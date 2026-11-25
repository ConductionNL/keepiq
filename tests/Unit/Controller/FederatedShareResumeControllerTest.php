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
	private FederatedShareResume&MockObject $resumes;

	/**
	 * Fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->resumes = $this->createMock(FederatedShareResume::class);
	}//end setUp()

	/**
	 * The controller, signed in as alice or as nobody.
	 *
	 * @param bool $signedIn Whether a user is signed in
	 *
	 * @return FederatedShareResumeController
	 */
	private function controller(bool $signedIn = true): FederatedShareResumeController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($signedIn === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
		}
		$session->method('getUser')->willReturn($user);

		return new FederatedShareResumeController(
			request: $this->createMock(IRequest::class),
			resumes: $this->resumes,
			userSession: $session,
		);
	}//end controller()

	/**
	 * The resumed share comes back with its new status, and the request's
	 * fields reach the service as they were sent.
	 *
	 * @return void
	 */
	public function testAResumedShareIsAnswered(): void {
		$row = new FederatedShare();
		$row->setId('s-1');
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$this->resumes->expects($this->once())->method('resume')->with(
			's-1',
			'alice',
			'fp',
			['key' => 'k', 'login' => 'l', 'additionalFields' => null, 'totp' => null, 'passkeys' => null],
			'2026-11-25T10:00:00+00:00',
		)->willReturn($row);

		$response = $this->controller()->resume(
			id: 's-1',
			certFingerprint: 'fp',
			sourceUpdatedAt: '2026-11-25T10:00:00+00:00',
			key: 'k',
			login: 'l',
		);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('active', $response->getData()['status']);
	}//end testAResumedShareIsAnswered()

	/**
	 * Nobody signed in, nothing resumed.
	 *
	 * @return void
	 */
	public function testASignedOutCallerIsRefused(): void {
		$this->resumes->expects($this->never())->method('resume');

		$this->assertSame(401, $this->controller(signedIn: false)->resume(id: 's-1')->getStatus());
	}//end testASignedOutCallerIsRefused()

	/**
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
		$this->resumes->method('resume')->willThrowException($refusal);

		$response = $this->controller()->resume(id: 's-1', sourceUpdatedAt: '2026-11-25T10:00:00+00:00', key: 'k');

		$this->assertSame($status, $response->getStatus());
		$this->assertSame($body, $response->getData());
	}//end testARefusalKeepsItsStatus()

	/**
	 * A partner too old for a field the login holds is answered as the
	 * share routes answer it, so the web app shows the same upgrade text.
	 *
	 * @return void
	 */
	public function testAPartnerTooOldIsAnsweredAsUpgradeRequired(): void {
		$this->resumes->method('resume')->willThrowException(new PartnerUpgradeRequiredException());

		$response = $this->controller()->resume(id: 's-1', sourceUpdatedAt: '2026-11-25T10:00:00+00:00', key: 'k');

		$this->assertSame(409, $response->getStatus());
		$this->assertSame(PartnerUpgradeRequiredException::CODE, $response->getData()['error']);
		$this->assertSame('s-1', $response->getData()['shareId']);
	}//end testAPartnerTooOldIsAnsweredAsUpgradeRequired()
}//end class
