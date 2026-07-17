<?php

/**
 * Unit tests for SecretController.
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

use OCA\Doriath\Controller\SecretController;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Exception\WriteLockedException;
use OCA\Doriath\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretController.
 */
class SecretControllerTest extends TestCase
{

    /**
     * @var SecretController
     */
    private SecretController $controller;

    /**
     * @var SecretService&MockObject
     */
    private SecretService&MockObject $secretService;

    /**
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request       = $this->createMock(IRequest::class);
        $this->secretService = $this->createMock(SecretService::class);

        $userSession = $this->createMock(IUserSession::class);
        $user        = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession->method('getUser')->willReturn($user);

        $this->controller = new SecretController(
            request: $this->request,
            secretService: $this->secretService,
            userSession: $userSession,
        );
    }//end setUp()

    /**
     * Build a secret with ciphertext.
     *
     * @return Secret
     */
    private function makeSecret(): Secret
    {
        $secret = new Secret();
        $secret->setId('s-1');
        $secret->setName('GitHub');
        $secret->setKey('ENCRYPTED');
        $secret->setEncryptionSuiteId('suite-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId('alice');
        return $secret;
    }//end makeSecret()

    /**
     * show() returns the ciphertext, never a decrypted value.
     *
     * @return void
     */
    public function testShowReturnsCiphertext(): void
    {
        $this->secretService->method('get')->willReturn($this->makeSecret());

        $response = $this->controller->show('s-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('ENCRYPTED', $data['key']);
    }//end testShowReturnsCiphertext()

    /**
     * show() on another user's secret returns 403.
     *
     * @return void
     */
    public function testShowForeignSecretForbidden(): void
    {
        $this->secretService->method('get')->willThrowException(new ForbiddenException('nope'));

        $response = $this->controller->show('s-1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testShowForeignSecretForbidden()

    /**
     * show() on a missing secret returns 404.
     *
     * @return void
     */
    public function testShowMissingSecretNotFound(): void
    {
        $this->secretService->method('get')->willThrowException(new NotFoundException('gone'));

        $response = $this->controller->show('s-1');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testShowMissingSecretNotFound()

    /**
     * show() on a revoked-suite secret returns 403.
     *
     * @return void
     */
    public function testShowRevokedSuiteForbidden(): void
    {
        $this->secretService->method('get')->willThrowException(new SuiteBlockedException('revoked'));

        $response = $this->controller->show('s-1');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testShowRevokedSuiteForbidden()

    /**
     * create() during a write lock returns 423 Locked.
     *
     * @return void
     */
    public function testCreateWriteLockedReturns423(): void
    {
        $this->secretService->method('create')->willThrowException(new WriteLockedException('locked'));

        $response = $this->controller->create('Name', 'CIPHER');
        $this->assertSame(423, $response->getStatus());
    }//end testCreateWriteLockedReturns423()

    /**
     * create() with no active suite returns 403.
     *
     * @return void
     */
    public function testCreateNoSuiteReturns403(): void
    {
        $this->secretService->method('create')->willThrowException(new SuiteBlockedException('no suite'));

        $response = $this->controller->create('Name', 'CIPHER');
        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testCreateNoSuiteReturns403()

    /**
     * create() returns the created secret with 201.
     *
     * @return void
     */
    public function testCreateReturns201(): void
    {
        $this->secretService->method('create')->willReturn($this->makeSecret());

        $response = $this->controller->create('GitHub', 'ENCRYPTED');
        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
        $this->assertSame('ENCRYPTED', $response->getData()['key']);
    }//end testCreateReturns201()

    /**
     * destroy() deletes and returns a status payload.
     *
     * @return void
     */
    public function testDestroyReturnsStatus(): void
    {
        $this->secretService->expects($this->once())->method('delete')->with('s-1', 'alice');

        $response = $this->controller->destroy('s-1');
        $this->assertSame('deleted', $response->getData()['status']);
    }//end testDestroyReturnsStatus()

    /**
     * index() returns the service list result.
     *
     * @return void
     */
    public function testIndexReturnsList(): void
    {
        $this->secretService->method('list')->willReturn(
            ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 50]
        );

        $response = $this->controller->index();
        $this->assertSame(0, $response->getData()['total']);
    }//end testIndexReturnsList()
}//end class
