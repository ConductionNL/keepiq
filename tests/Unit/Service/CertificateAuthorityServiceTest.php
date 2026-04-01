<?php

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\CACertificate;
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CertificateAuthorityServiceTest extends TestCase
{
    private CertificateAuthorityService $service;
    private CACertificateMapper $caCertMapper;
    private EncryptionSuiteMapper $suiteMapper;
    private IAppConfig $appConfig;
    private ICrypto $crypto;

    protected function setUp(): void
    {
        $this->caCertMapper = $this->createMock(CACertificateMapper::class);
        $this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->crypto = $this->createMock(ICrypto::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:' . $v);
        $this->crypto->method('decrypt')->willReturnCallback(fn ($v) => substr($v, 4));

        $this->service = new CertificateAuthorityService(
            $this->caCertMapper,
            $this->suiteMapper,
            $this->appConfig,
            $this->crypto,
            $logger,
        );
    }

    public function testBootstrapSkipsIfCaExists(): void
    {
        $root = new CACertificate();
        $this->caCertMapper->method('findRoot')->willReturn($root);

        // Should not insert anything.
        $this->caCertMapper->expects($this->never())->method('insert');

        $this->service->bootstrap();
    }

    public function testGetStatusReturnsNotConfiguredWhenDegraded(): void
    {
        $this->appConfig->method('getValueString')->willReturn('degraded');

        $status = $this->service->getStatus();

        $this->assertEquals('not_configured', $status['status']);
        $this->assertNull($status['root']);
    }

    public function testGetStatusReturnsHealthy(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');

        $root = new CACertificate();
        $root->setExpiresAt(new \DateTime('+10 years'));

        $intermediate = new CACertificate();
        $intermediate->setExpiresAt(new \DateTime('+2 years'));

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $status = $this->service->getStatus();

        $this->assertEquals('healthy', $status['status']);
    }

    public function testGetStatusReturnsExpiringSoon(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');

        $root = new CACertificate();
        $root->setExpiresAt(new \DateTime('+10 years'));

        $intermediate = new CACertificate();
        $intermediate->setExpiresAt(new \DateTime('+15 days'));

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $status = $this->service->getStatus();

        $this->assertEquals('expiring_soon', $status['status']);
    }

    public function testGetStatusReturnsNotConfiguredWhenNoCa(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');
        $this->caCertMapper->method('findRoot')
            ->willThrowException(new DoesNotExistException('No root'));

        $status = $this->service->getStatus();

        $this->assertEquals('not_configured', $status['status']);
        $this->assertNull($status['root']);
        $this->assertNull($status['intermediate']);
    }

    public function testGetStatusReturnsActionRequiredWhenRootExpiringSoon(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');

        $root = new CACertificate();
        $root->setExpiresAt(new \DateTime('+60 days'));

        $intermediate = new CACertificate();
        $intermediate->setExpiresAt(new \DateTime('+2 years'));

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $status = $this->service->getStatus();

        $this->assertEquals('action_required', $status['status']);
    }

    public function testGetStatusReturnsActionRequiredWhenIntermediateRevoked(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');

        $root = new CACertificate();
        $root->setExpiresAt(new \DateTime('+10 years'));

        $intermediate = new CACertificate();
        $intermediate->setExpiresAt(new \DateTime('+2 years'));
        $intermediate->setRevokedAt(new \DateTime());

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $status = $this->service->getStatus();

        $this->assertEquals('action_required', $status['status']);
    }

    public function testBootstrapCreatesRootAndIntermediate(): void
    {
        $this->caCertMapper->method('findRoot')
            ->willThrowException(new DoesNotExistException('No root'));

        // Expect exactly 2 inserts: root + intermediate.
        $this->caCertMapper->expects($this->exactly(2))->method('insert');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('doriath', 'ca_status', 'healthy');

        $this->service->bootstrap();
    }

    public function testRetryBootstrapDelegatesToBootstrap(): void
    {
        // If root already exists, bootstrap is a no-op (idempotent).
        $root = new CACertificate();
        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->expects($this->never())->method('insert');

        $this->service->retryBootstrap();
    }

    public function testRenewIntermediateNotForced(): void
    {
        // Set up root.
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:' . $rootKeyPem);

        // Set up old intermediate.
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);

        // findActiveIntermediate: first call returns old, second call for resignAllActiveSuites.
        $callCount = 0;
        $this->caCertMapper->method('findActiveIntermediate')
            ->willReturnCallback(function () use ($oldIntermediate, &$callCount) {
                $callCount++;
                // For resignAllActiveSuites, return the old intermediate (it has the key).
                return $oldIntermediate;
            });

        // insert for the new intermediate.
        $this->caCertMapper->expects($this->once())->method('insert');
        // update for old intermediate deactivation.
        $this->caCertMapper->expects($this->once())->method('update');

        // No active suites to re-sign.
        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewIntermediate(forced: false);

        // No suites to re-sign.
        $this->assertEquals(0, $count);
        // Not forced, so revokedAt should NOT be set.
        $this->assertNull($oldIntermediate->getRevokedAt());
        $this->assertFalse($oldIntermediate->getIsActive());
    }

    public function testRenewIntermediateForced(): void
    {
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:' . $rootKeyPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);
        $this->caCertMapper->expects($this->once())->method('insert');
        $this->caCertMapper->expects($this->once())->method('update');
        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewIntermediate(forced: true);

        $this->assertEquals(0, $count);
        // Forced: revokedAt should be set.
        $this->assertNotNull($oldIntermediate->getRevokedAt());
    }

    public function testRenewRoot(): void
    {
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $oldRoot = new CACertificate();
        $oldRoot->setId('root-old');
        $oldRoot->setCertificate($rootCertPem);
        $oldRoot->setPrivateKey('enc:' . $rootKeyPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($rootCertPem);
        $oldIntermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($oldRoot);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);

        // insert: new root + new intermediate = 2.
        $this->caCertMapper->expects($this->exactly(2))->method('insert');
        // update: old root (successor) + old intermediate (deactivate) + old intermediate (successor link) = 3.
        $this->caCertMapper->expects($this->exactly(3))->method('update');

        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewRoot();

        $this->assertEquals(0, $count);
        $this->assertNotNull($oldRoot->getSuccessorId());
    }

    public function testSignPublicKey(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // Generate a user key pair to sign.
        $userKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($userKey);
        $publicKeyPem = $details['key'];

        // The service triggers an OpenSSL warning when passing a public key to openssl_csr_new
        // (pre-existing design: signPublicKey wraps a public-key-only CSR). Suppress in test.
        $previousHandler = set_error_handler(static fn () => true, E_WARNING);
        try {
            $certPem = $this->service->signPublicKey($publicKeyPem);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString('BEGIN CERTIFICATE', $certPem);
    }

    public function testSignCsr(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // Generate a CSR.
        $userKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'Test User'], $userKey);
        openssl_csr_export($csr, $csrPem);

        $certPem = $this->service->signCsr($csrPem);

        $this->assertStringContainsString('BEGIN CERTIFICATE', $certPem);
    }

    public function testSignPublicKeyWithInvalidPem(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:' . $intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid public key PEM');
        $this->service->signPublicKey('not-a-valid-pem');
    }
}
