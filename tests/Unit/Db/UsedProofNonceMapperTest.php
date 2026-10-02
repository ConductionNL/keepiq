<?php

/**
 * Database-backed tests for UsedProofNonceMapper.
 *
 * A vault-key proof is single-use because the unique index on `nonce_hash`
 * lets exactly one insert of a challenge succeed (keepiq#868). That guarantee
 * lives entirely in the database, so these tests talk to a real one, like
 * SecretRequestMapperTest: a mocked query builder would pass just as happily
 * against a table with no unique index, which is the defect this guards.
 *
 * They skip on a bare checkout and run in CI against an installed instance.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use OCA\Keepiq\Db\UsedProofNonceMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for UsedProofNonceMapper against a real database.
 */
class UsedProofNonceMapperTest extends TestCase {
	/**
	 * The live database connection.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The mapper under test.
	 *
	 * @var UsedProofNonceMapper|null
	 */
	private ?UsedProofNonceMapper $mapper = null;

	/**
	 * Hashes this test claimed, removed again in tearDown.
	 *
	 * @var array<int,string>
	 */
	private array $claimed = [];

	/**
	 * Resolve a real connection, or skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);

		if ($this->db->tableExists('keepiq_used_proofs') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->mapper = new UsedProofNonceMapper(db: $this->db);
	}//end setUp()

	/**
	 * Remove every row this test claimed, whether it passed or failed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->claimed as $hash) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('keepiq_used_proofs')
				->where($delete->expr()->eq('nonce_hash', $delete->createNamedParameter($hash)));
			$delete->executeStatement();
		}

		$this->claimed = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * A fresh hash, remembered for cleanup.
	 *
	 * @return string
	 */
	private function hash(): string {
		$hash = hash('sha256', 'test-' . bin2hex(random_bytes(8)));
		$this->claimed[] = $hash;

		return $hash;
	}//end hash()

	/**
	 * The first claim of a challenge wins and the second loses. This is the
	 * whole single-use guarantee, and it has to come from the unique index.
	 *
	 * @return void
	 */
	public function testASecondClaimOfTheSameChallengeLoses(): void {
		$hash = $this->hash();

		$this->assertTrue($this->mapper->claim(nonceHash: $hash, expiresAt: time() + 300));
		$this->assertFalse($this->mapper->claim(nonceHash: $hash, expiresAt: time() + 300));
	}//end testASecondClaimOfTheSameChallengeLoses()

	/**
	 * Two different challenges do not block each other.
	 *
	 * @return void
	 */
	public function testDifferentChallengesAreIndependent(): void {
		$this->assertTrue($this->mapper->claim(nonceHash: $this->hash(), expiresAt: time() + 300));
		$this->assertTrue($this->mapper->claim(nonceHash: $this->hash(), expiresAt: time() + 300));
	}//end testDifferentChallengesAreIndependent()

	/**
	 * The sweep removes an expired claim and keeps a live one.
	 *
	 * @return void
	 */
	public function testTheSweepRemovesOnlyExpiredClaims(): void {
		$expired = $this->hash();
		$live = $this->hash();
		$now = time();
		$this->mapper->claim(nonceHash: $expired, expiresAt: $now - 10);
		$this->mapper->claim(nonceHash: $live, expiresAt: $now + 300);

		$this->mapper->deleteExpired(now: $now);

		// The expired one can be claimed again; the live one still cannot.
		$this->assertTrue($this->mapper->claim(nonceHash: $expired, expiresAt: $now + 300));
		$this->assertFalse($this->mapper->claim(nonceHash: $live, expiresAt: $now + 300));
	}//end testTheSweepRemovesOnlyExpiredClaims()
}//end class
