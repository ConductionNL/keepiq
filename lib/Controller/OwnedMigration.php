<?php

/**
 * Keepiq Owned Migration
 *
 * A suite migration the acting user has been proven to own.
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

use OCA\Keepiq\Db\SuiteMigration;

/**
 * A migration that passed the ownership guard, with the acting user's id.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class OwnedMigration {
	/**
	 * Constructor for OwnedMigration.
	 *
	 * @param SuiteMigration $migration The already-guarded migration
	 * @param string $userId The acting user's id
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SuiteMigration $migration,
		public readonly string $userId,
	) {
	}//end __construct()
}//end class
