<?php

/**
 * Keepiq - SecretRequestController tests
 *
 * This controller had NO test file at all, and 0/121 statement coverage, while
 * carrying the user-facing request surface: the create endpoint that decides
 * between a fresh request, a re-request, a plain request and an application
 * request, and the destroy endpoint that revokes one.
 *
 * That gap is why the payload-shape defect survived: the dialog sent snake_case
 * while the store forwarded camelCase, and the only test that touched creation
 * mocked the store, so nothing ever asserted what this controller received.
 *
 * The tests below concentrate on the mode selection, because that is where the
 * fresh/re-request distinction is enforced and where getting it wrong sends a
 * request into the wrong service method.
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

use OCA\Keepiq\Controller\SecretRequestController;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Service\SecretRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the user-facing secret-request controller.
 */
class SecretRequestControllerTest extends TestCase {
	/**
	 * The service mock.
	 *
	 * @var SecretRequestService&MockObject
	 */
	private SecretRequestService&MockObject $service;

	/**
	 * The controller under test.
	 *
	 * @var SecretRequestController
	 */
	private SecretRequestController $controller;

	/**
	 * Wire the controller with an authenticated session.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = $this->createMock(SecretRequestService::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->controller = new SecretRequestController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			session: $session,
		);
	}//end setUp()

	/**
	 * Build a persisted request for the service mocks to return.
	 *
	 * @return SecretRequest
	 */
	private function made(): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId('req-1');
		$entity->setSecretId('sec-1');
		$entity->setToken('tok-1');
		$entity->setStatus(SecretRequest::STATUS_PENDING);

		return $entity;
	}//end made()

	/**
	 * With no secretId, creation goes through the FRESH path.
	 *
	 * The system creates the placeholder; the requester supplies no value for the
	 * credential they are asking for.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testCreateWithoutASecretIdTakesTheFreshPath(): void {
		$this->service->expects($this->once())
			->method('createForUserVault')
			->with('alice', ['key', 'url'], 'Supplier API key', null, null)
			->willReturn($this->made());
		$this->service->expects($this->never())->method('create');
		$this->service->expects($this->never())->method('createReRequest');

		$response = $this->controller->create(
			requestedFields: ['key', 'url'],
			name: 'Supplier API key',
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testCreateWithoutASecretIdTakesTheFreshPath()

	/**
	 * A re-request without a secretId is refused explicitly.
	 *
	 * It overwrites values in an existing Secret, so it cannot be asked for
	 * without naming one. Refused here rather than failing deeper on a generic
	 * message, because the two flows differing by required input is what keeps
	 * them distinct.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testReRequestWithoutASecretIdIsRefused(): void {
		$this->service->expects($this->never())->method('createReRequest');
		$this->service->expects($this->never())->method('createForUserVault');

		$response = $this->controller->create(isReRequest: true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('secretId', $response->getData()['message']);
	}//end testReRequestWithoutASecretIdIsRefused()

	/**
	 * A re-request WITH a secretId reaches the re-request path.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-re-request-update-in-place
	 */
	public function testReRequestWithASecretIdIsDispatched(): void {
		$this->service->expects($this->once())
			->method('createReRequest')
			->willReturn($this->made());
		$this->service->expects($this->never())->method('createForUserVault');

		$response = $this->controller->create(
			secretId: 'sec-1',
			requestedFields: ['key'],
			isReRequest: true,
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testReRequestWithASecretIdIsDispatched()

	/**
	 * A supplied secretId without the re-request flag keeps the plain path.
	 *
	 * The legacy flow still exists; a fresh request is only chosen when no Secret
	 * was named at all.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testASuppliedSecretIdKeepsThePlainPath(): void {
		$this->service->expects($this->once())->method('create')->willReturn($this->made());
		$this->service->expects($this->never())->method('createForUserVault');

		$response = $this->controller->create(
			secretId: 'sec-1',
			encryptionSuiteId: 'suite-1',
			requestedFields: ['key'],
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testASuppliedSecretIdKeepsThePlainPath()

	/**
	 * An applicationId wins over the other discriminators.
	 *
	 * Preserves the precedence the endpoint had before the fresh path existed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testAnApplicationIdTakesTheApplicationPath(): void {
		$this->service->expects($this->once())
			->method('createForApplication')
			->willReturn($this->made());
		$this->service->expects($this->never())->method('createForUserVault');

		$response = $this->controller->create(
			secretId: 'sec-1',
			requestedFields: ['key'],
			applicationId: 'app-1',
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testAnApplicationIdTakesTheApplicationPath()

	/**
	 * An unauthenticated caller is refused before any service call.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function testUnauthenticatedCreateIsRefused(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new SecretRequestController(
			request: $this->createMock(IRequest::class),
			service: $this->service,
			session: $session,
		);

		$this->service->expects($this->never())->method('createForUserVault');

		$this->assertSame(
			Http::STATUS_UNAUTHORIZED,
			$controller->create(requestedFields: ['key'])->getStatus()
		);
	}//end testUnauthenticatedCreateIsRefused()
	/**
	 * Service refusal codes map to an HTTP status: 403, 404 and 409 pass
	 * through, anything else is a 400; the message is returned as-is.
	 *
	 * @return array<string, array{int, int}>
	 */
	public static function refusalCodes(): array {
		return [
			'forbidden' => [403, Http::STATUS_FORBIDDEN],
			'not found' => [404, Http::STATUS_NOT_FOUND],
			'conflict' => [409, Http::STATUS_CONFLICT],
			'no code' => [0, Http::STATUS_BAD_REQUEST],
			'unauthorized code' => [401, Http::STATUS_BAD_REQUEST],
			'server code' => [500, Http::STATUS_BAD_REQUEST],
		];
	}//end refusalCodes()

	/**
	 * A service refusal becomes the mapped status with the service message.
	 *
	 * @param int $code The exception code the service throws
	 * @param int $expected The expected HTTP status
	 *
	 * @return void
	 */
	#[DataProvider('refusalCodes')]
	public function testAServiceRefusalMapsToItsStatus(int $code, int $expected): void {
		$this->service->method('createForUserVault')
			->willThrowException(new \InvalidArgumentException('Refused for a reason', $code));

		$response = $this->controller->create(requestedFields: ['key'], name: 'N');

		$this->assertSame($expected, $response->getStatus());
		$this->assertSame(['message' => 'Refused for a reason'], $response->getData());
	}//end testAServiceRefusalMapsToItsStatus()

	/**
	 * An empty applicationId does not select the application path.
	 *
	 * @return void
	 */
	public function testAnEmptyApplicationIdKeepsThePlainPath(): void {
		$this->service->expects($this->once())
			->method('create')
			->willReturn($this->made());
		$this->service->expects($this->never())->method('createForApplication');

		$response = $this->controller->create(
			secretId: 'sec-1',
			requestedFields: ['key'],
			applicationId: '',
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}//end testAnEmptyApplicationIdKeepsThePlainPath()
}//end class
