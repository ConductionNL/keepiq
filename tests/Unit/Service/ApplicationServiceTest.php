<?php

/**
 * Unit tests for ApplicationService.
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
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\ApplicationMapper;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Service\ApplicationService;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ApplicationService.
 */
class ApplicationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var ApplicationService
     */
    private ApplicationService $service;

    /**
     * The mocked application mapper.
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $mapper;

    /**
     * The mocked encryption suite service.
     *
     * @var EncryptionSuiteService
     */
    private EncryptionSuiteService $suiteService;

    /**
     * The mocked encryption suite mapper.
     *
     * @var EncryptionSuiteMapper
     */
    private EncryptionSuiteMapper $suiteMapper;

    /**
     * The mocked group manager.
     *
     * @var IGroupManager
     */
    private IGroupManager $groupManager;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper       = $this->createMock(originalClassName: ApplicationMapper::class);
        $this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
        $this->suiteMapper  = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $logger             = $this->createMock(originalClassName: LoggerInterface::class);

        $this->service = new ApplicationService(
            mapper: $this->mapper,
            suiteService: $this->suiteService,
            suiteMapper: $this->suiteMapper,
            groupManager: $this->groupManager,
            logger: $logger
        );
    }

    /**
     * Admin registration without a CSR auto-approves and returns a private key.
     *
     * @return void
     */
    public function testRegisterAsAdminAutoApprovesAndReturnsPrivateKey(): void
    {
        $this->mapper->expects($this->once())->method('insert');
        $this->suiteService->expects($this->once())->method('createSuiteForApplication');

        $result = $this->service->register(
            name: 'Admin App',
            description: null,
            type: 'internal',
            csr: null,
            userId: 'admin',
            isAdmin: true
        );

        $this->assertSame('active', $result['application']->getStatus());
        $this->assertSame('admin', $result['application']->getApprovedBy());
        $this->assertIsString($result['privateKey']);
        $this->assertStringContainsString('PRIVATE KEY', $result['privateKey']);
    }

    /**
     * Non-admin registration enters the pending queue and notifies admins.
     *
     * @return void
     */
    public function testRegisterAsNonAdminIsPending(): void
    {
        $this->mapper->expects($this->once())->method('insert');
        $this->suiteService->expects($this->never())->method('createSuiteForApplication');
        $this->groupManager->method('get')->willReturn(null);

        $result = $this->service->register(
            name: 'User App',
            description: 'desc',
            type: 'external',
            csr: null,
            userId: 'bob',
            isAdmin: false
        );

        $this->assertSame('pending', $result['application']->getStatus());
        $this->assertNull($result['privateKey']);
    }

    /**
     * Anonymous registration is pending with a null registrant.
     *
     * @return void
     */
    public function testRegisterAnonymousIsPending(): void
    {
        $this->mapper->expects($this->once())->method('insert');
        $this->groupManager->method('get')->willReturn(null);

        $result = $this->service->register(
            name: 'Anon App',
            description: null,
            type: 'external',
            csr: null,
            userId: null,
            isAdmin: false
        );

        $this->assertSame('pending', $result['application']->getStatus());
        $this->assertNull($result['application']->getRegisteredBy());
    }

    /**
     * An empty name is rejected.
     *
     * @return void
     */
    public function testRegisterRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->register(
            name: '   ',
            description: null,
            type: 'external',
            csr: null,
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * An invalid type is rejected.
     *
     * @return void
     */
    public function testRegisterRejectsInvalidType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->register(
            name: 'App',
            description: null,
            type: 'bogus',
            csr: null,
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * A malformed CSR is rejected before any record is inserted.
     *
     * @return void
     */
    public function testRegisterRejectsMalformedCsr(): void
    {
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        $this->service->register(
            name: 'CSR App',
            description: null,
            type: 'external',
            csr: 'not-a-real-csr',
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * A CSR with a weak (sub-4096-bit) key is rejected.
     *
     * @return void
     */
    public function testRegisterRejectsWeakCsrKey(): void
    {
        $weakCsr = $this->makeCsr(2048);
        $this->mapper->expects($this->never())->method('insert');
        $this->expectException(InvalidArgumentException::class);
        $this->service->register(
            name: 'Weak App',
            description: null,
            type: 'external',
            csr: $weakCsr,
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * Admin registration with a valid CSR provisions a suite and returns no key.
     *
     * @return void
     */
    public function testRegisterAdminWithValidCsrReturnsNoPrivateKey(): void
    {
        $csr = $this->makeCsr(4096);
        $this->mapper->expects($this->once())->method('insert');
        $this->suiteService->expects($this->once())->method('createSuiteForApplication');

        $result = $this->service->register(
            name: 'CSR App',
            description: null,
            type: 'external',
            csr: $csr,
            userId: 'admin',
            isAdmin: true
        );

        $this->assertSame('active', $result['application']->getStatus());
        $this->assertNull($result['privateKey']);
    }

    /**
     * Approving a pending application without a CSR provisions a suite and key.
     *
     * @return void
     */
    public function testApproveWithoutCsr(): void
    {
        $pending = $this->makeApplication(['status' => 'pending']);
        $this->mapper->method('findById')->willReturn($pending);
        $this->suiteService->expects($this->once())->method('createSuiteForApplication');
        $this->mapper->expects($this->once())->method('update');

        $result = $this->service->approve(applicationId: $pending->getId(), adminUserId: 'admin');

        $this->assertSame('active', $result['application']->getStatus());
        $this->assertSame('admin', $result['application']->getApprovedBy());
        $this->assertIsString($result['privateKey']);
    }

    /**
     * Approving a non-pending application is rejected.
     *
     * @return void
     */
    public function testApproveRejectsNonPending(): void
    {
        $active = $this->makeApplication(['status' => 'active']);
        $this->mapper->method('findById')->willReturn($active);

        $this->expectException(InvalidArgumentException::class);
        $this->service->approve(applicationId: $active->getId(), adminUserId: 'admin');
    }

    /**
     * Rejecting a pending application hard-deletes it.
     *
     * @return void
     */
    public function testRejectHardDeletes(): void
    {
        $pending = $this->makeApplication(['status' => 'pending']);
        $this->mapper->method('findById')->willReturn($pending);
        $this->mapper->expects($this->once())->method('delete')->with($pending);

        $this->service->reject(applicationId: $pending->getId(), adminUserId: 'admin');
    }

    /**
     * Deleting an application cascades to its encryption suites.
     *
     * @return void
     */
    public function testDeleteCascadesSuites(): void
    {
        $app   = $this->makeApplication(['status' => 'active']);
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');

        $this->mapper->method('findById')->willReturn($app);
        $this->suiteMapper->method('findByOwner')->willReturn([$suite]);
        $this->suiteMapper->expects($this->once())->method('delete')->with($suite);
        $this->mapper->expects($this->once())->method('delete')->with($app);

        $this->service->delete($app->getId());
    }

    /**
     * Deleting a missing application throws.
     *
     * @return void
     */
    public function testDeleteMissingThrows(): void
    {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->expectException(RuntimeException::class);
        $this->service->delete('missing');
    }

    /**
     * A non-admin cannot read another user's pending application.
     *
     * @return void
     */
    public function testGetDeniesForeignPending(): void
    {
        $app = $this->makeApplication(['status' => 'pending', 'registeredBy' => 'alice']);
        $this->mapper->method('findById')->willReturn($app);

        $this->expectException(InvalidArgumentException::class);
        $this->service->get(applicationId: $app->getId(), userId: 'bob', isAdmin: false);
    }

    /**
     * A non-admin can read an active application (to write secrets).
     *
     * @return void
     */
    public function testGetAllowsActiveForNonAdmin(): void
    {
        $app = $this->makeApplication(['status' => 'active', 'registeredBy' => 'alice']);
        $this->mapper->method('findById')->willReturn($app);

        $result = $this->service->get(applicationId: $app->getId(), userId: 'bob', isAdmin: false);
        $this->assertSame($app->getId(), $result->getId());
    }

    /**
     * Admin list returns all applications with a total count.
     *
     * @return void
     */
    public function testListAsAdminReturnsAll(): void
    {
        $apps = [$this->makeApplication(['status' => 'active']), $this->makeApplication(['status' => 'pending'])];
        $this->mapper->method('findAll')->willReturn($apps);
        $this->mapper->method('countAll')->willReturn(2);

        $result = $this->service->list(userId: 'admin', isAdmin: true);
        $this->assertCount(2, $result['results']);
        $this->assertSame(2, $result['total']);
    }

    /**
     * Build an Application entity with sensible defaults for tests.
     *
     * @param array<string,mixed> $overrides Field overrides
     *
     * @return Application
     */
    private function makeApplication(array $overrides=[]): Application
    {
        $app = new Application();
        $app->setId($overrides['id'] ?? 'app-1');
        $app->setName($overrides['name'] ?? 'Test App');
        $app->setType($overrides['type'] ?? 'external');
        $app->setStatus($overrides['status'] ?? 'pending');
        $app->setRegisteredBy($overrides['registeredBy'] ?? null);
        if (isset($overrides['csr']) === true) {
            $app->setCsr($overrides['csr']);
        }

        return $app;
    }

    /**
     * Generate a PKCS#10 CSR PEM with the given key size.
     *
     * @param int $bits The RSA key size
     *
     * @return string The CSR PEM
     */
    private function makeCsr(int $bits): string
    {
        $key = openssl_pkey_new(
            [
                'private_key_bits' => $bits,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );
        $csr = openssl_csr_new(['commonName' => 'test'], $key, ['digest_alg' => 'sha256']);
        openssl_csr_export($csr, $csrPem);

        return $csrPem;
    }
}
