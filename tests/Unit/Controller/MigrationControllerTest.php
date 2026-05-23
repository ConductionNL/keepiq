<?php

/**
 * Unit tests for MigrationController.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
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

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\MigrationController;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MigrationController.
 */
class MigrationControllerTest extends TestCase
{
    private MigrationController $controller;
    private MigrationService&MockObject $migrationService;
    private IUserSession&MockObject $userSession;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $request = $this->createMock(IRequest::class);
        $this->migrationService = $this->createMock(MigrationService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new MigrationController(
            $request,
            $this->migrationService,
            $this->userSession,
        );
    }

    /**
     * Test getStatus returns migration when in progress.
     *
     * @return void
     */
    public function testGetStatusReturnsMigration(): void
    {
        $migration = new SuiteMigration();
        $migration->setId('migr-1');
        $migration->setOldSuiteId('old-suite');
        $migration->setNewSuiteId('new-suite');
        $migration->setStatus('in_progress');

        $this->migrationService->method('getInProgressMigration')
            ->with('user', 'testuser')
            ->willReturn($migration);

        $response = $this->controller->getStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('migr-1', $response->getData()['id']);
        $this->assertSame('in_progress', $response->getData()['status']);
    }

    /**
     * Test getStatus returns 'none' when no migration in progress.
     *
     * @return void
     */
    public function testGetStatusReturnsNoneWhenNoMigration(): void
    {
        $this->migrationService->method('getInProgressMigration')
            ->willThrowException(new DoesNotExistException('No migration'));

        $response = $this->controller->getStatus();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('none', $response->getData()['status']);
    }

    /**
     * Test complete delegates and returns migration.
     *
     * @return void
     */
    public function testCompleteReturnsMigration(): void
    {
        $migration = new SuiteMigration();
        $migration->setId('migr-1');
        $migration->setStatus('completed');

        $this->migrationService->method('completeMigration')
            ->with('migr-1', false)
            ->willReturn($migration);

        $response = $this->controller->complete('migr-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('completed', $response->getData()['status']);
    }

    /**
     * Test complete with errors.
     *
     * @return void
     */
    public function testCompleteWithErrors(): void
    {
        $migration = new SuiteMigration();
        $migration->setId('migr-1');
        $migration->setStatus('completed_with_errors');

        $this->migrationService->method('completeMigration')
            ->with('migr-1', true)
            ->willReturn($migration);

        $response = $this->controller->complete('migr-1', true);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('completed_with_errors', $response->getData()['status']);
    }

    /**
     * Test complete returns 400 on failure.
     *
     * @return void
     */
    public function testCompleteReturns400OnFailure(): void
    {
        $this->migrationService->method('completeMigration')
            ->willThrowException(new DoesNotExistException('Migration not found'));

        $response = $this->controller->complete('nonexistent');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }
}
