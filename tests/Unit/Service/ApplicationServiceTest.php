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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
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
}
