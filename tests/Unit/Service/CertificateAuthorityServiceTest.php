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
}
