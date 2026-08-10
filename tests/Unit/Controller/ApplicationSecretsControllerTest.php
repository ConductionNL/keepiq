<?php

/**
 * Unit tests for ApplicationSecretsController (machine secret-store API).
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

use DateTime;
use OCA\Doriath\Controller\ApplicationSecretsController;
use OCA\Doriath\Db\Application;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Service\MachineSecretEnvelopeService;
use OCA\Doriath\Service\MachineSecretResponseService;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the machine secret-store endpoints: by-name resolution semantics,
 * the encrypted envelope, ETag/304, updated_since, strict own-vault
 * scoping, and client-encrypted write-back.
 */
class ApplicationSecretsControllerTest extends TestCase
{

    private IRequest $request;

    private SecretMapper $secretMapper;

    private FolderMapper $folderMapper;

    private SecretService $secretService;

    private MachineSecretEnvelopeService $envelopeService;

    private IEventDispatcher $dispatcher;

    private ApplicationSecretsController $controller;

    /**
     * @var array<string,string> Header overrides for the mock request.
     */
    private array $headers = [];

    /**
     * @var array<string,string|null> Param overrides for the mock request.
     */
    private array $params = [];

    /**
     * Wire the controller with a real envelope service and a real response
     * service (both over mocked mappers) and an authenticated application.
     *
     * The response service is the REAL collaborator, not a stub: the ETag/304
     * and audit-dispatch assertions below are assertions about what it does,
     * so stubbing it would assert nothing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->secretMapper    = $this->createMock(SecretMapper::class);
        $this->folderMapper    = $this->createMock(FolderMapper::class);
        $this->secretService   = $this->createMock(SecretService::class);
        $this->dispatcher      = $this->createMock(IEventDispatcher::class);
        $this->envelopeService = new MachineSecretEnvelopeService(
            suiteMapper: $this->createMock(\OCA\Doriath\Db\EncryptionSuiteMapper::class),
            folderMapper: $this->folderMapper,
        );

        $this->request->method('getParam')->willReturnCallback(
            fn (string $k, $d=null) => $this->params[$k] ?? $d
        );
        $this->request->method('getHeader')->willReturnCallback(
            fn (string $k) => $this->headers[$k] ?? ''
        );

        $this->controller = new ApplicationSecretsController(
            request: $this->request,
            secretMapper: $this->secretMapper,
            folderMapper: $this->folderMapper,
            secretService: $this->secretService,
            envelopeService: $this->envelopeService,
            responseService: new MachineSecretResponseService(
                request: $this->request,
                envelopeService: $this->envelopeService,
                eventDispatcher: $this->dispatcher,
            ),
        );

        $app = new Application();
        $app->setId('app-1');
        $app->setName('Connector');
        $this->controller->setApplication($app);
    }//end setUp()

    /**
     * Build a secret owned by the given application.
     *
     * @param string $id      The secret id
     * @param string $name    The secret name
     * @param string $ownerId The owning application id
     *
     * @return Secret
     */
    private function secret(string $id, string $name, string $ownerId='app-1'): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName($name);
        $secret->setTypeId('api_key');
        $secret->setKey('CIPHER');
        $secret->setEncryptionSuiteId('suite-1');
        $secret->setOwnerType('application');
        $secret->setOwnerId($ownerId);
        $secret->setCreatedAt(new DateTime('2026-01-01T00:00:00+00:00'));
        $secret->setUpdatedAt(new DateTime('2026-01-02T00:00:00+00:00'));
        return $secret;
    }//end secret()

    /**
     * by-name with exactly one match returns the envelope.
     *
     * @return void
     */
    public function testByNameSingleMatch(): void
    {
        $this->secretMapper->method('findByName')
            ->willReturn([$this->secret('s1', 'zgw-api-token')]);

        $response = $this->controller->byName('zgw-api-token');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('doriath-machine-secret-v1', $response->getData()['format']);
    }//end testByNameSingleMatch()

    /**
     * by-name with no match returns 404.
     *
     * @return void
     */
    public function testByNameNoMatch(): void
    {
        $this->secretMapper->method('findByName')->willReturn([]);
        $response = $this->controller->byName('missing');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testByNameNoMatch()

    /**
     * by-name with multiple matches returns 409 with candidates and never
     * silently picks one.
     *
     * @return void
     */
    public function testByNameAmbiguousReturns409WithCandidates(): void
    {
        $this->folderMapper->method('getPath')->willReturn('a/b');
        $this->secretMapper->method('findByName')->willReturn(
                [
                    $this->secret('s1', 'dup'),
                    $this->secret('s2', 'dup'),
                ]
                );

        $response = $this->controller->byName('dup');
        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $candidates = $response->getData()['candidates'];
        $this->assertCount(2, $candidates);
        $this->assertSame(['id', 'name', 'folderPath', 'updatedAt'], array_keys($candidates[0]));
    }//end testByNameAmbiguousReturns409WithCandidates()

    /**
     * A folder-scoped by-name request resolves the folder path to an id and
     * passes it to the mapper.
     *
     * @return void
     */
    public function testByNameFolderScoped(): void
    {
        $this->params['folder'] = 'infra/zgw';
        $folder = new \OCA\Doriath\Db\Folder();
        $folder->setId('fol-9');
        $this->folderMapper->method('findByOwner')->willReturn([$folder]);
        $this->folderMapper->method('getPath')->willReturn('infra/zgw');

        $this->secretMapper->expects($this->once())
            ->method('findByName')
            ->with('application', 'app-1', 'zgw-api-token', 'fol-9')
            ->willReturn([$this->secret('s1', 'zgw-api-token')]);

        $response = $this->controller->byName('zgw-api-token');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testByNameFolderScoped()

    /**
     * A by-name request scoped to a nonexistent folder returns 404.
     *
     * @return void
     */
    public function testByNameUnknownFolderReturns404(): void
    {
        $this->params['folder'] = 'no/such';
        $this->folderMapper->method('findByOwner')->willReturn([]);

        $response = $this->controller->byName('x');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testByNameUnknownFolderReturns404()

    /**
     * show() on another application's secret returns 404, indistinguishable
     * from a nonexistent id (no existence oracle).
     *
     * @return void
     */
    public function testCrossVaultShowReturns404(): void
    {
        $this->secretMapper->method('findById')
            ->willReturn($this->secret('s1', 'other', 'app-2'));

        $response = $this->controller->show('s1');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testCrossVaultShowReturns404()

    /**
     * show() on a nonexistent id returns the same 404.
     *
     * @return void
     */
    public function testShowNonexistentReturns404(): void
    {
        $this->secretMapper->method('findById')
            ->willThrowException(new DoesNotExistException('none'));

        $response = $this->controller->show('ghost');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowNonexistentReturns404()

    /**
     * A matching If-None-Match returns 304 with no body and no audit event.
     *
     * @return void
     */
    public function testIfNoneMatchReturns304(): void
    {
        $secret = $this->secret('s1', 'zgw-api-token');
        $this->secretMapper->method('findById')->willReturn($secret);
        $etag = $this->envelopeService->etag($secret);
        $this->headers['If-None-Match'] = $etag;

        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $response = $this->controller->show('s1');
        $this->assertSame(Http::STATUS_NOT_MODIFIED, $response->getStatus());
        $this->assertSame([], $response->getData());
    }//end testIfNoneMatchReturns304()

    /**
     * A full read dispatches an application.secret_retrieved audit event.
     *
     * @return void
     */
    public function testFullReadDispatchesAudit(): void
    {
        $this->secretMapper->method('findById')
            ->willReturn($this->secret('s1', 'zgw-api-token'));
        $this->dispatcher->expects($this->once())->method('dispatchTyped');

        $response = $this->controller->show('s1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testFullReadDispatchesAudit()

    /**
     * index with a valid updated_since calls the filtered mapper query.
     *
     * @return void
     */
    public function testUpdatedSinceFilter(): void
    {
        $this->params['updated_since'] = '2026-01-01T00:00:00+00:00';
        $this->secretMapper->expects($this->once())
            ->method('findByOwnerUpdatedSince')
            ->willReturn([$this->secret('s1', 'a')]);
        $this->secretMapper->expects($this->never())->method('findByOwner');

        $response = $this->controller->index();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(1, $response->getData()['total']);
    }//end testUpdatedSinceFilter()

    /**
     * An invalid updated_since value returns 400.
     *
     * @return void
     */
    public function testUpdatedSinceInvalidReturns400(): void
    {
        $this->params['updated_since'] = 'not-a-date';
        $response = $this->controller->index();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testUpdatedSinceInvalidReturns400()

    /**
     * Write-back create returns 201 with the envelope.
     *
     * @return void
     */
    public function testCreateReturns201(): void
    {
        $this->params['name'] = 'new-token';
        $this->params['key']  = 'CIPHER';
        $this->secretService->method('createByApplication')
            ->willReturn($this->secret('s9', 'new-token'));

        $response = $this->controller->create();
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('doriath-machine-secret-v1', $response->getData()['format']);
    }//end testCreateReturns201()

    /**
     * Write-back update advances the secret and returns the new envelope;
     * a cross-vault id surfaces as 404.
     *
     * @return void
     */
    public function testUpdateCrossVaultReturns404(): void
    {
        $this->secretService->method('updateByApplication')
            ->willThrowException(new NotFoundException('Secret not found'));

        $response = $this->controller->update('s-other');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testUpdateCrossVaultReturns404()

    /**
     * The machine surface exposes no delete handler — the controller class
     * has no destroy()/delete() method.
     *
     * @return void
     */
    public function testNoDeleteHandlerExists(): void
    {
        $this->assertFalse(method_exists($this->controller, 'destroy'));
        $this->assertFalse(method_exists($this->controller, 'delete'));
    }//end testNoDeleteHandlerExists()
}//end class
