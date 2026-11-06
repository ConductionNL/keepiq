<?php

/**
 * Database fixture for tests of share rows and their quarantine
 * (GHSA-m5p9-6pw7-pf3g).
 *
 * The quarantine filter lives in SQL, so a mocked mapper would only echo the
 * rows a test hands it. A test using this trait runs against the instance's
 * real database inside a transaction that is rolled back afterwards, and
 * skips on a checkout without a database. CI runs it against pgsql.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
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

namespace OCA\Keepiq\Tests\Support;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;

/**
 * Real-database secrets and share rows for a test case.
 */
trait ShareTargetDatabase {

	/**
	 * The live database connection.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * Resolve a real connection with the quarantine columns and open a
	 * transaction, or skip.
	 *
	 * @return void
	 */
	private function openDatabase(): void {
		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_share_targets') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->db->beginTransaction();
	}//end openDatabase()

	/**
	 * Roll back everything the test wrote.
	 *
	 * @return void
	 */
	private function closeDatabase(): void {
		if ($this->db !== null && $this->db->inTransaction() === true) {
			$this->db->rollBack();
		}
	}//end closeDatabase()

	/**
	 * Insert a user-owned secret.
	 *
	 * @param string   $ownerId   The owning user
	 * @param DateTime $createdAt When it was created
	 * @param string   $name      The display name
	 *
	 * @return Secret
	 */
	private function insertSecret(string $ownerId, DateTime $createdAt, string $name = 'secret'): Secret {
		$secret = new Secret();
		$secret->setId(Uuid::uuid4()->toString());
		$secret->setName($name);
		$secret->setTypeId('login');
		$secret->setKey('CIPHERTEXT');
		$secret->setEncryptionSuiteId('suite-'.$ownerId);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setCreatedAt($createdAt);
		$secret->setUpdatedAt($createdAt);

		return (new SecretMapper(db: $this->db))->insert($secret);
	}//end insertSecret()

	/**
	 * Insert a share row, quarantined or live.
	 *
	 * @param string               $sourceSecretId The source secret
	 * @param string               $targetUserId   The recipient
	 * @param string               $secretId       The recipient copy
	 * @param string               $createdBy      Who created the row
	 * @param DateTime             $createdAt      When it was created
	 * @param bool                 $quarantined    Whether it is quarantined
	 * @param array<string,string> $grants         Optional groupShareId / teamFolderId
	 *
	 * @return ShareTarget
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A fixture switch.
	 */
	private function insertShareTarget(
		string $sourceSecretId,
		string $targetUserId,
		string $secretId,
		string $createdBy,
		DateTime $createdAt,
		bool $quarantined = false,
		array $grants = [],
	): ShareTarget {
		$row = new ShareTarget();
		$row->setId(Uuid::uuid4()->toString());
		$row->setSourceSecretId($sourceSecretId);
		$row->setTargetUserId($targetUserId);
		$row->setSecretId($secretId);
		$row->setCreatedBy($createdBy);
		$row->setCreatedAt($createdAt);
		$row->setGroupShareId($grants['groupShareId'] ?? null);
		$row->setTeamFolderId($grants['teamFolderId'] ?? null);
		if (isset($grants['expiresAt']) === true) {
			$row->setExpiresAt(new DateTime($grants['expiresAt']));
		}

		if ($quarantined === true) {
			$row->setQuarantinedAt(new DateTime());
			$row->setQuarantineReason('test');
		}

		return (new ShareTargetMapper(db: $this->db))->insert($row);
	}//end insertShareTarget()

	/**
	 * A user id unique to the running test, so real rows never match.
	 *
	 * @param string $name A readable stem
	 *
	 * @return string
	 */
	private function user(string $name): string {
		return 'qt-'.$name.'-'.substr(bin2hex(random_bytes(4)), 0, 8);
	}//end user()
}//end trait
