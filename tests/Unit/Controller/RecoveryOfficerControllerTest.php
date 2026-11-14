<?php

/**
 * Unit tests for RecoveryOfficerController, the recovery officer's routes.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\RecoveryOfficerController;
use OCA\Keepiq\Db\RecoveryKey;
use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\RecoveryKeyService;
use OCA\Keepiq\Service\RecoveryPolicyService;
use OCA\Keepiq\Service\RecoveryRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Every officer route refuses an anonymous caller with a 401 before any
 * service is asked; a signed-in caller's uid is what reaches the services,
 * and their refusals map to 403 (not an officer), 404 (not for you) and 400
 * (malformed body).
 *
 * @covers \OCA\Keepiq\Controller\RecoveryOfficerController
 * @uses   \OCA\Keepiq\Db\RecoveryKey
 * @uses   \OCA\Keepiq\Db\RecoveryOfficer
 */
class RecoveryOfficerControllerTest extends TestCase {

	/**
	 * The mocked officer policy.
	 *
	 * @var RecoveryPolicyService&MockObject
	 */
	private RecoveryPolicyService&MockObject $policy;

	/**
	 * The mocked recovery keys.
	 *
	 * @var RecoveryKeyService&MockObject
	 */
	private RecoveryKeyService&MockObject $keys;

