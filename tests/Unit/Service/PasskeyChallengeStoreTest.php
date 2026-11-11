<?php

/**
 * Unit tests for PasskeyChallengeStore.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\PasskeyChallengeStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;
use PHPUnit\Framework\TestCase;

/**
 * The enrolment challenge pair is single-use, short-lived and bound to the
 * user it was issued to.
 */
class PasskeyChallengeStoreTest extends TestCase {
	/**
	 * The session contents.
	 *
	 * @var array<string,mixed>
	 */
	private array $data = [];

	private int $now = 1000;

	private PasskeyChallengeStore $store;

	/**
	 * A store over an in-memory session and a settable clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$session = $this->createMock(originalClassName: ISession::class);
		$session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
			$this->data[$key] = $value;
		});
		$session->method('get')->willReturnCallback(fn (string $key): mixed => $this->data[$key] ?? null);
		$session->method('remove')->willReturnCallback(function (string $key): void {
			unset($this->data[$key]);
		});
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->store = new PasskeyChallengeStore(session: $session, timeFactory: $clock);
	}//end setUp()

	/**
	 * An issued pair is two distinct 32-byte challenges, taken back once.
	 *
	 * @return void
	 */
	public function testAnIssuedPairIsTakenOnce(): void {
		$pair = $this->store->issue(uid: 'alice');

		$this->assertSame(32, strlen($pair['challenge']));
		$this->assertSame(32, strlen($pair['assertionChallenge']));
		$this->assertNotSame($pair['challenge'], $pair['assertionChallenge']);
		$this->assertSame($pair, $this->store->take(uid: 'alice'));
		$this->assertNull($this->store->take(uid: 'alice'), 'a challenge pair must not be usable twice');
	}//end testAnIssuedPairIsTakenOnce()

	/**
	 * A pair past its lifetime is refused and removed.
	 *
	 * @return void
	 */
	public function testAnExpiredPairIsRefused(): void {
		$this->store->issue(uid: 'alice');
		$this->now += PasskeyChallengeStore::LIFETIME + 1;

		$this->assertNull($this->store->take(uid: 'alice'));
		$this->assertArrayNotHasKey(PasskeyChallengeStore::SESSION_KEY, $this->data);
	}//end testAnExpiredPairIsRefused()

	/**
	 * A pair issued to another user is refused and spent.
	 *
	 * @return void
	 */
	public function testAPairOfAnotherUserIsRefused(): void {
		$this->store->issue(uid: 'alice');

		$this->assertNull($this->store->take(uid: 'mallory'));
		$this->assertNull($this->store->take(uid: 'alice'));
	}//end testAPairOfAnotherUserIsRefused()

	/**
	 * A new pair replaces the pending one.
	 *
	 * @return void
	 */
	public function testANewPairReplacesThePendingOne(): void {
		$first = $this->store->issue(uid: 'alice');
		$second = $this->store->issue(uid: 'alice');

		$this->assertNotSame($first, $second);
		$this->assertSame($second, $this->store->take(uid: 'alice'));
	}//end testANewPairReplacesThePendingOne()

	/**
	 * Nothing pending, or a tampered entry, yields nothing.
	 *
	 * @return void
	 */
	public function testNothingOrAMalformedEntryIsRefused(): void {
		$this->assertNull($this->store->take(uid: 'alice'));

		$this->data[PasskeyChallengeStore::SESSION_KEY] = ['uid' => 'alice', 'challenge' => '', 'assertionChallenge' => '', 'expiresAt' => 2000];
		$this->assertNull($this->store->take(uid: 'alice'));
	}//end testNothingOrAMalformedEntryIsRefused()

	/**
	 * An unlock challenge is 32 bytes, taken back once, and lives apart from
	 * the enrolment pair: issuing one does not replace the other.
	 *
	 * @return void
	 */
	public function testAnUnlockChallengeIsTakenOnceAndKeptApart(): void {
		$pair = $this->store->issue(uid: 'alice');
		$challenge = $this->store->issueUnlock(uid: 'alice');

		$this->assertSame(32, strlen($challenge));
		$this->assertSame($challenge, $this->store->takeUnlock(uid: 'alice'));
		$this->assertNull($this->store->takeUnlock(uid: 'alice'), 'an unlock challenge must not be usable twice');
		$this->assertSame($pair, $this->store->take(uid: 'alice'));
	}//end testAnUnlockChallengeIsTakenOnceAndKeptApart()

	/**
	 * An unlock challenge of another user, or past its lifetime, is refused
	 * and spent.
	 *
	 * @return void
	 */
	public function testAnUnlockChallengeOfAnotherUserOrExpiredIsRefused(): void {
		$this->store->issueUnlock(uid: 'alice');
		$this->assertNull($this->store->takeUnlock(uid: 'mallory'));
		$this->assertNull($this->store->takeUnlock(uid: 'alice'));

		$this->store->issueUnlock(uid: 'alice');
		$this->now += PasskeyChallengeStore::LIFETIME + 1;
		$this->assertNull($this->store->takeUnlock(uid: 'alice'));
		$this->assertArrayNotHasKey(PasskeyChallengeStore::UNLOCK_SESSION_KEY, $this->data);

		$this->data[PasskeyChallengeStore::UNLOCK_SESSION_KEY] = ['uid' => 'alice', 'challenge' => '', 'expiresAt' => 2000];
		$this->assertNull($this->store->takeUnlock(uid: 'alice'));
	}//end testAnUnlockChallengeOfAnotherUserOrExpiredIsRefused()
}//end class
