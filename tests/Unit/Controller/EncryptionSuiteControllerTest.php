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
use OCA\Doriath\Exception\CaUnavailableException;
use OCA\Doriath\Service\EncryptionSuiteService;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
     * The mocked link share service.
     *
     * @var LinkShareService&MockObject
     */
    private LinkShareService&MockObject $linkShareService;

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
        $this->linkShareService = $this->createMock(originalClassName: LinkShareService::class);
        $this->userSession      = $this->createMock(originalClassName: IUserSession::class);

        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new EncryptionSuiteController(
            request: $request,
            suiteService: $this->suiteService,
            migrationService: $this->migrationService,
            linkShareService: $this->linkShareService,
            userSession: $this->userSession,
            logger: $this->createMock(originalClassName: LoggerInterface::class),
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
     * Test create returns 503 with Doriath's own message when the CA is degraded.
     *
     * A CaUnavailableException is raised deliberately by Doriath and its message
     * is authored here, so it is safe to hand back to the caller.
     *
     * @return void
     */
    public function testCreateReturns503WhenCaDegraded(): void
    {
        $this->suiteService->method('createSuite')
            ->willThrowException(new CaUnavailableException('Cannot create EncryptionSuite: CA is not healthy (status: degraded)'));

        $response = $this->controller->create('pub-key', 'encrypted-pk');

        $this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
        $this->assertSame(
            expected: 'Cannot create EncryptionSuite: CA is not healthy (status: degraded)',
            actual: $response->getData()['message']
        );
    }//end testCreateReturns503WhenCaDegraded()

    /**
     * Test create never forwards an internal exception message to the client.
     *
     * Regression test. Nextcloud's own crypto layer throws a bare
     * \RuntimeException('HMAC does not match.') when the instance secret in
     * config.php no longer matches the sealed CA private key. The controller
     * used to `catch (RuntimeException $e)` and return `$e->getMessage()`
     * verbatim, so that internal detail was served to the client as a 503 body
     * with no corresponding log entry anywhere.
     *
     * @return void
     */
    public function testCreateDoesNotLeakInternalExceptionMessages(): void
    {
        $this->suiteService->method('createSuite')
            ->willThrowException(new RuntimeException('HMAC does not match.'));

        $response = $this->controller->create('pub-key', 'encrypted-pk');
        $message  = $response->getData()['message'];

        // Still a server-availability fault, not a client error: the request
        // was well-formed and will succeed once the CA is repaired.
        $this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
        $this->assertStringNotContainsString(needle: 'HMAC', haystack: $message);
        $this->assertSame(
            expected: 'Could not create encryption suite. Please contact your administrator.',
            actual: $message
        );
    }//end testCreateDoesNotLeakInternalExceptionMessages()

    /**
     * Test an internal failure is logged even though it is not returned.
     *
     * The detail has to survive somewhere — before this change the only record
     * of an HMAC failure was the HTTP response body, and nextcloud.log had
     * nothing at all.
     *
     * @return void
     */
    public function testCreateLogsInternalFailures(): void
    {
        $logger  = $this->createMock(originalClassName: LoggerInterface::class);
        $request = $this->createMock(originalClassName: IRequest::class);

        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('unexpectedly'),
                $this->arrayHasKey('exception')
            );

        $controller = new EncryptionSuiteController(
            request: $request,
            suiteService: $this->suiteService,
            migrationService: $this->migrationService,
            linkShareService: $this->linkShareService,
            userSession: $this->userSession,
            logger: $logger,
        );

        $this->suiteService->method('createSuite')
            ->willThrowException(new RuntimeException('HMAC does not match.'));

        $controller->create('pub-key', 'encrypted-pk');
    }//end testCreateLogsInternalFailures()

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

    /**
     * Test compromiseRecovery cascades to LinkShareService.deleteByUserId.
     *
     * Every outstanding link share signed against the now-compromised public
     * key must be invalidated so a holder cannot decrypt the snapshot after
     * the user reports the breach. The cascade fires *after* markCompromised
     * (so the old suite is already locked) and *before* createSuite (so the
     * new suite never receives leaked references).
     *
     * @return void
     *
     * @spec openspec/changes/implement-link-sharing/tasks.md#5.2
     */
    public function testCompromiseRecoveryCascadesLinkShareDeleteByUserId(): void
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
            ->willReturn($migration);

        $this->linkShareService->expects($this->once())
            ->method('deleteByUserId')
            ->with('testuser');

        $response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

        $this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
    }//end testCompromiseRecoveryCascadesLinkShareDeleteByUserId()
}//end class
