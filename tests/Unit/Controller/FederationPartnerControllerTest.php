<?php

/**
 * Outcome tests for FederationPartnerController.
 *
 * Adding and previewing partners is refused with 409 on a server that cannot
 * federate; the service's refusals answer 400 or 404; removing a partner
 * suspends the shares to it.
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
use OCA\Keepiq\Controller\FederationPartnerController;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Keepiq\Controller\FederationPartnerController
 */
class FederationPartnerControllerTest extends TestCase {

	/**
	 * The mocked partner service.
	 *
	 * @var FederationPartnerService&MockObject
	 */
	private FederationPartnerService&MockObject $partners;

	/**
	 * The mocked root service.
	 *
	 * @var FederationRootService&MockObject
	 */
	private FederationRootService&MockObject $root;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * The mocked federated share service.
	 *
	 * @var FederatedShareService&MockObject
	 */
	private FederatedShareService&MockObject $shares;

	/**
	 * Build fresh mocks for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->partners = $this->createMock(FederationPartnerService::class);
		$this->root = $this->createMock(FederationRootService::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->shares = $this->createMock(FederatedShareService::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return FederationPartnerController
	 */
	private function controller(): FederationPartnerController {
		return new FederationPartnerController(
			request: $this->createMock(IRequest::class),
			partners: $this->partners,
			root: $this->root,
			userSession: $this->session,
			federatedShares: $this->shares,
		);
	}//end controller()

	/**
	 * A partner that serializes to the given array.
	 *
	 * @param array<string,mixed> $data The serialized form
	 *
	 * @return FederationPartner&MockObject
	 */
	private function partner(array $data): FederationPartner&MockObject {
		$partner = $this->createMock(FederationPartner::class);
		$partner->method('jsonSerialize')->willReturn($data);
		return $partner;
	}//end partner()

