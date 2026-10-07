<?php

/**
 * Keepiq Backup Schema Fingerprint
 *
 * A fingerprint of the installed Keepiq schema, written into every archive
 * and compared before a restore (admin-scheduled-vault-backups D4). It is the
 * SHA-256 of the backed-up table list plus the Keepiq migrations Nextcloud
 * has applied, in order. Two installs with the same migrations have the same
 * tables and columns; Nextcloud's public API offers no column reader outside
 * a migration, so the applied migrations are the authority used here.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

use OCA\Keepiq\AppInfo\Application;
use OCP\IDBConnection;

/**
 * Computes the installed schema fingerprint.
 */
class SchemaFingerprint {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The fingerprint of the installed schema.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-hold-ciphertext-and-metadata-only
	 */
	public function current(): string {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('version')
			->from('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)))
			->executeQuery();
		$versions = [];
		while (($row = $result->fetch()) !== false) {
			$versions[] = (string)$row['version'];
		}

		$result->closeCursor();
		sort($versions);

		return hash('sha256', implode(',', BackupTableRegistry::TABLES) . '|' . implode(',', $versions));
	}//end current()
}//end class
