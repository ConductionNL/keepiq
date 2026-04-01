<?php

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EncryptionSuiteServiceTest extends TestCase
{
    private EncryptionSuiteService $service;
    private EncryptionSuiteMapper $mapper;
    private CertificateAuthorityService $caService;
    private IAppConfig $appConfig;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(EncryptionSuiteMapper::class);
        $this->caService = $this->createMock(CertificateAuthorityService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new EncryptionSuiteService(
            $this->mapper,
            $this->caService,
            $this->appConfig,
            $logger,
        );
    }

    public function testCreateSuiteSuccess(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');
        $this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
        $this->mapper->expects($this->once())->method('insert');

        $suite = $this->service->createSuite('user', 'testuser', 'pubkey-pem', 'encrypted-pk');

        $this->assertEquals('active', $suite->getStatus());
        $this->assertEquals('user', $suite->getOwnerType());
        $this->assertEquals('testuser', $suite->getOwnerId());
    }

    public function testCreateSuiteFailsWhenCaDegraded(): void
    {
        $this->appConfig->method('getValueString')->willReturn('degraded');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not healthy/');

        $this->service->createSuite('user', 'testuser', 'pubkey', 'pk');
    }

    public function testRevokeSuiteSuccess(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->mapper->method('findById')->willReturn($suite);
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->revokeSuite('suite-1', 'security concern', 'admin');

        $this->assertEquals('revoked', $result->getStatus());
        $this->assertEquals('admin', $result->getRevokedBy());
    }

    public function testRevokeCompromisedSuiteFails(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('compromised');

        $this->mapper->method('findById')->willReturn($suite);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->revokeSuite('suite-1', 'test', 'admin');
    }

    public function testReinstateSuiteSuccess(): void
    {
        // Generate a real key pair for the test.
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privPem);
        $details = openssl_pkey_get_details($keyPair);

        // Create a self-signed cert.
        $csr = openssl_csr_new(['commonName' => 'Test'], $keyPair);
        $cert = openssl_csr_sign($csr, null, $keyPair, 365);
        openssl_x509_export($cert, $certPem);

        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('revoked');
        $suite->setCertificate($certPem);
        $suite->setRevokedAt(new \DateTime());
        $suite->setRevokedReason('test');
        $suite->setRevokedBy('admin');

        $this->mapper->method('findById')->willReturn($suite);
        $this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->reinstateSuite('suite-1', 'admin');

        $this->assertEquals('active', $result->getStatus());
        $this->assertNotNull($result->getReinstatedBy());
        // Revocation audit fields should be preserved.
        $this->assertNotNull($result->getRevokedAt());
    }

    public function testReinstateCompromisedSuiteFails(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('compromised');

        $this->mapper->method('findById')->willReturn($suite);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->reinstateSuite('suite-1', 'admin');
    }
}