	/**
	 * The index reports support, the local fingerprint and every partner.
	 *
	 * @return void
	 */
	public function testIndexReportsSupportFingerprintAndPartners(): void {
		$this->root->method('isSupported')->willReturn(true);
		$this->root->method('localRootFingerprint')->willReturn('sha256:local');
		$this->partners->method('all')->willReturn([$this->partner(['id' => 'p-1']), $this->partner(['id' => 'p-2'])]);

		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['supported' => true, 'localRootFingerprint' => 'sha256:local', 'partners' => [['id' => 'p-1'], ['id' => 'p-2']]],
			$response->getData()
		);
	}//end testIndexReportsSupportFingerprintAndPartners()

	/**
	 * Preview and create answer 409 on a server that cannot federate, before
	 * the partner service is asked anything.
	 *
	 * @return void
	 */
	public function testPreviewAndCreateAnswer409WhenFederationIsUnsupported(): void {
		$this->root->method('isSupported')->willReturn(false);
		$this->partners->expects($this->never())->method('preview');
		$this->partners->expects($this->never())->method('add');

		foreach ([
			$this->controller()->preview(url: 'https://partner.example'),
			$this->controller()->create(url: 'https://partner.example', rootFingerprint: 'sha256:x'),
		] as $response) {
			$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
			$this->assertSame(['message' => 'Federation needs Nextcloud 33 or later'], $response->getData());
		}
	}//end testPreviewAndCreateAnswer409WhenFederationIsUnsupported()

	/**
	 * A preview answers the service's preview, or 400 with its refusal.
	 *
	 * @return void
	 */
	public function testPreviewAnswersThePreviewOrA400(): void {
		$this->root->method('isSupported')->willReturn(true);
		$this->partners->expects($this->exactly(2))->method('preview')
			->with('https://partner.example')
			->willReturnOnConsecutiveCalls(
				['name' => 'Partner', 'rootFingerprint' => 'sha256:p'],
				$this->throwException(new InvalidArgumentException('Unreachable')),
			);

		$ok = $this->controller()->preview(url: 'https://partner.example');
		$bad = $this->controller()->preview(url: 'https://partner.example');

		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame(['name' => 'Partner', 'rootFingerprint' => 'sha256:p'], $ok->getData());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $bad->getStatus());
		$this->assertSame(['message' => 'Unreachable'], $bad->getData());
	}//end testPreviewAnswersThePreviewOrA400()

	/**
	 * Create passes the confirmed fingerprint, the directions (null meaning
	 * off) and the session admin, and answers 201.
	 *
	 * @return void
	 */
	public function testCreatePassesTheFieldsAndTheAdminAndAnswers201(): void {
		$this->root->method('isSupported')->willReturn(true);
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($admin);
		$this->partners->expects($this->once())->method('add')
			->with('https://partner.example', 'sha256:p', true, false, 'admin')
			->willReturn($this->partner(['id' => 'p-1']));

		$response = $this->controller()->create(
			url: 'https://partner.example',
			rootFingerprint: 'sha256:p',
			allowOutbound: true,
			allowInbound: null,
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(['id' => 'p-1'], $response->getData());
	}//end testCreatePassesTheFieldsAndTheAdminAndAnswers201()

	/**
	 * A refused create answers 400 with the service's message.
	 *
	 * @return void
	 */
	public function testARefusedCreateAnswers400(): void {
		$this->root->method('isSupported')->willReturn(true);
		$this->partners->method('add')->willThrowException(new InvalidArgumentException('Fingerprint mismatch'));

		$response = $this->controller()->create(url: 'https://partner.example', rootFingerprint: 'sha256:x');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Fingerprint mismatch'], $response->getData());
	}//end testARefusedCreateAnswers400()

	/**
	 * Update passes both directions and answers the partner, or 404.
	 *
	 * @return void
	 */
	public function testUpdateAnswersThePartnerOrA404(): void {
		$this->partners->expects($this->exactly(2))->method('update')
			->with('p-1', false, true)
			->willReturnOnConsecutiveCalls(
				$this->partner(['id' => 'p-1', 'allowInbound' => true]),
				$this->throwException(new DoesNotExistException('gone')),
			);

		$ok = $this->controller()->update(id: 'p-1', allowOutbound: null, allowInbound: true);
		$missing = $this->controller()->update(id: 'p-1', allowOutbound: false, allowInbound: true);

		$this->assertSame(Http::STATUS_OK, $ok->getStatus());
		$this->assertSame(['id' => 'p-1', 'allowInbound' => true], $ok->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Not found'], $missing->getData());
	}//end testUpdateAnswersThePartnerOrA404()

	/**
	 * A re-pin passes the confirmed fingerprint and the administrator, and answers the partner with whether its pin
	 * changed.
	 *
	 * @return void
	 */
	public function testRepinAnswersThePartnerAndWhetherItChanged(): void {
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$this->session->method('getUser')->willReturn($admin);
		$this->root->method('isSupported')->willReturn(true);
		$this->partners->expects($this->exactly(2))->method('repin')
			->with('p-1', 'sha256:new', 'admin')
			->willReturnOnConsecutiveCalls(
				['partner' => $this->partner(['id' => 'p-1']), 'changed' => true],
				['partner' => $this->partner(['id' => 'p-1']), 'changed' => false],
			);

		$changed = $this->controller()->repin(id: 'p-1', fingerprint: 'sha256:new');
		$unchanged = $this->controller()->repin(id: 'p-1', fingerprint: 'sha256:new');

		$this->assertSame(Http::STATUS_OK, $changed->getStatus());
		$this->assertSame(['partner' => ['id' => 'p-1'], 'changed' => true], $changed->getData());
		$this->assertSame(['partner' => ['id' => 'p-1'], 'changed' => false], $unchanged->getData());
	}//end testRepinAnswersThePartnerAndWhetherItChanged()

	/**
	 * A refused re-pin answers 400 with the service's message, an unknown partner 404.
	 *
	 * @return void
	 */
	public function testARefusedRepinAnswers400AndAnUnknownPartner404(): void {
		$this->root->method('isSupported')->willReturn(true);
		$this->partners->expects($this->exactly(2))->method('repin')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new InvalidArgumentException('The partner root fingerprint is not the one you confirmed')),
				$this->throwException(new DoesNotExistException('gone')),
			);

		$refused = $this->controller()->repin(id: 'p-1', fingerprint: 'sha256:x');
		$missing = $this->controller()->repin(id: 'p-2', fingerprint: 'sha256:x');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'The partner root fingerprint is not the one you confirmed'], $refused->getData());
		$this->assertSame(Http::STATUS_NOT_FOUND, $missing->getStatus());
		$this->assertSame(['message' => 'Not found'], $missing->getData());
	}//end testARefusedRepinAnswers400AndAnUnknownPartner404()

	/**
	 * Below Nextcloud 33 a re-pin answers 409 before the partner service is asked.
	 *
	 * @return void
	 */
	public function testRepinAnswers409WhenFederationIsUnsupported(): void {
		$this->root->method('isSupported')->willReturn(false);
		$this->partners->expects($this->never())->method('repin');

		$response = $this->controller()->repin(id: 'p-1', fingerprint: 'sha256:x');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testRepinAnswers409WhenFederationIsUnsupported()

	/**
	 * Removing a partner suspends the shares to it.
	 *
	 * @return void
	 */
	public function testDestroyRemovesThePartnerAndSuspendsItsShares(): void {
		$this->partners->expects($this->once())->method('remove')->with('p-1');
		$this->shares->expects($this->once())->method('suspendForPartner')->with('p-1')->willReturn(2);

		$response = $this->controller()->destroy(id: 'p-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['removed' => 'p-1'], $response->getData());
	}//end testDestroyRemovesThePartnerAndSuspendsItsShares()

	/**
	 * Removing an unknown partner answers 404 and suspends nothing.
	 *
	 * @return void
	 */
	public function testDestroyingAnUnknownPartnerSuspendsNothing(): void {
		$this->partners->method('remove')->willThrowException(new DoesNotExistException('gone'));
		$this->shares->expects($this->never())->method('suspendForPartner');

		$response = $this->controller()->destroy(id: 'p-1');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testDestroyingAnUnknownPartnerSuspendsNothing()
}//end class
