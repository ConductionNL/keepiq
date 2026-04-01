<?php

/**
 * Unit tests for EncryptionSuiteController.
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

use InvalidArgumentException;
use OCA\Doriath\Controller\EncryptionSuiteController;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\SuiteMigration;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for EncryptionSuiteController.
 */
class EncryptionSuiteControllerTest extends TestCase
{
    private EncryptionSuiteController $controller;
    private EncryptionSuiteService&MockObject $suiteService;
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
        $this->suiteService = $this->createMock(EncryptionSuiteService::class);
        $this->migrationService = $this->createMock(MigrationService::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new EncryptionSuiteController(
            $request,
            $this->suiteService,
            $this->migrationService,
            $this->userSession,
        );
    }

    /**
     * Test index returns the current user's suites.
     *
     * @return void
     */
    public function testIndexReturnsSuites(): void
    {
        $suite1 = new EncryptionSuite();
        $suite1->setId('suite-1');
        $suite1->setOwnerType('user');
        $suite1->setOwnerId('testuser');
        $suite1->setStatus('active');

        $suite2 = new EncryptionSuite();
        $suite2->setId('suite-2');
        $suite2->setOwnerType('user');
        $suite2->setOwnerId('testuser');
        $suite2->setStatus('revoked');

        $this->suiteService->method('getSuitesByOwner')
            ->with('user', 'testuser')
            ->willReturn([$suite1, $suite2]);

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertCount(2, $data);
        $this->assertSame('suite-1', $data[0]['id']);
        $this->assertSame('suite-2', $data[1]['id']);
    }

    /**
     * Test show returns a suite.
     *
     * @return void
     */
    public function testShowReturnsSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('testuser');

        $this->suiteService->method('getSuite')
            ->with('suite-1')
            ->willReturn($suite);

        $response = $this->controller->show('suite-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('suite-1', $response->getData()['id']);
    }

    /**
     * Test show returns 404 when suite not found.
     *
     * @return void
     */
    public function testShowReturns404WhenNotFound(): void
    {
        $this->suiteService->method('getSuite')
            ->willThrowException(new DoesNotExistException('Not found'));

        $response = $this->controller->show('nonexistent');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }

    /**
     * Test create returns 201 on success.
     *
     * @return void
     */
    public function testCreateReturns201OnSuccess(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('new-suite');
        $suite->setOwnerType('user');
        $suite->setOwnerId('testuser');
        $suite->setStatus('active');

        $this->suiteService->method('createSuite')
            ->with('user', 'testuser', 'pub-key-pem', 'encrypted-pk')
            ->willReturn($suite);

        $response = $this->controller->create('pub-key-pem', 'encrypted-pk');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('new-suite', $response->getData()['id']);
    }

    /**
     * Test create returns 503 when CA is degraded.
     *
     * @return void
     */
    public function testCreateReturns503WhenCaDegraded(): void
    {
        $this->suiteService->method('createSuite')
            ->willThrowException(new RuntimeException('CA is not healthy'));

        $response = $this->controller->create('pub-key', 'encrypted-pk');

        $this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
        $this->assertArrayHasKey('message', $response->getData());
    }

    /**
     * Test updatePrivateKey returns updated suite.
     *
     * @return void
     */
    public function testUpdatePrivateKeyReturnsSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('testuser');
        $suite->setPrivateKey('old-pk');

        $this->suiteService->method('getSuite')
            ->with('suite-1')
            ->willReturn($suite);

        $response = $this->controller->updatePrivateKey('suite-1', 'new-encrypted-pk');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('new-encrypted-pk', $response->getData()['privateKey']);
    }

    /**
     * Test revoke returns revoked suite.
     *
     * @return void
     */
    public function testRevokeReturnsSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('revoked');

        $this->suiteService->method('revokeSuite')
            ->with('suite-1', 'security concern', 'testuser')
            ->willReturn($suite);

        $response = $this->controller->revoke('suite-1', 'security concern');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('revoked', $response->getData()['status']);
    }

    /**
     * Test revoke returns 400 for already compromised suite.
     *
     * @return void
     */
    public function testRevokeReturns400ForCompromisedSuite(): void
    {
        $this->suiteService->method('revokeSuite')
            ->willThrowException(new InvalidArgumentException('Cannot revoke a compromised suite'));

        $response = $this->controller->revoke('suite-1', 'test');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test reinstate returns reinstated suite.
     *
     * @return void
     */
    public function testReinstateReturnsSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setStatus('active');

        $this->suiteService->method('reinstateSuite')
            ->with('suite-1', 'testuser')
            ->willReturn($suite);

        $response = $this->controller->reinstate('suite-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('active', $response->getData()['status']);
    }

    /**
     * Test reinstate returns 400 when suite is not revoked.
     *
     * @return void
     */
    public function testReinstateReturns400WhenNotRevoked(): void
    {
        $this->suiteService->method('reinstateSuite')
            ->willThrowException(new InvalidArgumentException('Only revoked suites can be reinstated'));

        $response = $this->controller->reinstate('suite-1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    /**
     * Test compromiseRecovery creates new suite and migration.
     *
     * @return void
     */
    public function testCompromiseRecoverySuccess(): void
    {
        $oldSuite = new EncryptionSuite();
        $oldSuite->setId('old-suite');
        $oldSuite->setPrivateKey('old-encrypted-pk');

        $newSuite = new EncryptionSuite();
        $newSuite->setId('new-suite');
        $newSuite->setStatus('active');

        $migration = new SuiteMigration();
        $migration->setId('migr-1');
        $migration->setOldSuiteId('old-suite');
        $migration->setNewSuiteId('new-suite');
        $migration->setStatus('in_progress');

        $this->suiteService->method('getActiveSuite')
            ->with('user', 'testuser')
            ->willReturn($oldSuite);
        $this->suiteService->method('createSuite')
            ->willReturn($newSuite);
        $this->migrationService->method('initiateCompromiseRecovery')
            ->with('old-suite', 'new-suite')
            ->willReturn($migration);

        $response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('new-suite', $data['newSuite']['id']);
        $this->assertSame('migr-1', $data['migration']['id']);
        $this->assertSame('old-encrypted-pk', $data['oldEncryptedPrivateKey']);
    }

    /**
     * Test compromiseRecovery returns 500 on failure.
     *
     * @return void
     */
    public function testCompromiseRecoveryReturns500OnFailure(): void
    {
        $this->suiteService->method('getActiveSuite')
            ->willThrowException(new DoesNotExistException('No active suite'));

        $response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
    }
}
