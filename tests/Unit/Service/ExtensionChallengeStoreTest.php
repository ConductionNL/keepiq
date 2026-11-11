<?php

/**
 * Unit tests for ExtensionChallengeStore.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\WebAuthnChallengeMapper;
use OCA\Keepiq\Service\ExtensionChallengeStore;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * Extension challenges are single-use, short-lived and bound to the user,
 * the app password and the purpose.
 */
class ExtensionChallengeStoreTest extends TestCase {
	/**
	 * The stored rows, by id.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rows = [];

	private int $nextId = 1;

	private int $now = 1000;

	/**
	 * A row a parallel request deletes between this request's read and its
	 * delete, or null.
	 *
	 * @var int|null
	 */
	private ?int $raceDeletes = null;

	private ExtensionChallengeStore $store;

	/**
	 * A store over an in-memory table and a settable clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$mapper = $this->createMock(originalClassName: WebAuthnChallengeMapper::class);
		$mapper->method('replace')->willReturnCallback(function (array $row): void {
			foreach ($this->rows as $id => $existing) {
				if ($existing['uid'] === $row['uid'] && $existing['token_id'] === $row['token_id'] && $existing['purpose'] === $row['purpose']) {
					unset($this->rows[$id]);
				}
			}

			$this->rows[$this->nextId] = ['id' => $this->nextId] + $row;
			$this->nextId++;
		});
		$mapper->method('findPending')->willReturnCallback(function (string $uid, int $tokenId, string $purpose): ?array {
			foreach ($this->rows as $row) {
				if ($row['uid'] === $uid && $row['token_id'] === $tokenId && $row['purpose'] === $purpose) {
					return $row;
				}
			}

			return null;
		});
		$mapper->method('deleteById')->willReturnCallback(function (int $id): int {
			if ($this->raceDeletes === $id) {
				unset($this->rows[$id]);
			}

			if (isset($this->rows[$id]) === false) {
				return 0;
			}

			unset($this->rows[$id]);
			return 1;
		});
		$mapper->method('deleteByToken')->willReturnCallback(function (int $tokenId): int {
			$before = count($this->rows);
			$this->rows = array_filter($this->rows, static fn (array $row): bool => $row['token_id'] !== $tokenId);
			return $before - count($this->rows);
		});
		$mapper->method('deleteExpired')->willReturnCallback(function (int $now): int {
			$before = count($this->rows);
			$this->rows = array_filter($this->rows, static fn (array $row): bool => $row['expires_at'] >= $now);
			return $before - count($this->rows);
		});

		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturnCallback(static fn (int $length, string $chars): string => random_bytes($length));
		$clock = $this->createMock(originalClassName: ITimeFactory::class);
		$clock->method('getTime')->willReturnCallback(fn (): int => $this->now);

		$this->store = new ExtensionChallengeStore(mapper: $mapper, random: $random, timeFactory: $clock);
	}//end setUp()

	/**
	 * An unlock challenge is 32 bytes and taken back once.
	 *
	 * @return void
	 */
	public function testAnUnlockChallengeIsTakenOnce(): void {
		$issued = $this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');

		$this->assertSame(32, strlen($issued['challenge']));
		$this->assertNull($issued['assertionChallenge']);
		$this->assertSame($issued, $this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'));
		$this->assertNull($this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'), 'a challenge must not be usable twice');
	}//end testAnUnlockChallengeIsTakenOnce()

	/**
	 * An enrolment gets two distinct challenges.
	 *
	 * @return void
	 */
	public function testAnEnrolmentGetsAProofChallengeToo(): void {
		$issued = $this->store->issue(uid: 'alice', tokenId: 7, purpose: 'enrol');

		$this->assertSame(32, strlen((string)$issued['assertionChallenge']));
		$this->assertNotSame($issued['challenge'], $issued['assertionChallenge']);
		$this->assertSame($issued, $this->store->take(uid: 'alice', tokenId: 7, purpose: 'enrol'));
	}//end testAnEnrolmentGetsAProofChallengeToo()

	/**
	 * When a parallel request deletes the row first, this one gets nothing.
	 *
	 * @return void
	 */
	public function testOnlyTheRequestWhoseDeleteRemovedTheRowMayVerify(): void {
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');
		$this->raceDeletes = array_key_first($this->rows);

		$this->assertNull($this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'));
	}//end testOnlyTheRequestWhoseDeleteRemovedTheRowMayVerify()

	/**
	 * A challenge of another app password, user or purpose is not given out.
	 *
	 * @return void
	 */
	public function testAChallengeIsBoundToTheAppPasswordUserAndPurpose(): void {
		$issued = $this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');

		$this->assertNull($this->store->take(uid: 'alice', tokenId: 8, purpose: 'unlock'));
		$this->assertNull($this->store->take(uid: 'mallory', tokenId: 7, purpose: 'unlock'));
		$this->assertNull($this->store->take(uid: 'alice', tokenId: 7, purpose: 'enrol'));
		$this->assertSame($issued, $this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'));
	}//end testAChallengeIsBoundToTheAppPasswordUserAndPurpose()

	/**
	 * A challenge issued 301 seconds ago is refused and spent.
	 *
	 * @return void
	 */
	public function testAnExpiredChallengeIsRefused(): void {
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');
		$this->now += ExtensionChallengeStore::LIFETIME + 1;

		$this->assertNull($this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'));
		$this->assertSame([], $this->rows);
	}//end testAnExpiredChallengeIsRefused()

	/**
	 * A new challenge replaces the pending one of the same binding only.
	 *
	 * @return void
	 */
	public function testANewChallengeReplacesThePendingOne(): void {
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');
		$second = $this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');
		$other = $this->store->issue(uid: 'alice', tokenId: 8, purpose: 'unlock');

		$this->assertCount(2, $this->rows);
		$this->assertSame($second, $this->store->take(uid: 'alice', tokenId: 7, purpose: 'unlock'));
		$this->assertSame($other, $this->store->take(uid: 'alice', tokenId: 8, purpose: 'unlock'));
	}//end testANewChallengeReplacesThePendingOne()

	/**
	 * Forgetting an app password drops its challenges; the purge drops the
	 * expired ones.
	 *
	 * @return void
	 */
	public function testForgetTokenAndPurge(): void {
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'unlock');
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'enrol');
		$this->store->issue(uid: 'bob', tokenId: 9, purpose: 'unlock');

		$this->assertSame(2, $this->store->forgetToken(tokenId: 7));
		$this->assertSame(0, $this->store->purgeExpired());
		$this->now += ExtensionChallengeStore::LIFETIME + 1;
		$this->assertSame(1, $this->store->purgeExpired());
	}//end testForgetTokenAndPurge()

	/**
	 * An unknown purpose or a tampered row is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownPurposeOrATamperedRowIsRefused(): void {
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'enrol');
		$id = (int)array_key_first($this->rows);
		$this->rows[$id]['assertion_challenge'] = null;
		$this->assertNull($this->store->take(uid: 'alice', tokenId: 7, purpose: 'enrol'));

		$this->expectException(InvalidArgumentException::class);
		$this->store->issue(uid: 'alice', tokenId: 7, purpose: 'login');
	}//end testAnUnknownPurposeOrATamperedRowIsRefused()
}//end class
