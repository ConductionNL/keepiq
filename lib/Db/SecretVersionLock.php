<?php

/**
 * Keepiq Secret Version Lock
 *
 * Takes the row lock of a secret without moving its version, the lock a share-save takes, and reads the version
 * the row holds (keepiq#1255).
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use Closure;
use DateTime;
use DateTimeZone;
use OCP\IDBConnection;
use Throwable;

/**
 * The version lock of `keepiq_secrets` rows.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
class SecretVersionLock {
	/**
	 * Constructor for SecretVersionLock.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(private IDBConnection $db) {
	}//end __construct()

	/**
	 * Take the row lock of a secret without moving its version, and read the version it holds. The no-op UPDATE
	 * holds the lock until the transaction ends, the same lock SecretMapper::advanceVersion() takes, so a
	 * share-save of the row and this caller wait for each other, and the read after it sees the version the
	 * other one committed. Call it inside a transaction.
	 *
	 * @param string $id The secret ID
	 *
	 * @return DateTime|null The version, in UTC; null when the row is gone
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public function lock(string $id): ?DateTime {
		$lock = $this->db->getQueryBuilder();
		$lock->update('keepiq_secrets')
			->set('updated_at', $lock->createFunction('updated_at'))
			->where($lock->expr()->eq('id', $lock->createNamedParameter($id)));
		$lock->executeStatement();

		$read = $this->db->getQueryBuilder();
		$read->select('updated_at')
			->from('keepiq_secrets')
			->where($read->expr()->eq('id', $read->createNamedParameter($id)));
		$result = $read->executeQuery();
		$value  = $result->fetchOne();
		$result->closeCursor();

		if (is_string($value) === false || $value === '') {
			return null;
		}

		return new DateTime($value, new DateTimeZone('UTC'));
	}//end lock()

	/**
	 * Run `$work` in a transaction that holds the secret's row lock, with the
	 * version the row holds (null when it is gone). The transaction commits
	 * when `$work` returns and rolls back when it throws.
	 *
	 * @param string $id The secret ID
	 * @param Closure(?DateTime):T $work What to do while the row is locked
	 *
	 * @return T What `$work` returned
	 *
	 * @throws Throwable What `$work` threw, after the rollback
	 *
	 * @template T
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 */
	public function whileLocked(string $id, Closure $work): mixed {
		$this->db->beginTransaction();
		try {
			$result = $work($this->lock(id: $id));
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}

		return $result;
	}//end whileLocked()
}//end class
