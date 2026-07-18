<?php

/**
 * Unit tests for CertificateAuthorityService.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\CACertificate;
use OCA\Doriath\Db\CACertificateMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for CertificateAuthorityService.
 */
class CertificateAuthorityServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var CertificateAuthorityService
     */
    private CertificateAuthorityService $service;

    /**
     * The mocked CA certificate mapper.
     *
     * @var CACertificateMapper
     */
    private CACertificateMapper $caCertMapper;

    /**
     * The mocked encryption suite mapper.
     *
     * @var EncryptionSuiteMapper
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * The mocked app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * The mocked crypto service.
     *
     * @var ICrypto
     */
    private ICrypto $crypto;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->caCertMapper = $this->createMock(originalClassName: CACertificateMapper::class);
        $this->suiteMapper  = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->appConfig    = $this->createMock(originalClassName: IAppConfig::class);
        $this->crypto       = $this->createMock(originalClassName: ICrypto::class);
        $logger = $this->createMock(originalClassName: LoggerInterface::class);

        $this->crypto->method('encrypt')->willReturnCallback(fn ($v) => 'enc:'.$v);
        $this->crypto->method('decrypt')->willReturnCallback(fn ($v) => substr($v, 4));

        $this->service = new CertificateAuthorityService(
            caCertificateMapper: $this->caCertMapper,
            suiteMapper: $this->suiteMapper,
            appConfig: $this->appConfig,
            crypto: $this->crypto,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that bootstrap skips when a CA already exists (root + active intermediate).
     *
     * @return void
     */
    public function testBootstrapSkipsIfCaExists(): void
    {
        $root         = new CACertificate();
        $intermediate = new CACertificate();
        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // Should not insert anything.
        $this->caCertMapper->expects($this->never())->method('insert');

        // Healthy state should re-assert ca_status=healthy.
        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('doriath', 'ca_status', 'healthy');

        $this->service->bootstrap();
    }//end testBootstrapSkipsIfCaExists()

    /**
     * Test that bootstrap recovers the intermediate when root exists but no
     * active intermediate does (partial-state recovery — see issue #41).
     *
     * @return void
     */
    public function testBootstrapRecoversIntermediateWhenMissing(): void
    {
        // Build a real root key + cert (2048-bit for test speed) so recoverIntermediate
        // can decrypt and re-use the persisted root to sign a new intermediate.
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setType('root');
        $root->setCertificate($rootCertPem);
        // Crypto mock returns 'enc:'.$plain on encrypt and strips the prefix on decrypt.
        $root->setPrivateKey('enc:'.$rootKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')
            ->willThrowException(new DoesNotExistException('No intermediate'));

        // Recovery path: exactly one insert (the new intermediate), no root recreation.
        $this->caCertMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                function (CACertificate $entity): CACertificate {
                    $this->assertEquals(expected: 'intermediate', actual: $entity->getType());
                    $this->assertTrue(condition: $entity->getIsActive());
                    return $entity;
                }
            );

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('doriath', 'ca_status', 'healthy');

        $this->service->bootstrap();
    }//end testBootstrapRecoversIntermediateWhenMissing()

    /**
     * Test that getStatus returns not_configured when CA is degraded.
     *
     * @return void
     */
    public function testGetStatusReturnsNotConfiguredWhenDegraded(): void
    {
        $this->appConfig->method('getValueString')->willReturn('degraded');

        $status = $this->service->getStatus();

        $this->assertEquals(expected: 'not_configured', actual: $status['status']);
        $this->assertNull(actual: $status['root']);
    }//end testGetStatusReturnsNotConfiguredWhenDegraded()

    /**
     * Test that getStatus returns healthy when certificates are valid.
     *
     * @return void
     */
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

        $this->assertEquals(expected: 'healthy', actual: $status['status']);
    }//end testGetStatusReturnsHealthy()

    /**
     * Test that getStatus returns expiring_soon when intermediate expires within 30 days.
     *
     * @return void
     */
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

        $this->assertEquals(expected: 'expiring_soon', actual: $status['status']);
    }//end testGetStatusReturnsExpiringSoon()

    /**
     * Test that getStatus returns not_configured when no CA is found.
     *
     * @return void
     */
    public function testGetStatusReturnsNotConfiguredWhenNoCa(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');
        $this->caCertMapper->method('findRoot')
            ->willThrowException(new DoesNotExistException('No root'));

        $status = $this->service->getStatus();

        $this->assertEquals(expected: 'not_configured', actual: $status['status']);
        $this->assertNull(actual: $status['root']);
        $this->assertNull(actual: $status['intermediate']);
    }//end testGetStatusReturnsNotConfiguredWhenNoCa()

    /**
     * Test that getStatus returns action_required when root is expiring within 90 days.
     *
     * @return void
     */
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

        $this->assertEquals(expected: 'action_required', actual: $status['status']);
    }//end testGetStatusReturnsActionRequiredWhenRootExpiringSoon()

    /**
     * Test that getStatus returns action_required when intermediate is revoked.
     *
     * @return void
     */
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

        $this->assertEquals(expected: 'action_required', actual: $status['status']);
    }//end testGetStatusReturnsActionRequiredWhenIntermediateRevoked()

    /**
     * Test that bootstrap creates root and intermediate certificates.
     *
     * @return void
     */
    public function testBootstrapCreatesRootAndIntermediate(): void
    {
        $this->caCertMapper->method('findRoot')
            ->willThrowException(new DoesNotExistException('No root'));

        // Expect exactly 2 inserts: root + intermediate.
        $this->caCertMapper->expects($this->exactly(count: 2))->method('insert');

        $this->appConfig->expects($this->once())
            ->method('setValueString')
            ->with('doriath', 'ca_status', 'healthy');

        $this->service->bootstrap();
    }//end testBootstrapCreatesRootAndIntermediate()

    /**
     * Test that retryBootstrap delegates to bootstrap.
     *
     * @return void
     */
    public function testRetryBootstrapDelegatesToBootstrap(): void
    {
        // If root + active intermediate already exist, bootstrap is a no-op (idempotent).
        $root         = new CACertificate();
        $intermediate = new CACertificate();
        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);
        $this->caCertMapper->expects($this->never())->method('insert');

        $this->service->retryBootstrap();
    }//end testRetryBootstrapDelegatesToBootstrap()

    /**
     * Test renewIntermediate without force flag does not revoke old intermediate.
     *
     * @return void
     */
    public function testRenewIntermediateNotForced(): void
    {
        // Set up root.
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:'.$rootKeyPem);

        // Set up old intermediate.
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);

        // FindActiveIntermediate returns old intermediate on every call.
        $callCount = 0;
        $this->caCertMapper->method('findActiveIntermediate')
            ->willReturnCallback(
                function () use ($oldIntermediate, &$callCount) {
                    $callCount++;
                    return $oldIntermediate;
                }
            );

        // Insert for the new intermediate.
        $this->caCertMapper->expects($this->once())->method('insert');
        // Update for old intermediate deactivation.
        $this->caCertMapper->expects($this->once())->method('update');

        // No active suites to re-sign.
        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewIntermediate(forced: false);

        // No suites to re-sign.
        $this->assertEquals(expected: 0, actual: $count);
        // Not forced, so revokedAt should NOT be set.
        $this->assertNull(actual: $oldIntermediate->getRevokedAt());
        $this->assertFalse(condition: $oldIntermediate->getIsActive());
    }//end testRenewIntermediateNotForced()

    /**
     * Test renewIntermediate with force flag revokes the old intermediate.
     *
     * @return void
     */
    public function testRenewIntermediateForced(): void
    {
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:'.$rootKeyPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);
        $this->caCertMapper->expects($this->once())->method('insert');
        $this->caCertMapper->expects($this->once())->method('update');
        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewIntermediate(forced: true);

        $this->assertEquals(expected: 0, actual: $count);
        // Forced: revokedAt should be set.
        $this->assertNotNull(actual: $oldIntermediate->getRevokedAt());
    }//end testRenewIntermediateForced()

    /**
     * Re-signing a suite MUST preserve its certificate's public key.
     *
     * Regression for the read-after-write decrypt failure: re-signing existed to
     * chain a suite to a renewed intermediate, but it fed a public-only key to
     * openssl_csr_new(), which silently mints a throwaway key pair. The issued
     * certificate then carried a public key whose private half nobody holds, so
     * any value the browser encrypted under the new certificate could not be
     * decrypted with the user's wrapped private key. The fix keeps the existing
     * certificate whenever it cannot re-sign while preserving the public key, so
     * the suite's public key is invariant across renew.
     *
     * @return void
     */
    public function testRenewIntermediatePreservesSuitePublicKey(): void
    {
        // Build a real root key + cert (2048-bit for speed).
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:'.$rootKeyPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:'.$intKeyPem);

        // A real user suite signed by the old intermediate.
        $userKey  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $userCsr  = openssl_csr_new(['commonName' => 'User 1'], $userKey);
        $userCert = openssl_csr_sign($userCsr, $intCertPem, $intKey, 365);
        openssl_x509_export($userCert, $userCertPem);

        $originalModulus = openssl_pkey_get_details(openssl_pkey_get_public($userCertPem))['rsa']['n'];

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('user1');
        $suite->setStatus('active');
        $suite->setCertificate($userCertPem);

        $this->caCertMapper->method('findRoot')->willReturn($root);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);
        $this->caCertMapper->expects($this->once())->method('insert');
        $this->caCertMapper->expects($this->once())->method('update');

        $this->suiteMapper->method('findAllActiveWithLimit')
            ->willReturnOnConsecutiveCalls([$suite], []);

        $this->service->renewIntermediate(forced: false);

        // Whatever certificate the suite ends up with, its public key (modulus)
        // MUST be the original — never a throwaway pair the server can't match.
        $resultModulus = openssl_pkey_get_details(
            openssl_pkey_get_public($suite->getCertificate())
        )['rsa']['n'];
        $this->assertSame(
            expected: $originalModulus,
            actual: $resultModulus,
            message: 'Re-signing must preserve the suite certificate public key',
        );
        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $suite->getCertificate());
    }//end testRenewIntermediatePreservesSuitePublicKey()

    /**
     * REGRESSION (found in live verification 2026-07-18): signPublicKey with a
     * PUBLIC-ONLY key must issue a certificate carrying exactly the submitted
     * key. On OpenSSL builds where openssl_csr_new() silently mints a throwaway
     * keypair for a public-only key, the first-run suite-creation path
     * (EncryptionSuiteService::createSuite → signPublicKey without a private
     * key) issued a certificate whose private half nobody holds — every secret
     * the user then stored or received was undecryptable (silent vault data
     * loss). The fix guards the modulus and falls back to phpseclib issuance.
     *
     * @return void
     */
    public function testSignPublicKeyPreservesSubmittedPublicKey(): void
    {
        // Build a real root + intermediate (2048-bit for speed).
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setIsActive(true);
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // A browser-style keypair: the private key NEVER reaches the server.
        $browserKey = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $browserPublicPem = openssl_pkey_get_details($browserKey)['key'];
        $submittedModulus = openssl_pkey_get_details(
            openssl_pkey_get_public($browserPublicPem)
        )['rsa']['n'];

        $certPem = $this->service->signPublicKey(publicKeyPem: $browserPublicPem, commonName: 'user1');

        $issuedModulus = openssl_pkey_get_details(
            openssl_pkey_get_public($certPem)
        )['rsa']['n'];
        $this->assertSame(
            expected: $submittedModulus,
            actual: $issuedModulus,
            message: 'Issued certificate must carry the submitted public key — never a throwaway pair',
        );
        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $certPem);
    }//end testSignPublicKeyPreservesSubmittedPublicKey()

    /**
     * Test that renewIntermediate re-signs all active suites.
     *
     * @return void
     */
    public function testRenewIntermediateResignsSuites(): void
    {
        // Build a real root key + cert (2048-bit for speed).
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $root = new CACertificate();
        $root->setId('root-1');
        $root->setCertificate($rootCertPem);
        $root->setPrivateKey('enc:'.$rootKeyPem);

        // Build a real old intermediate key + cert signed by root.
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, $rootCertPem, $rootKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($intCertPem);
        $oldIntermediate->setPrivateKey('enc:'.$intKeyPem);

        // Build 2 user keys and sign their certs with the old intermediate.
        $userKey1  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $userCsr1  = openssl_csr_new(['commonName' => 'User 1'], $userKey1);
        $userCert1 = openssl_csr_sign($userCsr1, $intCertPem, $intKey, 365);
        openssl_x509_export($userCert1, $userCertPem1);

        $userKey2  = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $userCsr2  = openssl_csr_new(['commonName' => 'User 2'], $userKey2);
        $userCert2 = openssl_csr_sign($userCsr2, $intCertPem, $intKey, 365);
        openssl_x509_export($userCert2, $userCertPem2);

        $suite1 = new EncryptionSuite();
        $suite1->setId('suite-1');
        $suite1->setOwnerType('user');
        $suite1->setOwnerId('user1');
        $suite1->setStatus('active');
        $suite1->setCertificate($userCertPem1);

        $suite2 = new EncryptionSuite();
        $suite2->setId('suite-2');
        $suite2->setOwnerType('user');
        $suite2->setOwnerId('user2');
        $suite2->setStatus('active');
        $suite2->setCertificate($userCertPem2);

        $this->caCertMapper->method('findRoot')->willReturn($root);

        // FindActiveIntermediate returns oldIntermediate for all calls.
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);

        $this->caCertMapper->expects($this->once())->method('insert');
        $this->caCertMapper->expects($this->once())->method('update');

        // FindAllActiveWithLimit: first call returns 2 suites, second returns empty (end of batch loop).
        $this->suiteMapper->method('findAllActiveWithLimit')
            ->willReturnOnConsecutiveCalls([$suite1, $suite2], []);

        $modulus1 = openssl_pkey_get_details(openssl_pkey_get_public($userCertPem1))['rsa']['n'];
        $modulus2 = openssl_pkey_get_details(openssl_pkey_get_public($userCertPem2))['rsa']['n'];

        // The phpseclib re-sign carries each suite's ORIGINAL public key into a
        // freshly-signed certificate, so both suites are updated. (The old
        // CSR-based path could never do this and silently kept old certs.)
        $this->suiteMapper->expects($this->exactly(2))->method('update');

        $count = $this->service->renewIntermediate(forced: false);

        $this->assertEquals(expected: 2, actual: $count);

        // Each suite keeps a valid certificate carrying its ORIGINAL public key.
        $this->assertNotNull(actual: $suite1->getCertificate());
        $this->assertNotNull(actual: $suite2->getCertificate());
        $this->assertSame(
            expected: $modulus1,
            actual: openssl_pkey_get_details(openssl_pkey_get_public($suite1->getCertificate()))['rsa']['n'],
        );
        $this->assertSame(
            expected: $modulus2,
            actual: openssl_pkey_get_details(openssl_pkey_get_public($suite2->getCertificate()))['rsa']['n'],
        );
        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $suite1->getCertificate());
        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $suite2->getCertificate());
    }//end testRenewIntermediateResignsSuites()

    /**
     * Test that renewRoot creates new root and intermediate, and re-signs suites.
     *
     * @return void
     */
    public function testRenewRoot(): void
    {
        $rootKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rootKey, $rootKeyPem);
        $rootCsr  = openssl_csr_new(['commonName' => 'Root CA'], $rootKey);
        $rootCert = openssl_csr_sign($rootCsr, null, $rootKey, 365);
        openssl_x509_export($rootCert, $rootCertPem);

        $oldRoot = new CACertificate();
        $oldRoot->setId('root-old');
        $oldRoot->setCertificate($rootCertPem);
        $oldRoot->setPrivateKey('enc:'.$rootKeyPem);

        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);

        $oldIntermediate = new CACertificate();
        $oldIntermediate->setId('int-old');
        $oldIntermediate->setIsActive(true);
        $oldIntermediate->setCertificate($rootCertPem);
        $oldIntermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findRoot')->willReturn($oldRoot);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($oldIntermediate);

        // Insert: new root + new intermediate = 2.
        $this->caCertMapper->expects($this->exactly(count: 2))->method('insert');
        // Update: old root (successor) + old intermediate (deactivate) + old intermediate (successor link) = 3.
        $this->caCertMapper->expects($this->exactly(count: 3))->method('update');

        $this->suiteMapper->method('findAllActiveWithLimit')->willReturn([]);

        $count = $this->service->renewRoot();

        $this->assertEquals(expected: 0, actual: $count);
        $this->assertNotNull(actual: $oldRoot->getSuccessorId());
    }//end testRenewRoot()

    /**
     * Test that signPublicKey returns a valid certificate PEM.
     *
     * @return void
     */
    public function testSignPublicKey(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // Generate a user key pair to sign.
        $userKey      = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details      = openssl_pkey_get_details($userKey);
        $publicKeyPem = $details['key'];

        // The service triggers an OpenSSL warning when passing a public key to openssl_csr_new
        // (pre-existing design: signPublicKey wraps a public-key-only CSR). Suppress in test.
        set_error_handler(static fn () => true, E_WARNING);
        try {
            $certPem = $this->service->signPublicKey($publicKeyPem);
        } finally {
            restore_error_handler();
        }

        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $certPem);
    }//end testSignPublicKey()

    /**
     * Regression lock for the signPublicKey keypair fix (Phase-0).
     *
     * When the matching private key is supplied (server-side key generation),
     * the issued certificate MUST carry the caller's ACTUAL public key. The
     * previous bug fed a public-only key to openssl_csr_new(), which silently
     * generated a throwaway keypair — producing a certificate whose private key
     * nobody held, breaking the whole encrypt/decrypt model. This test signs a
     * known keypair and asserts the certificate's embedded public key is byte-for-byte
     * the user's real public key, not a throwaway.
     *
     * @return void
     */
    public function testSignPublicKeyWithPrivateKeyEmbedsRealPublicKey(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // The user's real keypair (server-side generation supplies both halves).
        $userKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($userKey, $userPrivateKeyPem);
        $userDetails      = openssl_pkey_get_details($userKey);
        $userPublicKeyPem = $userDetails['key'];

        $certPem = $this->service->signPublicKey(
            publicKeyPem: $userPublicKeyPem,
            commonName: 'Real User',
            privateKeyPem: $userPrivateKeyPem,
        );

        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $certPem);

        // The certificate's embedded public key MUST equal the user's real public
        // key. A throwaway-keypair regression would make these differ.
        $certPublicKey    = openssl_pkey_get_public(openssl_x509_read($certPem));
        $certPublicKeyPem = openssl_pkey_get_details($certPublicKey)['key'];

        $this->assertSame(
            expected: $userPublicKeyPem,
            actual: $certPublicKeyPem,
            message: 'signed certificate must carry the user\'s real public key, not a throwaway'
        );

        // And the user's private key must mathematically match the cert (a
        // throwaway public key would fail this check too).
        $this->assertTrue(
            condition: openssl_x509_check_private_key($certPem, $userPrivateKeyPem),
            message: 'the user\'s private key must match the issued certificate'
        );
    }//end testSignPublicKeyWithPrivateKeyEmbedsRealPublicKey()

    /**
     * Regression lock: an invalid private key PEM passed to signPublicKey is
     * rejected with a typed InvalidArgumentException (never silently falls back
     * to generating a throwaway keypair).
     *
     * @return void
     */
    public function testSignPublicKeyWithInvalidPrivateKeyThrows(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $userKey          = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $userPublicKeyPem = openssl_pkey_get_details($userKey)['key'];

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid private key PEM');
        $this->service->signPublicKey(
            publicKeyPem: $userPublicKeyPem,
            commonName: 'Real User',
            privateKeyPem: 'not-a-valid-private-key',
        );
    }//end testSignPublicKeyWithInvalidPrivateKeyThrows()

    /**
     * Test that signCsr returns a valid certificate PEM.
     *
     * @return void
     */
    public function testSignCsr(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setId('int-1');
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        // Generate a CSR.
        $userKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr     = openssl_csr_new(['commonName' => 'Test User'], $userKey);
        openssl_csr_export($csr, $csrPem);

        $certPem = $this->service->signCsr($csrPem);

        $this->assertStringContainsString(needle: 'BEGIN CERTIFICATE', haystack: $certPem);
    }//end testSignCsr()

    /**
     * Test that signPublicKey throws on an invalid PEM string.
     *
     * @return void
     */
    public function testSignPublicKeyWithInvalidPem(): void
    {
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($intKey, $intKeyPem);
        $intCsr  = openssl_csr_new(['commonName' => 'Int CA'], $intKey);
        $intCert = openssl_csr_sign($intCsr, null, $intKey, 365);
        openssl_x509_export($intCert, $intCertPem);

        $intermediate = new CACertificate();
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);

        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessage(message: 'Invalid public key PEM');
        $this->service->signPublicKey('not-a-valid-pem');
    }//end testSignPublicKeyWithInvalidPem()

    /**
     * Regression lock (certificate-lifecycle §2.4 live-verify catch):
     * reissueSuiteCertificate MUST actually mint a NEW certificate that
     * carries the suite's ORIGINAL public key. The old CSR-based
     * implementation could never do this — openssl_csr_new silently
     * generates a throwaway key pair for public-only keys, so its
     * modulus guard rejected every result and the re-sign was a
     * permanent no-op. Real crypto end to end, no mocks on the seam.
     *
     * @return void
     */
    public function testReissueSuiteCertificatePreservesPublicKey(): void
    {
        // Real intermediate: self-signed CA cert with its private key.
        $intKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $intCsr = openssl_csr_new(['commonName' => 'Test Intermediate CA'], $intKey, ['digest_alg' => 'sha256']);
        $intX509 = openssl_csr_sign($intCsr, null, $intKey, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export(certificate: $intX509, output: $intCertPem);
        openssl_pkey_export(key: $intKey, output: $intKeyPem);

        // Real suite: its own key pair and certificate.
        $suiteKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $suiteCsr = openssl_csr_new(['commonName' => 'alice@test'], $suiteKey, ['digest_alg' => 'sha256']);
        $suiteX509 = openssl_csr_sign($suiteCsr, $intX509, $intKey, 365, ['digest_alg' => 'sha256']);
        openssl_x509_export(certificate: $suiteX509, output: $oldSuiteCertPem);

        $intermediate = new CACertificate();
        $intermediate->setCertificate($intCertPem);
        $intermediate->setPrivateKey('enc:'.$intKeyPem);
        $this->caCertMapper->method('findActiveIntermediate')->willReturn($intermediate);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setCertificate($oldSuiteCertPem);
        $this->suiteMapper->expects($this->once())->method('update');

        $result = $this->service->reissueSuiteCertificate(suite: $suite);

        $this->assertTrue($result, 're-issue must succeed with a real intermediate');
        $newCertPem = $suite->getCertificate();
        $this->assertNotSame($oldSuiteCertPem, $newCertPem, 'a NEW certificate must be minted');

        $oldModulus = openssl_pkey_get_details(openssl_pkey_get_public($oldSuiteCertPem))['rsa']['n'];
        $newModulus = openssl_pkey_get_details(openssl_pkey_get_public($newCertPem))['rsa']['n'];
        $this->assertTrue(hash_equals($oldModulus, $newModulus), 'the ORIGINAL public key must be preserved');

        $parsed = openssl_x509_parse($newCertPem);
        $this->assertSame('alice@test', $parsed['subject']['CN'], 'the subject CN must be preserved');
        $this->assertSame(1, openssl_x509_verify($newCertPem, $intCertPem), 'the new cert must chain to the intermediate');
    }//end testReissueSuiteCertificatePreservesPublicKey()
}//end class
