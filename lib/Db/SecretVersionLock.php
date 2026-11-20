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

use DateTime;
use DateTimeZone;
use OCP\IDBConnection;

/**
 * The version lock of `keepiq_secrets` rows.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
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
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
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
}//end class
