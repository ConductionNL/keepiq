<?php

/**
 * Unit tests for FederationController::recipientCertificates().
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
use OCA\Keepiq\Controller\FederationController;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCA\Keepiq\Service\PartnerCertificateBatchService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The batch route is the caller's: anonymous is 401, an empty list 400, more than the bound 400, and the
 * service's answer goes out as is.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
 */
#[CoversClass(FederationController::class)]
class FederationControllerBatchLookupTest extends TestCase {
	/** @var PartnerCertificateBatchService&MockObject */
	private PartnerCertificateBatchService $batch;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->batch = $this->createMock(originalClassName: PartnerCertificateBatchService::class);
	}//end setUp()

	/**
	 * The controller over the given session user, or none.
	 *
	 * @param string|null $uid The signed-in user id
	 *
	 * @return FederationController
	 */
	private function controller(?string $uid): FederationController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createStub(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createStub(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederationController(
			request: $this->createStub(originalClassName: IRequest::class),
			certificates: $this->createStub(originalClassName: FederatedCertificateServiceInterface::class),
			userSession: $session,
			batch: $this->batch,
		);
	}//end controller()

	/**
	 * An anonymous batch is 401 and nothing is looked up.
	 *
	 * @return void
	 */
	public function testWithoutAUserIsUnauthorized(): void {
		$this->batch->expects($this->never())->method('lookup');

		$response = $this->controller(uid: null)->recipientCertificates(shareIds: ['s1']);

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testWithoutAUserIsUnauthorized()

	/**
	 * A list with no usable id is 400.
	 *
	 * @return void
	 */
	public function testAnEmptyListIsABadRequest(): void {
		$this->batch->expects($this->never())->method('lookup');

		$response = $this->controller(uid: 'alice')->recipientCertificates(shareIds: ['', 42]);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'shareIds_required'], actual: $response->getData());
	}//end testAnEmptyListIsABadRequest()

	/**
	 * The service's answer is returned for the signed-in user, with only the string ids.
	 *
	 * @return void
	 */
	public function testTheAnswerIsTheServicesForTheCaller(): void {
		$answer = [
			'certificates' => ['s1' => ['cloudId' => 'bob@a.example']],
			'refused'      => ['s2' => ['status' => 404, 'message' => 'unknown_share']],
		];
		$this->batch->expects($this->once())
			->method('lookup')
			->with(['s1', 's2'], 'alice')
			->willReturn($answer);

		$response = $this->controller(uid: 'alice')->recipientCertificates(shareIds: ['s1', null, 's2']);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: json_encode(value: $answer), actual: json_encode(value: $response->getData()));
	}//end testTheAnswerIsTheServicesForTheCaller()

	/**
	 * An answer with nothing refused still sends `refused` as a map, not a list.
	 *
	 * @return void
	 */
	public function testEmptyListsGoOutAsMaps(): void {
		$this->batch->method('lookup')->willReturn(['certificates' => ['s1' => ['cloudId' => 'bob@a.example']], 'refused' => []]);

		$response = $this->controller(uid: 'alice')->recipientCertificates(shareIds: ['s1']);

		$this->assertSame(
			expected: '{"certificates":{"s1":{"cloudId":"bob@a.example"}},"refused":{}}',
			actual: json_encode(value: $response->getData())
		);
	}//end testEmptyListsGoOutAsMaps()

	/**
	 * More than the bound is 400 with the service's reason.
	 *
	 * @return void
	 */
	public function testMoreThanTheBoundIsABadRequest(): void {
		$this->batch->method('lookup')->willThrowException(new InvalidArgumentException('too_many_shares'));

		$response = $this->controller(uid: 'alice')->recipientCertificates(shareIds: ['s1']);

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'too_many_shares'], actual: $response->getData());
	}//end testMoreThanTheBoundIsABadRequest()
}//end class
