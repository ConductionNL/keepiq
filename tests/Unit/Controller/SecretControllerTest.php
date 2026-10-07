<?php

/**
 * Unit tests for SecretController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\SecretController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\SuiteBlockedException;
use OCA\Keepiq\Exception\WriteLockedException;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretController.
 */
class SecretControllerTest extends TestCase {

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
	 * The signed-in session (alice).
	 *
	 * @var IUserSession&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * Build the controller.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->secretService = $this->createMock(SecretService::class);

		$userSession = $this->createMock(IUserSession::class);
		$this->userSession = $userSession;
		$user = $this->createMock(IUser::class);
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
	private function makeSecret(): Secret {
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
	public function testShowReturnsCiphertext(): void {
		$this->secretService->method('get')->willReturn($this->makeSecret());

		$response = $this->controller->show('s-1');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('ENCRYPTED', $data['key']);
	}//end testShowReturnsCiphertext()

	/**
	 * show() on another user's secret returns 403.
	 *
	 * @return void
	 */
	public function testShowForeignSecretForbidden(): void {
		$this->secretService->method('get')->willThrowException(new ForbiddenException('nope'));

		$response = $this->controller->show('s-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testShowForeignSecretForbidden()

	/**
	 * show() on a missing secret returns 404.
	 *
	 * @return void
	 */
	public function testShowMissingSecretNotFound(): void {
		$this->secretService->method('get')->willThrowException(new NotFoundException('gone'));

		$response = $this->controller->show('s-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowMissingSecretNotFound()

	/**
	 * show() on a revoked-suite secret returns 403.
	 *
	 * @return void
	 */
	public function testShowRevokedSuiteForbidden(): void {
		$this->secretService->method('get')->willThrowException(new SuiteBlockedException('revoked'));

		$response = $this->controller->show('s-1');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testShowRevokedSuiteForbidden()

	/**
	 * create() during a write lock returns 423 Locked.
	 *
	 * @return void
	 */
	public function testCreateWriteLockedReturns423(): void {
		$this->secretService->method('create')->willThrowException(new WriteLockedException('locked'));

		$response = $this->controller->create('Name', 'CIPHER');
		$this->assertSame(423, $response->getStatus());
	}//end testCreateWriteLockedReturns423()

	/**
	 * create() with no active suite returns 403.
	 *
	 * @return void
	 */
	public function testCreateNoSuiteReturns403(): void {
		$this->secretService->method('create')->willThrowException(new SuiteBlockedException('no suite'));

		$response = $this->controller->create('Name', 'CIPHER');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testCreateNoSuiteReturns403()

	/**
	 * create() returns the created secret with 201.
	 *
	 * @return void
	 */
	public function testCreateReturns201(): void {
		$this->secretService->method('create')->willReturn($this->makeSecret());

		$response = $this->controller->create('GitHub', 'ENCRYPTED');
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame('ENCRYPTED', $response->getData()['key']);
	}//end testCreateReturns201()

	/**
	 * index() returns the service list result.
	 *
	 * @return void
	 */
	public function testIndexReturnsList(): void {
		$this->secretService->method('list')->willReturn(
			['items' => [], 'total' => 0, 'page' => 1, 'limit' => 50]
		);

		$response = $this->controller->index();
		$this->assertSame(0, $response->getData()['total']);
	}//end testIndexReturnsList()

	/**
	 * admin-vault-policies §4.1: a policy refusal reaches the client as 403
	 * with the policy code, on create and on update.
	 *
	 * @return void
	 */
	public function testPolicyRefusalCarriesTheCode(): void {
		$refusal = new \OCA\Keepiq\Exception\PolicyViolationException(
			policyCode: 'org_ownership_required',
			message: 'kept in a team folder'
		);
		$this->secretService->method('create')->willThrowException($refusal);
		$this->secretService->method('update')->willThrowException($refusal);
		$this->request->method('getParam')->willReturnArgument(0);

		$created = $this->controller->create(name: 'Bank', key: 'CIPHERTEXT-BLOB-0001', folderId: 'private');
		// The update route lives in its own controller (offline-edit-queue).
		$updater = new \OCA\Keepiq\Controller\SecretUpdateController(
			request: $this->request,
			secretService: $this->secretService,
			userSession: $this->userSession,
		);
		$updated = $updater->update(id: 's-1');

		foreach ([$created, $updated] as $response) {
			$this->assertSame(403, $response->getStatus());
			$this->assertSame('org_ownership_required', $response->getData()['code']);
		}
	}//end testPolicyRefusalCarriesTheCode()

	/**
	 * The 403 above must reach the browser as a refusal with its code.
	 * Nextcloud's OCSMiddleware rewrites a 403 JSONResponse of an
	 * OCSController into an HTTP 200 OCS v1 envelope without the `code`
	 * (found live, 4 Oct 2026: a refused personal login read as a saved
	 * secret). The controller stays an OCSController, so the CSRF model is
	 * the same as every other Keepiq API controller, and Keepiq's
	 * OcsRefusalMiddleware hands the refusal on as 428 with the policy code
	 * as `error`, which the OCS layer leaves alone.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-policies/spec.md#requirement-work-logins-are-kept-in-team-folders
	 */
	public function testPolicyRefusalLeavesAs428WithItsCode(): void {
		$this->assertTrue(
			is_subclass_of(SecretController::class, \OCP\AppFramework\OCSController::class),
			'SecretController keeps the CSRF model of the other API controllers'
		);

		$this->secretService->method('create')->willThrowException(
			new \OCA\Keepiq\Exception\PolicyViolationException(
				policyCode: 'org_ownership_required',
				message: 'kept in a team folder'
			)
		);
		$this->request->method('getParam')->willReturnArgument(0);

		$response = (new \OCA\Keepiq\Middleware\OcsRefusalMiddleware())->afterController(
			$this->controller,
			'create',
			$this->controller->create(name: 'Bank', key: 'CIPHERTEXT-BLOB-0001', folderId: 'private')
		);

		$this->assertSame(428, $response->getStatus());
		$this->assertSame('org_ownership_required', $response->getData()['error']);
		$this->assertSame('org_ownership_required', $response->getData()['code']);
	}//end testPolicyRefusalLeavesAs428WithItsCode()
}//end class
