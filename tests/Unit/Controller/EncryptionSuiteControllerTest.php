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

    /**
     * The controller under test.
     *
     * @var EncryptionSuiteController
     */
    private EncryptionSuiteController $controller;

    /**
     * The mocked suite service.
     *
     * @var EncryptionSuiteService&MockObject
     */
    private EncryptionSuiteService&MockObject $suiteService;

    /**
     * The mocked migration service.
     *
     * @var MigrationService&MockObject
     */
    private MigrationService&MockObject $migrationService;

    /**
     * The mocked user session.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $request            = $this->createMock(originalClassName: IRequest::class);
        $this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
        $this->migrationService = $this->createMock(originalClassName: MigrationService::class);
        $this->userSession      = $this->createMock(originalClassName: IUserSession::class);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new EncryptionSuiteController(
            request: $request,
            suiteService: $this->suiteService,
            migrationService: $this->migrationService,
            userSession: $this->userSession,
        );
    }//end setUp()

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

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertCount(expectedCount: 2, haystack: $data);
        $this->assertSame(expected: 'suite-1', actual: $data[0]['id']);
        $this->assertSame(expected: 'suite-2', actual: $data[1]['id']);
    }//end testIndexReturnsSuites()

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

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'suite-1', actual: $response->getData()['id']);
    }//end testShowReturnsSuite()

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

        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
    }//end testShowReturns404WhenNotFound()

    /**
     * Test show returns 404 when suite belongs to another user.
     *
     * @return void
     */
    public function testShowReturns403ForOtherUsersSuite(): void
    {
        $suite = new EncryptionSuite();
        $suite->setId('suite-1');
        $suite->setOwnerType('user');
        $suite->setOwnerId('otheruser');

        $this->suiteService->method('getSuite')
            ->with('suite-1')
            ->willReturn($suite);

        $response = $this->controller->show('suite-1');

        // ValidateOwnership throws RuntimeException caught as a generic Exception -> NOT_FOUND.
        $this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
        $this->assertArrayHasKey(key: 'message', array: $response->getData());
        $this->assertStringContainsString(needle: 'Access denied', haystack: $response->getData()['message']);
    }//end testShowReturns403ForOtherUsersSuite()

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

        $this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
        $this->assertSame(expected: 'new-suite', actual: $response->getData()['id']);
    }//end testCreateReturns201OnSuccess()

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

        $this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
        $this->assertArrayHasKey(key: 'message', array: $response->getData());
    }//end testCreateReturns503WhenCaDegraded()

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

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'new-encrypted-pk', actual: $response->getData()['privateKey']);
    }//end testUpdatePrivateKeyReturnsSuite()

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

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
    }//end testRevokeReturnsSuite()

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

        $this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
    }//end testRevokeReturns400ForCompromisedSuite()

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

        $this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
        $this->assertSame(expected: 'active', actual: $response->getData()['status']);
    }//end testReinstateReturnsSuite()

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

        $this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
    }//end testReinstateReturns400WhenNotRevoked()

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

        $this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
        $data = $response->getData();
        $this->assertSame(expected: 'new-suite', actual: $data['newSuite']['id']);
        $this->assertSame(expected: 'migr-1', actual: $data['migration']['id']);
        $this->assertSame(expected: 'old-encrypted-pk', actual: $data['oldEncryptedPrivateKey']);
    }//end testCompromiseRecoverySuccess()

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

        $this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
    }//end testCompromiseRecoveryReturns500OnFailure()
}//end class