	/**
	 * The mocked recovery requests.
	 *
	 * @var RecoveryRequestService&MockObject
	 */
	private RecoveryRequestService&MockObject $requests;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policy = $this->createMock(originalClassName: RecoveryPolicyService::class);
		$this->keys = $this->createMock(originalClassName: RecoveryKeyService::class);
		$this->requests = $this->createMock(originalClassName: RecoveryRequestService::class);
	}//end setUp()

	/**
	 * The controller, signed in as $uid (or anonymous for null).
	 *
	 * @param string|null $uid The signed-in uid.
	 *
	 * @return RecoveryOfficerController
	 */
	private function controller(?string $uid = 'olga'): RecoveryOfficerController {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new RecoveryOfficerController(
			request: $this->createMock(originalClassName: IRequest::class),
			policy: $this->policy,
			keys: $this->keys,
			requests: $this->requests,
			userSession: $session,
		);
	}//end controller()

	/**
	 * Each route, called anonymously.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public static function anonymousCalls(): array {
		return [
			'overview' => [static fn (RecoveryOfficerController $c) => $c->overview()],
			'createKey' => [static fn (RecoveryOfficerController $c) => $c->createKey(publicKey: 'PUBLIC', copies: ['olga' => 'A'])],
			'ownCopy' => [static fn (RecoveryOfficerController $c) => $c->ownCopy(keyId: 'key-1')],
			'replaceOwnCopy' => [static fn (RecoveryOfficerController $c) => $c->replaceOwnCopy(keyId: 'key-1', wrappedPrivateKey: 'W')],
			'approve' => [static fn (RecoveryOfficerController $c) => $c->approve(id: 'req-1')],
			'decline' => [static fn (RecoveryOfficerController $c) => $c->decline(id: 'req-1')],
			'handoff' => [static fn (RecoveryOfficerController $c) => $c->handoff(id: 'req-1')],
			'postSealed' => [static fn (RecoveryOfficerController $c) => $c->postSealed(id: 'req-1', sealedResult: 'S')],
		];
	}//end anonymousCalls()

	/**
	 * An anonymous caller gets 401 and no service is asked anything.
	 *
	 * @param callable $call The route call.
	 *
	 * @return void
	 *
	 * @dataProvider anonymousCalls
	 */
	public function testAnAnonymousCallerIsRefusedBeforeAnyService(callable $call): void {
		$this->policy->expects($this->never())->method($this->anything());
		$this->keys->expects($this->never())->method($this->anything());
		$this->requests->expects($this->never())->method($this->anything());

		$response = $call($this->controller(uid: null));

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Unauthorized'], actual: $response->getData());
	}//end testAnAnonymousCallerIsRefusedBeforeAnyService()

	/**
	 * A caller who is not an officer learns only that, and the officers, key
	 * and requests are never read.
	 *
	 * @return void
	 */
	public function testOverviewForANonOfficerRevealsNothing(): void {
		$this->policy->expects($this->once())->method('isOfficer')->with('mallory')->willReturn(false);
		$this->policy->expects($this->never())->method('officers');
		$this->keys->expects($this->never())->method('publicInfo');
		$this->requests->expects($this->never())->method('forOfficer');

		$response = $this->controller(uid: 'mallory')->overview();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['officer' => false], actual: $response->getData());
	}//end testOverviewForANonOfficerRevealsNothing()

	/**
	 * An officer sees the officers, the threshold, the active key and the
	 * requests open to them.
	 *
	 * @return void
	 */
	public function testOverviewForAnOfficerCarriesTheWholeView(): void {
		$key = ['id' => 'key-1', 'certificate' => 'CERT', 'fingerprint' => 'FP', 'caChain' => []];
		$open = [['id' => 'req-1', 'status' => 'open']];
		$this->policy->method('isOfficer')->with('olga')->willReturn(true);
		$this->policy->method('officers')->willReturn(['olga', 'omar']);
		$this->policy->method('threshold')->willReturn(2);
		$this->keys->method('publicInfo')->willReturn($key);
		$this->requests->expects($this->once())->method('forOfficer')->with('olga')->willReturn($open);

		$response = $this->controller()->overview();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: ['officer' => true, 'officers' => ['olga', 'omar'], 'threshold' => 2, 'key' => $key, 'requests' => $open],
			actual: $response->getData()
		);
	}//end testOverviewForAnOfficerCarriesTheWholeView()

	/**
	 * Creating a key forwards the officer, the public key and the copies, and
	 * answers 201 with the stored key.
	 *
	 * @return void
	 */
	public function testCreateKeyAnswersCreatedWithTheStoredKey(): void {
		$key = new RecoveryKey();
		$key->setId('key-1');
		$key->setFingerprint('FP');
		$key->setStatus('active');
		$this->keys->expects($this->once())
			->method('createKey')
			->with('olga', 'PUBLIC', ['olga' => 'A', 'omar' => 'B'])
			->willReturn($key);

		$response = $this->controller()->createKey(publicKey: 'PUBLIC', copies: ['olga' => 'A', 'omar' => 'B']);

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'key-1', actual: $response->getData()['id']);
		$this->assertSame(expected: 'FP', actual: $response->getData()['fingerprint']);
		$this->assertSame(expected: 'active', actual: $response->getData()['status']);
	}//end testCreateKeyAnswersCreatedWithTheStoredKey()

	/**
	 * A key creation by a non-officer is 403; one with malformed copies is 400.
	 *
	 * @return void
	 */
	public function testCreateKeyRefusalsMapToForbiddenAndBadRequest(): void {
		$this->keys->expects($this->exactly(count: 2))
			->method('createKey')
			->willReturnCallback(
				static function (string $officerUid): RecoveryKey {
					if ($officerUid === 'mallory') {
						throw new ForbiddenException(message: 'Only officers create the recovery key');
					}

					throw new InvalidArgumentException(message: 'Every officer needs one copy');
				}
			);

		$forbidden = $this->controller(uid: 'mallory')->createKey(publicKey: 'PUBLIC', copies: []);
		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $forbidden->getStatus());
		$this->assertSame(expected: ['message' => 'Only officers create the recovery key'], actual: $forbidden->getData());

		$bad = $this->controller()->createKey(publicKey: 'PUBLIC', copies: ['olga' => 'A']);
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $bad->getStatus());
		$this->assertSame(expected: ['message' => 'Every officer needs one copy'], actual: $bad->getData());
	}//end testCreateKeyRefusalsMapToForbiddenAndBadRequest()

	/**
	 * A named key id reaches the service as that id, and the answer is the
	 * caller's own wrapped copy.
	 *
	 * @return void
	 */
	public function testOwnCopyOfANamedKeyReturnsTheCallersWrappedCopy(): void {
		$copy = new RecoveryOfficer();
		$copy->setRecoveryKeyId('key-7');
		$copy->setWrappedPrivateKey('WRAPPED');
		$this->keys->expects($this->once())->method('ownCopy')->with('olga', 'key-7')->willReturn($copy);

		$response = $this->controller()->ownCopy(keyId: 'key-7');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['recoveryKeyId' => 'key-7', 'wrappedPrivateKey' => 'WRAPPED'], actual: $response->getData());
	}//end testOwnCopyOfANamedKeyReturnsTheCallersWrappedCopy()

	/**
	 * No key id means the active key (null to the service); no copy is a 404.
	 *
	 * @return void
	 */
	public function testOwnCopyOfTheActiveKeyWithoutACopyIsNotFound(): void {
		$this->keys->expects($this->once())
			->method('ownCopy')
			->with('mallory', null)
			->willThrowException(new NotFoundException(message: 'No recovery key copy'));

		$response = $this->controller(uid: 'mallory')->ownCopy();

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $response->getData());
	}//end testOwnCopyOfTheActiveKeyWithoutACopyIsNotFound()

	/**
	 * Replacing the caller's own copy forwards the key and the new wrap.
	 *
	 * @return void
	 */
	public function testReplaceOwnCopyForwardsTheNewWrap(): void {
		$this->keys->expects($this->once())->method('replaceOwnCopy')->with('olga', 'key-1', 'NEW-WRAP');

		$response = $this->controller()->replaceOwnCopy(keyId: 'key-1', wrappedPrivateKey: 'NEW-WRAP');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['status' => 'replaced'], actual: $response->getData());
	}//end testReplaceOwnCopyForwardsTheNewWrap()

	/**
	 * A copy the caller does not hold is 404; an empty wrap is 400.
	 *
	 * @return void
	 */
	public function testReplaceOwnCopyRefusalsMapToNotFoundAndBadRequest(): void {
		$this->keys->expects($this->exactly(count: 2))
			->method('replaceOwnCopy')
			->willReturnCallback(
				static function (string $officerUid, string $recoveryKeyId, string $wrapped): void {
					if ($officerUid === 'mallory') {
						throw new NotFoundException(message: 'No recovery key copy');
					}

					if ($wrapped === '') {
						throw new InvalidArgumentException(message: 'wrappedPrivateKey is required');
					}
				}
			);

		$missing = $this->controller(uid: 'mallory')->replaceOwnCopy(keyId: 'key-1', wrappedPrivateKey: 'W');
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $missing->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $missing->getData());

		$empty = $this->controller()->replaceOwnCopy(keyId: 'key-1');
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $empty->getStatus());
		$this->assertSame(expected: ['message' => 'wrappedPrivateKey is required'], actual: $empty->getData());
	}//end testReplaceOwnCopyRefusalsMapToNotFoundAndBadRequest()

	/**
	 * An approval reports the status the service reached.
	 *
	 * @return void
	 */
	public function testApproveReportsTheResultingStatus(): void {
		$this->requests->expects($this->once())->method('approve')->with('req-1', 'olga')->willReturn('approved');

		$response = $this->controller()->approve(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: ['status' => 'approved'], actual: $response->getData());
	}//end testApproveReportsTheResultingStatus()

	/**
	 * A decline by a non-officer is 403 with the reason.
	 *
	 * @return void
	 */
	public function testDeclineByANonOfficerIsForbidden(): void {
		$this->requests->expects($this->once())
			->method('decline')
			->with('req-1', 'mallory')
			->willThrowException(new ForbiddenException(message: 'Not a recovery officer'));

		$response = $this->controller(uid: 'mallory')->decline(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not a recovery officer'], actual: $response->getData());
	}//end testDeclineByANonOfficerIsForbidden()

	/**
	 * A decline that succeeds reports declined.
	 *
	 * @return void
	 */
	public function testDeclineReportsDeclined(): void {
		$this->requests->expects($this->once())->method('decline')->with('req-1', 'olga');

		$response = $this->controller()->decline(id: 'req-1');

		$this->assertSame(expected: ['status' => 'declined'], actual: $response->getData());
	}//end testDeclineReportsDeclined()

	/**
	 * Handoff material for a request the caller did not approve answers like
	 * an unknown id: 404 without the service's reason.
	 *
	 * @return void
	 */
	public function testHandoffForSomeoneElsesRequestIsNotFound(): void {
		$this->requests->expects($this->once())
			->method('handoff')
			->with('req-1', 'omar')
			->willThrowException(new NotFoundException(message: 'Not an approver of req-1'));

		$response = $this->controller(uid: 'omar')->handoff(id: 'req-1');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertSame(expected: ['message' => 'Not found'], actual: $response->getData());
	}//end testHandoffForSomeoneElsesRequestIsNotFound()

	/**
	 * Posting an empty sealed result is a 400 with the service's reason;
	 * posting one reports handed-off.
	 *
	 * @return void
	 */
	public function testPostSealedMapsAMalformedBodyToBadRequest(): void {
		$this->requests->expects($this->exactly(count: 2))
			->method('postSealed')
			->willReturnCallback(
				static function (string $id, string $officerUid, string $sealed): void {
					if ($id !== 'req-1' || $officerUid !== 'olga') {
						throw new NotFoundException(message: 'unexpected');
					}

					if ($sealed === '') {
						throw new InvalidArgumentException(message: 'sealedResult is required');
					}
				}
			);

		$empty = $this->controller()->postSealed(id: 'req-1');
		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $empty->getStatus());
		$this->assertSame(expected: ['message' => 'sealedResult is required'], actual: $empty->getData());

		$sealed = $this->controller()->postSealed(id: 'req-1', sealedResult: 'SEALED');
		$this->assertSame(expected: Http::STATUS_OK, actual: $sealed->getStatus());
		$this->assertSame(expected: ['status' => 'handed-off'], actual: $sealed->getData());
	}//end testPostSealedMapsAMalformedBodyToBadRequest()
}//end class
