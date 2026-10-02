<?php

/**
 * Keepiq Migration: use-only shares and shares that end by themselves
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
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

namespace OCA\Keepiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `use_only` and `expires_at` to the three grant tables (share targets,
 * group shares, team-folder memberships) and `use_only` and
 * `access_expires_at` to the secrets table, where the resolver materialises
 * the effective values onto each recipient copy. Existing rows stay
 * unrestricted.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.1
 */
class Version001004Date20261002150000 extends SimpleMigrationStep {

	/**
	 * The grant tables that carry the two flags as the sharer set them.
	 *
	 * @var string[]
	 */
	public const GRANT_TABLES = [
		'keepiq_share_targets',
		'keepiq_group_shares',
		'keepiq_team_folder_members',
	];

	/**
	 * The index on the secrets table that the expiry filter and job use.
	 *
	 * @var string
	 */
	public const ACCESS_EXPIRES_INDEX = 'keepiq_sec_access_exp_idx';

	/**
	 * Add the columns where they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.1
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema  = $schemaClosure();
		$changed = false;
		foreach (self::GRANT_TABLES as $tableName) {
			if ($schema->hasTable($tableName) === false) {
				continue;
			}

			$changed = $this->addFlags(schema: $schema, tableName: $tableName, expiryColumn: 'expires_at') || $changed;
		}

		if ($schema->hasTable('keepiq_secrets') === true) {
			$changed = $this->addFlags(schema: $schema, tableName: 'keepiq_secrets', expiryColumn: 'access_expires_at') || $changed;
			$table   = $schema->getTable('keepiq_secrets');
			if ($table->hasIndex(self::ACCESS_EXPIRES_INDEX) === false) {
				$table->addIndex(['access_expires_at'], self::ACCESS_EXPIRES_INDEX);
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Add `use_only` and the given expiry column to one table.
	 *
	 * @param ISchemaWrapper $schema       The schema
	 * @param string         $tableName    The table
	 * @param string         $expiryColumn The name of the end-date column
	 *
	 * @return bool Whether anything was added
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/tasks.md#task-1.1
	 */
	private function addFlags(ISchemaWrapper $schema, string $tableName, string $expiryColumn): bool {
		$table   = $schema->getTable($tableName);
		$changed = false;
		if ($table->hasColumn('use_only') === false) {
			// Nextcloud boolean columns must be nullable (Oracle has no false).
			$table->addColumn('use_only', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}

		if ($table->hasColumn($expiryColumn) === false) {
			$table->addColumn($expiryColumn, Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}

		return $changed;
	}//end addFlags()
}//end class
