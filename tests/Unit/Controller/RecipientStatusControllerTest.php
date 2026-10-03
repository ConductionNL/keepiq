<?php

/**
 * Keepiq Recipient Status Controller Test
 *
 * The share dialog's recipient marks (keepiq#37), through the controller and
 * the REAL RecipientStatusService. Only Nextcloud's sharee search and the
 * suite lookup are doubled. The refusal: a user the caller's sharee search
 * does not return is never looked up and never answered about, even when
 * that user holds a vault.
 *
 * @category Tests
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
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\RecipientStatusController;
use OCA\Keepiq\Service\RecipientStatusService;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecipientStatusControllerTest extends TestCase {
	private ISearch&MockObject $search;
	private ShareService&MockObject $shareService;
	private IUserSession&MockObject $userSession;
	private RecipientStatusController $controller;

	protected function setUp(): void {
		parent::setUp();
		$this->search = $this->createMock(ISearch::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('owner');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new RecipientStatusController(
			$this->createMock(IRequest::class),
			new RecipientStatusService($this->search, $this->shareService),
			$this->userSession,
		);
	}

	/**
	 * A sharee search answer in Nextcloud's shape.
	 *
	 * @param array<int,string> $exact Exact-match user ids
	 * @param array<int,string> $users Other user ids
	 *
	 * @return array{0: array<string,mixed>, 1: bool}
	 */
	private function searchResult(array $exact, array $users): array {
		$row = static fn (string $id): array => [
			'label' => ucfirst($id),
			'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $id],
		];

		return [
			[
				'exact' => ['users' => array_map($row, $exact)],
				'users' => array_map($row, $users),
			],
			false,
		];
	}

	public function testMarksFoundUsersByWhetherTheyHoldAVault(): void {
		$this->search->expects($this->once())
			->method('search')
			->with('al', [IShare::TYPE_USER], false, RecipientStatusService::MAX_USERS, 0)
			->willReturn($this->searchResult(['al'], ['alice', 'albert']));
		$this->shareService->method('recipientCertificates')
			->willReturn(['al' => 'PEM-al', 'alice' => 'PEM-alice']);

		$response = $this->controller->status('al', ['alice', 'albert', 'al']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'recipients' => [
					['userId' => 'alice', 'hasSuite' => true],
					['userId' => 'albert', 'hasSuite' => false],
					['userId' => 'al', 'hasSuite' => true],
				],
			],
			$response->getData()
		);
		$this->assertStringNotContainsString('PEM', json_encode($response->getData()));
	}

	/**
	 * The refusal: "carol" holds a vault, but the caller's sharee search for
	 * the term does not return her (another group, enumeration off, or simply
	 * not matching). She must not be looked up or answered about.
	 */
	public function testAnswersNothingForUsersTheSearchDoesNotReturn(): void {
		$this->search->method('search')
			->willReturn($this->searchResult([], ['alice']));
		$this->shareService->expects($this->once())
			->method('recipientCertificates')
			->with(['alice'])
			->willReturn(['alice' => 'PEM-alice']);

		$response = $this->controller->status('al', ['alice', 'carol']);

		$this->assertSame(
			['recipients' => [['userId' => 'alice', 'hasSuite' => true]]],
			$response->getData()
		);
	}

	public function testAnswersNothingAtAllWhenTheSearchFindsNoneOfThem(): void {
		$this->search->method('search')
			->willReturn($this->searchResult([], []));
		$this->shareService->expects($this->never())->method('recipientCertificates');

		$response = $this->controller->status('', ['carol', 'dave']);

		$this->assertSame(['recipients' => []], $response->getData());
	}

	public function testRefusesMoreThanOnePageOfUsers(): void {
		$this->search->expects($this->never())->method('search');
		$ids = array_map(static fn (int $i): string => 'user' . $i, range(1, RecipientStatusService::MAX_USERS + 1));

		$response = $this->controller->status('user', $ids);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCountsDistinctUsersAndDropsMalformedIds(): void {
		$this->search->method('search')
			->willReturn($this->searchResult([], ['alice']));
		$this->shareService->method('recipientCertificates')->willReturn([]);
		$ids = array_merge(array_fill(0, 40, 'alice'), ['', 7, null]);

		$response = $this->controller->status('al', $ids);

		$this->assertSame(
			['recipients' => [['userId' => 'alice', 'hasSuite' => false]]],
			$response->getData()
		);
	}

	public function testRefusesWithoutASession(): void {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$controller = new RecipientStatusController(
			$this->createMock(IRequest::class),
			new RecipientStatusService($this->search, $this->shareService),
			$session,
		);
		$this->search->expects($this->never())->method('search');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->status('al', ['alice'])->getStatus());
	}
}
