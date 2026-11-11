<?php

/**
 * Keepiq Termination Run
 *
 * One completion or abort of a suite migration, once its owner is resolved.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\SuiteMigration;

/**
 * The migration being terminated, the id it was asked for by and its
 * resolved owner; built by MigrationService and never kept beyond the call.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */
final class TerminationRun {
	/**
	 * Constructor for TerminationRun.
	 *
	 * @param SuiteMigration $migration The in-progress migration
	 * @param string $migrationId The migration id the caller asked for
	 * @param string|null $ownerId The resolved owner, or null when unresolvable
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SuiteMigration $migration,
		public readonly string $migrationId,
		public readonly ?string $ownerId,
	) {
	}//end __construct()
}//end class
