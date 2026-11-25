<?php

/**
 * `POST /api/v1/federated-shares/{id}/resume` answers each outcome of a
 * resume with its status (sharing-atomic-share-save task 3.4).
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
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\FederatedShareResumeController;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Service\FederatedShareResume;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;

class FederatedShareResumeControllerTest extends TestCase {
	/**
	 * The resume behind the route.
	 *
	 * @var FederatedShareResume&MockObject
	 */
	private FederatedShareResume&MockObject $resumes;

	/**
	 * The request body as Nextcloud binds it.
	 *
	 * @var array<string,mixed>
	 */
	private array $params = [];

	/**
	 * Fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resumes = $this->createMock(originalClassName: FederatedShareResume::class);
	}//end setUp()

	/**
	 * The controller, signed in as alice or as nobody.
	 *
	 * @param bool $signedIn Whether a user is signed in
	 *
	 * @return FederatedShareResumeController
	 */
	private function controller(bool $signedIn = true): FederatedShareResumeController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn(value: 'alice');
		}

		$session->method('getUser')->willReturn(value: $user);
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParams')->willReturnCallback(callback: fn (): array => $this->params);

		return new FederatedShareResumeController(request: $request, resumes: $this->resumes, userSession: $session);
	}//end controller()

	/**
	 * The resumed share comes back with its new status, and the request body
	 * reaches the service as it was sent.
	 *
	 * @return void
	 */
	public function testAResumedShareIsAnswered(): void {
		$this->params = ['id' => 's-1', 'certFingerprint' => 'fp', 'sourceUpdatedAt' => '2026-11-25T10:00:00+00:00', 'key' => 'k'];
		$row = new FederatedShare();
		$row->setId('s-1');
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$this->resumes->expects($this->once())->method('resume')->willReturnCallback(
			callback: function (string $shareId, string $userId, array $body) use ($row): FederatedShare {
				$this->assertSame(expected: ['s-1', 'alice', $this->params], actual: [$shareId, $userId, $body]);
				return $row;
			}
		);

		$response = $this->controller()->resume(id: 's-1');

		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: 'active', actual: $response->getData()['status']);
	}//end testAResumedShareIsAnswered()

	/**
	 * Nobody signed in, nothing resumed.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsRefused(): void {
		$this->resumes->expects($this->never())->method('resume');

		$this->assertSame(expected: 401, actual: $this->controller(signedIn: false)->resume(id: 's-1')->getStatus());
	}//end testASignedOutCallerIsRefused()

	/**
	 * The refusals and how they are answered.
	 *
	 * @return array<string,array{0:Throwable,1:int,2:array<string,mixed>}>
	 */
	public static function refusals(): array {
		return [
			'not the caller\'s suspended share' => [new NotFoundException(message: 'Share not found'), 404, ['message' => 'Share not found']],
			'invalid' => [new InvalidArgumentException('invalid'), 400, ['message' => 'invalid']],
			'stale' => [
				new ShareSaveRefusal(error: 'stale_source', message: 'older', status: 409, details: ['sourceUpdatedAt' => '2026-11-25T10:00:00+00:00']),
				409,
				['error' => 'stale_source', 'message' => 'older', 'sourceUpdatedAt' => '2026-11-25T10:00:00+00:00'],
			],
		];
	}//end refusals()

	/**
	 * Each refusal keeps its own status and body.
	 *
	 * @param Throwable $refusal What the service threw
	 * @param int $status The expected status
	 * @param array<string,mixed> $body The expected body
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testARefusalKeepsItsStatus(Throwable $refusal, int $status, array $body): void {
		$this->resumes->method('resume')->willThrowException(exception: $refusal);

		$response = $this->controller()->resume(id: 's-1');

		$this->assertSame(expected: [$status, $body], actual: [$response->getStatus(), $response->getData()]);
	}//end testARefusalKeepsItsStatus()

	/**
	 * A partner too old for a field the login holds is answered as the share
	 * routes answer it, so the web app shows the same upgrade text.
	 *
	 * @return void
	 */
	public function testAPartnerTooOldIsAnsweredAsUpgradeRequired(): void {
		$this->resumes->method('resume')->willThrowException(exception: new PartnerUpgradeRequiredException());

		$response = $this->controller()->resume(id: 's-1');

		$this->assertSame(
			expected: [409, PartnerUpgradeRequiredException::CODE, 's-1'],
			actual: [$response->getStatus(), $response->getData()['error'], $response->getData()['shareId']]
		);
	}//end testAPartnerTooOldIsAnsweredAsUpgradeRequired()
}//end class
