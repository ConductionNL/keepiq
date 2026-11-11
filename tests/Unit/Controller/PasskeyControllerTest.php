<?php

/**
 * Contract tests for the PasskeyController challenge + record-use endpoints.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\PasskeyController;
use OCA\Keepiq\Service\PasskeyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract for `passkey#challenge` (GET /api/v1/passkeys/challenge) and
 * `passkey#used` (POST /api/v1/passkeys/{id}/used).
 *
 * Both endpoints are `#[NoAdminRequired]` and owner-scoped: the owner is taken
 * from the SESSION, never from the request. These tests assert that the service
 * is actually reached with the session owner plus the request's own credential
 * id, that the payload carries the service's value (not a controller-invented
 * one), and that an anonymous caller is refused before the service is touched.
 *
 */
class PasskeyControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked passkey service.
	 *
	 * @var PasskeyService&MockObject
	 */
	private PasskeyService&MockObject $service;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(originalClassName: IRequest::class);
		$this->service = $this->createMock(originalClassName: PasskeyService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller with the session resolving to the given user.
	 *
	 * @param string|null $userId The session user, or null for an anonymous caller
	 *
	 * @return PasskeyController The controller under test.
	 */
	private function controller(?string $userId = 'alice'): PasskeyController {
		if ($userId === null) {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->userSession->method('getUser')->willReturn($user);
		}

		return new PasskeyController(
			request: $this->request,
			service: $this->service,
			userSession: $this->userSession,
		);
	}//end controller()

	/**
	 * `passkey#challenge` must hand back the service's fresh challenge.
	 *
	 * The enrollment ceremony is only replay-safe when the challenge comes
	 * from the server's challenge source. A controller that minted its own
	 * constant would look identical from the status code alone, so the payload
	 * value is asserted against the service's return.
	 *
	 * @return void
	 */
	public function testChallengeReturnsTheServiceMintedChallenge(): void {
		$controller = $this->controller('alice');

		$this->service->expects($this->once())
			->method('enrolmentChallenge')
			->with('alice')
			->willReturn(['challenge' => 'T2xLZW5ub3QtcmVwbGF5LXRoaXM', 'assertionChallenge' => 'c2Vjb25k']);

		$response = $controller->challenge();

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'an authenticated caller must be served a challenge'
		);
		$this->assertSame(
			['challenge' => 'T2xLZW5ub3QtcmVwbGF5LXRoaXM', 'assertionChallenge' => 'c2Vjb25k'],
			$response->getData(),
			'the payload must carry the session owner\'s stored challenge pair'
		);
	}//end testChallengeReturnsTheServiceMintedChallenge()

	/**
	 * An anonymous caller must be refused before a challenge is minted.
	 *
	 * Minting first and refusing afterwards would burn challenge state for an
	 * unauthenticated caller, so the service must never be reached.
	 *
	 * @return void
	 */
	public function testChallengeRefusesAnAnonymousCallerWithoutMintingOne(): void {
		$controller = $this->controller(null);

		$this->service->expects($this->never())->method('enrolmentChallenge');

		$response = $controller->challenge();

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'the vault lock screen refuses an anonymous caller with 403'
		);
		$this->assertSame(
			['message' => 'Unauthenticated'],
			$response->getData(),
			'the refusal body must name the reason and leak no challenge'
		);
	}//end testChallengeRefusesAnAnonymousCallerWithoutMintingOne()

	/**
	 * `passkey#used` must stamp the session owner's own credential.
	 *
	 * The owner comes from the session and the credential id from the route —
	 * forwarding the wrong one of the two would let a caller stamp another
	 * user's passkey, so both arguments are asserted.
	 *
	 * @return void
	 */
	public function testUsedRecordsTheSessionOwnerAgainstTheRoutedCredential(): void {
		$controller = $this->controller('alice');

		$this->service->expects($this->once())
			->method('recordUse')
			->with('alice', '4e6f7420-0000-4000-8000-000000000042');

		$response = $controller->used(id: '4e6f7420-0000-4000-8000-000000000042');

		$this->assertSame(
			Http::STATUS_OK,
			$response->getStatus(),
			'a recorded use answers 200'
		);
		$this->assertSame(
			['recorded' => true],
			$response->getData(),
			'the caller must be told the stamp was persisted'
		);
	}//end testUsedRecordsTheSessionOwnerAgainstTheRoutedCredential()

	/**
	 * An anonymous caller must not be able to stamp any credential.
	 *
	 * @return void
	 */
	public function testUsedRefusesAnAnonymousCallerAndWritesNothing(): void {
		$controller = $this->controller(null);

		$this->service->expects($this->never())->method('recordUse');

		$response = $controller->used(id: '4e6f7420-0000-4000-8000-000000000042');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$response->getStatus(),
			'an anonymous caller must not reach the last-used write'
		);
		$this->assertSame(
			['message' => 'Unauthenticated'],
			$response->getData(),
			'the refusal must not be dressed up as a successful record'
		);
	}//end testUsedRefusesAnAnonymousCallerAndWritesNothing()

	/**
	 * `passkey#create` hands the ceremony to the service for the session
	 * owner and answers a refused verification with 400.
	 *
	 * @return void
	 */
	public function testCreateForwardsTheCeremonyAndRefusesAnUnverifiedOne(): void {
		$controller = $this->controller('alice');

		$this->service->expects($this->once())
			->method('enroll')
			->with(
				'alice',
				$this->callback(
					static fn (array $dto): bool => $dto['attestation'] === ['id' => 'a']
						&& $dto['assertion'] === ['id' => 'b']
						&& $dto['credentialId'] === 'c'
						&& array_key_exists('publicKey', $dto) === false
				)
			)
			->willThrowException(new InvalidArgumentException('The passkey could not be verified'));

		$response = $controller->create(
			credentialId: 'c',
			wrappedUnlockKey: 'e',
			prfSalt: 's',
			attestation: ['id' => 'a'],
			assertion: ['id' => 'b'],
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'The passkey could not be verified'], $response->getData());
	}//end testCreateForwardsTheCeremonyAndRefusesAnUnverifiedOne()

	/**
	 * `passkey#unlock` forwards the assertion for the session owner, returns
	 * the envelope the service released, and answers a refusal with a
	 * generic 400 that carries no envelope.
	 *
	 * @return void
	 */
	public function testUnlockReturnsOnlyAVerifiedEnvelope(): void {
		$controller = $this->controller('alice');
		$released = ['id' => 'p1', 'credentialId' => 'c', 'wrappedUnlockKey' => 'W', 'unlockKeyEpoch' => 3];

		$this->service->expects($this->exactly(2))
			->method('unlock')
			->with('alice', ['rawId' => 'c'], 'web', '')
			->willReturnOnConsecutiveCalls(
				$released,
				$this->throwException(new InvalidArgumentException('The passkey could not be verified'))
			);

		$response = $controller->unlock(assertion: ['rawId' => 'c']);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($released, $response->getData());

		$refused = $controller->unlock(assertion: ['rawId' => 'c']);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		$this->assertSame(['message' => 'The passkey could not be verified'], $refused->getData());
	}//end testUnlockReturnsOnlyAVerifiedEnvelope()

	/**
	 * An anonymous caller is refused before any assertion is looked at.
	 *
	 * @return void
	 */
	public function testUnlockRefusesAnAnonymousCaller(): void {
		$controller = $this->controller(null);
		$this->service->expects($this->never())->method('unlock');

		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->unlock(assertion: ['rawId' => 'c'])->getStatus());
	}//end testUnlockRefusesAnAnonymousCaller()
}//end class
