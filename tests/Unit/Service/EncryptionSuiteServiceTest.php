<?php

/**
 * Unit tests for EncryptionSuiteService.
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\CertificateAuthorityService;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for EncryptionSuiteService.
 */
class EncryptionSuiteServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var EncryptionSuiteService
     */
    private EncryptionSuiteService $service;

    /**
     * The mocked suite mapper.
     *
     * @var EncryptionSuiteMapper
     */
    private EncryptionSuiteMapper $mapper;

    /**
     * The mocked CA service.
     *
     * @var CertificateAuthorityService
     */
    private CertificateAuthorityService $caService;

    /**
     * The mocked app config.
     *
     * @var IAppConfig
     */
    private IAppConfig $appConfig;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper    = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->caService = $this->createMock(originalClassName: CertificateAuthorityService::class);
        $this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
        $logger          = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new EncryptionSuiteService(
            mapper: $this->mapper,
            caService: $this->caService,
            appConfig: $this->appConfig,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test that createSuite succeeds when the CA is healthy.
     *
     * @return void
     */
    public function testCreateSuiteSuccess(): void
    {
        $this->appConfig->method('getValueString')->willReturn('healthy');
        $this->caService->method('signPublicKey')->willReturn('-----BEGIN CERTIFICATE-----...');
        $this->mapper->expects($this->once())->method('insert');

        $suite = $this->service->createSuite('user', 'testuser', 'pubkey-pem', 'encrypted-pk');

        $this->assertEquals(expected: 'active', actual: $suite->getStatus());
        $this->assertEquals(expected: 'user', actual: $suite->getOwnerType());
        $this->assertEquals(expected: 'testuser', actual: $suite->getOwnerId());
    }//end testCreateSuiteSuccess()

    /**
     * Test that createSuite throws when the CA is degraded.
     *
     * @return void
     */
    public function testCreateSuiteFailsWhenCaDegraded(): void
    {
        $this->appConfig->method('getValueString')->willReturn('degraded');

        $this->expectException(exception: RuntimeException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/not healthy/');

        $this->service->createSuite('user', 'testuser', 'pubkey', 'pk');
    }//end testCreateSuiteFailsWhenCaDegraded()

    /**
     * Test that revokeSuite marks the suite as revoked.
     *
     * @return void
     */
    public function testRevokeSuiteSuccess(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->mapper->method('findById')->willReturn($suite);
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->revokeSuite('suite-1', 'security concern', 'admin');

        $this->assertEquals(expected: 'revoked', actual: $result->getStatus());
        $this->assertEquals(expected: 'admin', actual: $result->getRevokedBy());
    }//end testRevokeSuiteSuccess()

    /**
     * Test that revoking a compromised suite throws.
     *
     * @return void
     */
    public function testRevokeCompromisedSuiteFails(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('compromised');

        $this->mapper->method('findById')->willReturn($suite);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->revokeSuite('suite-1', 'test', 'admin');
    }//end testRevokeCompromisedSuiteFails()

    /**
     * Test that reinstateSuite reinstates a revoked suite.
     *
     * @return void
     */
    public function testReinstateSuiteSuccess(): void
    {
        // Generate a real key pair for the test.
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privPem);

        // Create a self-signed cert.
        $csr  = openssl_csr_new(['commonName' => 'Test'], $keyPair);
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

        $this->assertEquals(expected: 'active', actual: $result->getStatus());
        $this->assertNotNull(actual: $result->getReinstatedBy());
        // Revocation audit fields should be preserved.
        $this->assertNotNull(actual: $result->getRevokedAt());
    }//end testReinstateSuiteSuccess()

    /**
     * Test that reinstating a compromised suite throws.
     *
     * @return void
     */
    public function testReinstateCompromisedSuiteFails(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('compromised');

        $this->mapper->method('findById')->willReturn($suite);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->reinstateSuite('suite-1', 'admin');
    }//end testReinstateCompromisedSuiteFails()

    /**
     * Test that reinstating an active suite throws.
     *
     * @return void
     */
    public function testReinstateActiveSuiteFails(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->mapper->method('findById')->willReturn($suite);

        $this->expectException(exception: InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(regularExpression: '/Only revoked/');
        $this->service->reinstateSuite('suite-1', 'admin');
    }//end testReinstateActiveSuiteFails()

    /**
     * Test that getSuite delegates to the mapper.
     *
     * @return void
     */
    public function testGetSuiteDelegatesToMapper(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');

        $this->mapper->method('findById')
            ->with('suite-1')
            ->willReturn($suite);

        $result = $this->service->getSuite('suite-1');

        $this->assertSame(expected: $suite, actual: $result);
    }//end testGetSuiteDelegatesToMapper()

    /**
     * Test that getActiveSuite delegates to the mapper.
     *
     * @return void
     */
    public function testGetActiveSuiteDelegatesToMapper(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->mapper->method('findActiveByOwner')
            ->with('user', 'testuser')
            ->willReturn($suite);

        $result = $this->service->getActiveSuite('user', 'testuser');

        $this->assertSame(expected: $suite, actual: $result);
    }//end testGetActiveSuiteDelegatesToMapper()

    /**
     * Test that getSuitesByOwner delegates to the mapper.
     *
     * @return void
     */
    public function testGetSuitesByOwnerDelegatesToMapper(): void
    {
        $suite1 = new EncryptionSuite();
        $suite1->setId('suite-1');
        $suite2 = new EncryptionSuite();
        $suite2->setId('suite-2');

        $this->mapper->method('findByOwner')
            ->with('user', 'testuser')
            ->willReturn([$suite1, $suite2]);

        $result = $this->service->getSuitesByOwner('user', 'testuser');

        $this->assertCount(expectedCount: 2, haystack: $result);
        $this->assertSame(expected: 'suite-1', actual: $result[0]->getId());
        $this->assertSame(expected: 'suite-2', actual: $result[1]->getId());
    }//end testGetSuitesByOwnerDelegatesToMapper()
}//end class
