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
use OCA\Doriath\Service\ApplicationService;
use OCA\Doriath\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ApplicationService.
 */
class ApplicationServiceTest extends TestCase
{
    /**
     * Service under test.
     *
     * @var ApplicationService
     */
    private ApplicationService $service;

    /**
     * Mock mapper.
     *
     * @var ApplicationMapper
     */
    private ApplicationMapper $mapper;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper  = $this->createMock(originalClassName: ApplicationMapper::class);
        $groupManager  = $this->createMock(originalClassName: IGroupManager::class);
        $logger        = $this->createMock(originalClassName: LoggerInterface::class);
        $this->service = new ApplicationService(
            mapper: $this->mapper,
            groupManager: $groupManager,
            logger: $logger
        );
    }

    /**
     * Test admin registration auto-approves and stamps approver fields.
     *
     * @return void
     */
    public function testRegisterAsAdminAutoApproves(): void
    {
        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (Application $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->register(
            name: 'OpenConnector',
            description: 'a connector',
            type: Application::TYPE_INTERNAL,
            csr: null,
            userId: 'admin',
            isAdmin: true
        );

        $this->assertSame($captured, $result);
        $this->assertSame(Application::STATUS_ACTIVE, $result->getStatus());
        $this->assertSame('admin', $result->getApprovedBy());
        $this->assertNotNull($result->getApprovedAt());
        $this->assertSame('OpenConnector', $result->getName());
        $this->assertSame(Application::TYPE_INTERNAL, $result->getType());
    }

    /**
     * Test non-admin registration creates a pending row.
     *
     * @return void
     */
    public function testRegisterAsUserCreatesPending(): void
    {
        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (Application $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->register(
            name: 'CI Bot',
            description: null,
            type: Application::TYPE_EXTERNAL,
            csr: '-----BEGIN CERTIFICATE REQUEST-----',
            userId: 'alice',
            isAdmin: false
        );

        $this->assertSame(Application::STATUS_PENDING, $result->getStatus());
        $this->assertNull($result->getApprovedBy());
        $this->assertNull($result->getApprovedAt());
        $this->assertSame('alice', $result->getRegisteredBy());
        $this->assertSame('-----BEGIN CERTIFICATE REQUEST-----', $result->getCsr());
    }

    /**
     * Test approve flips a pending app to active and updates timestamps.
     *
     * @return void
     */
    public function testApprovePendingApp(): void
    {
        $entity = new Application();
        $entity->setId('app-1');
        $entity->setName('CI Bot');
        $entity->setStatus(Application::STATUS_PENDING);

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('app-1')
            ->willReturn($entity);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->approve(applicationId: 'app-1', adminUserId: 'admin', isAdmin: true);

        $this->assertSame(Application::STATUS_ACTIVE, $result->getStatus());
        $this->assertSame('admin', $result->getApprovedBy());
        $this->assertNotNull($result->getApprovedAt());
    }

    /**
     * Test reject hard-deletes a pending app and refuses non-admin callers.
     *
     * @return void
     */
    public function testRejectRequiresAdminAndHardDeletes(): void
    {
        $entity = new Application();
        $entity->setId('app-1');
        $entity->setStatus(Application::STATUS_PENDING);

        $this->mapper->expects($this->once())
            ->method('findById')
            ->willReturn($entity);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($entity);

        $this->service->reject(applicationId: 'app-1', adminUserId: 'admin', isAdmin: true);

        // Non-admin caller must be refused without hitting the mapper a second time.
        $solo = $this->createMock(ApplicationMapper::class);
        $solo->expects($this->never())->method('delete');
        $svc = new ApplicationService(
            mapper: $solo,
            groupManager: $this->createMock(IGroupManager::class),
            logger: $this->createMock(LoggerInterface::class)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only an administrator');
        $svc->reject(applicationId: 'app-2', adminUserId: 'alice', isAdmin: false);
    }

    /**
     * Test get enforces the non-admin visibility rule.
     *
     * @return void
     */
    public function testGetEnforcesNonAdminVisibility(): void
    {
        $other = new Application();
        $other->setId('app-9');
        $other->setStatus(Application::STATUS_PENDING);
        $other->setRegisteredBy('bob');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('app-9')
            ->willReturn($other);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Not authorized');

        $this->service->get(applicationId: 'app-9', userId: 'alice', isAdmin: false);
    }

    /**
     * Test get returns the row when not found is translated to a 400-friendly error.
     *
     * @return void
     */
    public function testGetTranslatesNotFound(): void
    {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new DoesNotExistException('absent'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Application not found');

        $this->service->get(applicationId: 'missing', userId: 'alice', isAdmin: false);
    }

    /**
     * Admin registration with a valid >=4096-bit CSR persists the row.
     *
     * @return void
     */
    public function testRegisterAdminAcceptsValid4096Csr(): void
    {
        $csr = (string) file_get_contents(__DIR__.'/../fixtures/csr-4096.pem');
        $this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $result = $this->service->register(
            name: 'OK',
            description: null,
            type: Application::TYPE_INTERNAL,
            csr: $csr,
            userId: 'admin',
            isAdmin: true
        );

        $this->assertSame(Application::STATUS_ACTIVE, $result->getStatus());
        $this->assertSame($csr, $result->getCsr());
    }

    /**
     * Admin registration with a sub-4096-bit CSR is rejected.
     *
     * @return void
     */
    public function testRegisterAdminRejectsWeakCsr(): void
    {
        $csr = (string) file_get_contents(__DIR__.'/../fixtures/csr-2048.pem');
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/below the 4096-bit minimum/');
        $this->service->register(
            name: 'Weak',
            description: null,
            type: Application::TYPE_INTERNAL,
            csr: $csr,
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * Admin registration with a malformed CSR is rejected with a clear message.
     *
     * @return void
     */
    public function testRegisterAdminRejectsMalformedCsr(): void
    {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PKCS#10 format not recognised/');
        $this->service->register(
            name: 'Malformed',
            description: null,
            type: Application::TYPE_INTERNAL,
            csr: 'not-a-csr',
            userId: 'admin',
            isAdmin: true
        );
    }

    /**
     * Non-admin (pending) registration stores the CSR verbatim — validation
     * is deferred to approval time so anonymous registrants don't leak the
     * format/size checks back via 400-status timing.
     *
     * @return void
     */
    public function testRegisterPendingStoresAnyCsr(): void
    {
        $this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

        $result = $this->service->register(
            name: 'Pending',
            description: null,
            type: Application::TYPE_INTERNAL,
            csr: 'not-validated-here',
            userId: 'alice',
            isAdmin: false
        );

        $this->assertSame(Application::STATUS_PENDING, $result->getStatus());
        $this->assertSame('not-validated-here', $result->getCsr());
    }

    /**
     * Approval re-validates the stored CSR — a malformed pending CSR is
     * rejected when the admin tries to approve.
     *
     * @return void
     */
    public function testApproveRejectsMalformedStoredCsr(): void
    {
        $entity = new Application();
        $entity->setId('app-bad');
        $entity->setStatus(Application::STATUS_PENDING);
        $entity->setCsr('not-a-csr');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with('app-bad')
            ->willReturn($entity);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PKCS#10 format not recognised/');
        $this->service->approve(applicationId: 'app-bad', adminUserId: 'admin', isAdmin: true);
    }

    /**
     * Approval accepts a stored >=4096-bit CSR.
     *
     * @return void
     */
    public function testApproveAcceptsValidStoredCsr(): void
    {
        $csr = (string) file_get_contents(__DIR__.'/../fixtures/csr-4096.pem');

        $entity = new Application();
        $entity->setId('app-ok');
        $entity->setStatus(Application::STATUS_PENDING);
        $entity->setCsr($csr);

        $this->mapper->expects($this->once())->method('findById')->willReturn($entity);
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $result = $this->service->approve(applicationId: 'app-ok', adminUserId: 'admin', isAdmin: true);
        $this->assertSame(Application::STATUS_ACTIVE, $result->getStatus());
    }

    /**
     * §7.3 — When a non-admin (or anonymous) caller submits a
     * registration, the service notifies every admin via the
     * NotificationService with subject `app_pending`.
     *
     * @return void
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-7.3
     */
    public function testRegisterPendingDispatchesAdminNotification(): void
    {
        $mapper        = $this->createMock(ApplicationMapper::class);
        $groupManager  = $this->createMock(IGroupManager::class);
        $logger        = $this->createMock(LoggerInterface::class);
        $notifications = $this->createMock(NotificationService::class);

        $service = new ApplicationService(
            mapper: $mapper,
            groupManager: $groupManager,
            logger: $logger,
            notificationService: $notifications,
        );

        $mapper->method('insert')->willReturnArgument(0);

        $adminUser = $this->createMock(IUser::class);
        $adminUser->method('getUID')->willReturn('admin1');
        $adminGroup = $this->createMock(IGroup::class);
        $adminGroup->method('getUsers')->willReturn([$adminUser]);
        $groupManager->method('get')->with('admin')->willReturn($adminGroup);

        $notifications->expects($this->once())
            ->method('notify')
            ->with(
                'app_pending',
                'admin1',
                $this->callback(static function (array $params): bool {
                    return ($params['applicationName'] ?? null) === 'Bot'
                        && ($params['registeredBy'] ?? null) === 'alice';
                }),
                'application',
                $this->anything(),
            );

        $service->register(
            name: 'Bot',
            description: null,
            type: Application::TYPE_EXTERNAL,
            csr: null,
            userId: 'alice',
            isAdmin: false,
        );
    }

    /**
     * §7.3 — Admin-registered (auto-approved) apps do NOT trigger the
     * admin notification (the approve queue exists only for pending
     * rows).
     *
     * @return void
     */
    public function testRegisterActiveDoesNotDispatchAdminNotification(): void
    {
        $mapper        = $this->createMock(ApplicationMapper::class);
        $groupManager  = $this->createMock(IGroupManager::class);
        $notifications = $this->createMock(NotificationService::class);

        $service = new ApplicationService(
            mapper: $mapper,
            groupManager: $groupManager,
            logger: $this->createMock(LoggerInterface::class),
            notificationService: $notifications,
        );

        $mapper->method('insert')->willReturnArgument(0);
        $notifications->expects($this->never())->method('notify');

        $service->register(
            name: 'AdminApp',
            description: null,
            type: Application::TYPE_INTERNAL,
            csr: null,
            userId: 'admin',
            isAdmin: true,
        );
    }
}
