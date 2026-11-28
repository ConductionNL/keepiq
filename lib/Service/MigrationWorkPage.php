<?php

/**
 * Keepiq Migration Work Page
 *
 * One page of compromise-recovery work a user asks for: the migration, the
 * user, and the page's limit and offset, clamped. The limit is capped low:
 * every record in the page carries its RSA ciphertext, so a large page is a
 * large response for no gain — the client's in-flight window is 4.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SuiteMigration;

/**
 * The bounds of one work page.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class MigrationWorkPage {

	/**
	 * The page size, between 1 and 100.
	 *
	 * @var integer
	 */
	public readonly int $limit;

	/**
	 * The page offset, 0 or more.
	 *
	 * @var integer
	 */
	public readonly int $offset;

	/**
	 * Capture the page, clamping the limit to 1..100 and the offset to 0 and up.
	 *
	 * @param SuiteMigration $migration The in-progress migration
	 * @param string         $ownerId   The user the migration runs for
	 * @param integer        $limit     The requested page size
	 * @param integer        $offset    The requested offset
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SuiteMigration $migration,
		public readonly string $ownerId,
		int $limit,
		int $offset,
	) {
		$this->limit = max(1, min(100, $limit));
		$this->offset = max(0, $offset);
	}//end __construct()

	/**
	 * The suite the page's records are still bound to.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function oldSuiteId(): string {
		return $this->migration->getOldSuiteId();
	}//end oldSuiteId()

	/**
	 * What the work page is: the migration, its suites, the clamped bounds and the version window.
	 *
	 * @param integer $versionWindow The configured per-secret version window
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function header(int $versionWindow): array {
		return [
			'migrationId' => $this->migration->getId(),
			'oldSuiteId' => $this->oldSuiteId(),
			'newSuiteId' => $this->migration->getNewSuiteId(),
			'limit' => $this->limit,
			'offset' => $this->offset,
			'versionWindow' => $versionWindow,
		];
	}//end header()

	/**
	 * One version in the work page, ciphertext only. A version stores `''`
	 * for a field that was empty; the list says null for it, as it does for
	 * a head.
	 *
	 * @param SecretVersion        $version The version to re-encrypt
	 * @param FederatedValueFields $blobs   The blob normaliser
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-compromise-recovery-migration-re-encrypts-a-bounded-window
	 */
	public function versionRecord(SecretVersion $version, FederatedValueFields $blobs): array {
		return [
			'id' => $version->getId(),
			'secretId' => $version->getSecretId(),
			'versionNumber' => $version->getVersionNumber(),
			'key' => $version->getKey(),
			'login' => $version->getLogin(),
			'additionalFields' => $version->getAdditionalFields(),
			'totp' => $blobs->blob(value: $version->getTotp()),
			'passkeys' => $blobs->blob(value: $version->getPasskeys()),
		];
	}//end versionRecord()
}//end class
