<?php

/**
 * Outcome tests for FederatedShareController with a signed-in owner.
 *
 * The owner's create, list, update, revoke and suspend calls hand the
 * signed-in user and the browser-made ciphertext to the service, and each
 * service refusal maps to its own HTTP status.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Kernel\Contracts\FederatedShareServiceInterface;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Keepiq\Controller\FederatedShareController
 * @uses   \OCA\Keepiq\Exception\PartnerUpgradeRequiredException
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 * @uses   \OCA\Keepiq\Exception\NotFoundException
 */
class FederatedShareControllerTest extends TestCase {

	/**
	 * The mocked federated share service.
	 *
	 * @var FederatedShareServiceInterface&MockObject
	 */
	private FederatedShareServiceInterface&MockObject $shares;

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->shares = $this->createMock(FederatedShareServiceInterface::class);
		$this->request = $this->createMock(IRequest::class);
	}//end setUp()

	/**
	 * The controller under test, signed in as alice.
	 *
	 * @return FederatedShareController
	 */
	private function controller(): FederatedShareController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederatedShareController(
			request: $this->request,
			shares: $this->shares,
			userSession: $session,
		);
	}//end controller()

	/**
	 * A share that serializes to the given array.
	 *
	 * @param array<string,mixed> $data The serialized form
	 *
	 * @return FederatedShare&MockObject
	 */
	private function share(array $data): FederatedShare&MockObject {
		$share = $this->createMock(FederatedShare::class);
		$share->method('jsonSerialize')->willReturn($data);
		return $share;
	}//end share()

	/**
	 * Create passes the owner, recipient, fingerprint and every ciphertext field and answers 201.
	 *
	 * @return void
	 */
	public function testCreatePassesTheCiphertextAndAnswers201(): void {
		$this->shares->expects($this->once())
			->method('create')
			->with(
				's-1',
				'alice',
				'bob@partner.example',
				'FP',
				[
					'key' => 'K',
					'login' => 'L',
					'additionalFields' => 'A',
					'totp' => 'T',
					'passkeys' => 'P',
				]
			)
			->willReturn($this->share(['id' => 'fs-1']));

		$response = $this->controller()->create(
			secretId: 's-1',
			recipientCloudId: 'bob@partner.example',
			certFingerprint: 'FP',
			key: 'K',
			login: 'L',
			additionalFields: 'A',
			totp: 'T',
			passkeys: 'P',
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'fs-1'], $response->getData());
	}//end testCreatePassesTheCiphertextAndAnswers201()

	/**
	 * An invalid share answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testCreateAnswers400OnAnInvalidShare(): void {
		foreach (['not_a_partner', 'already_shared'] as $reason) {
			$this->shares = $this->createMock(FederatedShareServiceInterface::class);
			$this->shares->method('create')->willThrowException(new InvalidArgumentException($reason));

			$response = $this->controller()->create(secretId: 's-1', recipientCloudId: 'bob@x', key: 'K');

			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $reason);
			$this->assertSame(['message' => $reason], $response->getData(), $reason);
		}
	}//end testCreateAnswers400OnAnInvalidShare()

	/**
	 * A recipient host whose outbound sharing is off answers 403 with its reason, as the certificate lookup does.
	 *
	 * @return void
	 */
	public function testCreateAnswers403WhenOutboundSharingIsOff(): void {
		$this->shares->method('create')->willThrowException(new InvalidArgumentException('outbound_disabled'));

		$response = $this->controller()->create(secretId: 's-1', recipientCloudId: 'bob@x', key: 'K');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'outbound_disabled'], $response->getData());
	}//end testCreateAnswers403WhenOutboundSharingIsOff()

	/**
	 * A partner too old for the share answers 409 with the code and version, without a share id.
	 *
	 * @return void
	 */
	public function testCreateAnswers409WhenThePartnerMustUpgrade(): void {
		$exception = new PartnerUpgradeRequiredException(requiredVersion: '9.9.9');
		$this->shares->method('create')->willThrowException($exception);

		$response = $this->controller()->create(secretId: 's-1', recipientCloudId: 'bob@x', key: 'K');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(
			[
				'error' => 'partner_upgrade_required',
				'message' => $exception->getMessage(),
				'requiredVersion' => '9.9.9',
			],
			$response->getData()
		);
	}//end testCreateAnswers409WhenThePartnerMustUpgrade()

	/**
	 * Each runtime refusal maps to its status: not found 404, forbidden 403,
	 * federation unavailable 409 and any other failure 502.
	 *
	 * @return void
	 */
	public function testCreateMapsEachRuntimeRefusalToItsStatus(): void {
		$cases = [
			[new NotFoundException('gone'), Http::STATUS_NOT_FOUND],
			[new ForbiddenException('read_only'), Http::STATUS_FORBIDDEN],
			[new RuntimeException('federation_unavailable'), Http::STATUS_CONFLICT],
			[new RuntimeException('delivery_failed'), Http::STATUS_BAD_GATEWAY],
		];

		foreach ($cases as [$exception, $status]) {
			$this->shares = $this->createMock(FederatedShareServiceInterface::class);
			$this->shares->method('create')->willThrowException($exception);

			$response = $this->controller()->create(secretId: 's-1', recipientCloudId: 'bob@x', key: 'K');

			$this->assertSame($status, $response->getStatus(), $exception->getMessage());
			$this->assertSame(['message' => $exception->getMessage()], $response->getData());
		}
	}//end testCreateMapsEachRuntimeRefusalToItsStatus()

	/**
	 * The list serializes every share of the owner's secret.
	 *
	 * @return void
	 */
	public function testIndexListsTheSharesOfTheSecret(): void {
		$this->shares->expects($this->once())
			->method('listForSecret')
			->with('s-1', 'alice')
			->willReturn([$this->share(['id' => 'fs-1']), $this->share(['id' => 'fs-2'])]);

		$response = $this->controller()->index(secretId: 's-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['id' => 'fs-1'], ['id' => 'fs-2']], $response->getData());
	}//end testIndexListsTheSharesOfTheSecret()

	/**
	 * A secret that is not the user's own answers 404 on the list.
	 *
	 * @return void
	 */
	public function testIndexAnswers404ForASecretThatIsNotTheUsers(): void {
		$this->shares->method('listForSecret')->willThrowException(new NotFoundException('secret_not_found'));

		$response = $this->controller()->index(secretId: 's-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'secret_not_found'], $response->getData());
	}//end testIndexAnswers404ForASecretThatIsNotTheUsers()

	/**
	 * `PUT /federated-shares/{id}` is retired: a partner share's ciphertext
	 * changes only through its owner's share-save, in the same transaction as
	 * the owner's row. Revoke and suspend stay.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/tasks.md#3.3
	 *
	 * @return void
	 */
	public function testTheUpdateEndpointIsRetired(): void {
		$this->assertFalse(condition: method_exists(FederatedShareController::class, 'update'), message: 'federatedShare#update must be retired');
		$this->assertTrue(condition: method_exists(FederatedShareController::class, 'destroy'));
		$this->assertTrue(condition: method_exists(FederatedShareController::class, 'suspend'));
	}//end testTheUpdateEndpointIsRetired()

	/**
	 * Revoking asks the service for the owner's share and echoes its id.
	 *
	 * @return void
	 */
	public function testDestroyRevokesTheShare(): void {
		$this->shares->expects($this->once())->method('revoke')->with('fs-1', 'alice');

		$response = $this->controller()->destroy(id: 'fs-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['revoked' => 'fs-1'], $response->getData());
	}//end testDestroyRevokesTheShare()

	/**
	 * A share that is not the owner's answers 404 on revoke.
	 *
	 * @return void
	 */
	public function testDestroyAnswers404ForAnUnknownShare(): void {
		$this->shares->method('revoke')->willThrowException(new NotFoundException('share_not_found'));

		$response = $this->controller()->destroy(id: 'fs-9');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'share_not_found'], $response->getData());
	}//end testDestroyAnswers404ForAnUnknownShare()

	/**
	 * Suspending passes the verifier's reason and returns the suspended share.
	 *
	 * @return void
	 */
	public function testSuspendPassesTheReason(): void {
		$this->shares->expects($this->once())
			->method('suspend')
			->with('fs-1', 'alice', 'cert_mismatch')
			->willReturn($this->share(['id' => 'fs-1', 'status' => 'suspended']));

		$response = $this->controller()->suspend(id: 'fs-1', reason: 'cert_mismatch');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['id' => 'fs-1', 'status' => 'suspended'], $response->getData());
	}//end testSuspendPassesTheReason()

	/**
	 * A suspend the partner did not take answers 502.
	 *
	 * @return void
	 */
	public function testSuspendAnswers502WhenDeliveryFails(): void {
		$this->shares->method('suspend')->willThrowException(new RuntimeException('delivery_failed'));

		$response = $this->controller()->suspend(id: 'fs-1');

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame(['message' => 'delivery_failed'], $response->getData());
	}//end testSuspendAnswers502WhenDeliveryFails()
}//end class
