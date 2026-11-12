<?php

/**
 * Keepiq Record Outcome
 *
 * What the browser reports for one migrated record.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Controller;

/**
 * The outcome the browser reports for exactly one record of one store:
 * a commit, or the failure to record in its place.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class RecordOutcome {
	/**
	 * Constructor for RecordOutcome.
	 *
	 * @param OwnedMigration $owned The guarded migration and acting user
	 * @param string $store The store being written
	 * @param string $recordId The record ID
	 * @param string|null $error A per-record failure to record instead of committing
	 *
	 * @return void
	 */
	public function __construct(
		public readonly OwnedMigration $owned,
		public readonly string $store,
		public readonly string $recordId,
		public readonly ?string $error,
	) {
	}//end __construct()
}//end class
