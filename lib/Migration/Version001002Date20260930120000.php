<?php

/**
 * Keepiq migration: the trash and archive state of a secret.
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
 * Adds nullable `trashed_at` and `archived_at` to the secrets table and an
 * index on (owner_id, trashed_at). Existing rows stay live (both null).
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
 */
class Version001002Date20260930120000 extends SimpleMigrationStep {

	/**
	 * Add the columns and the index when they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-deleting-a-secret-moves-it-to-the-trash
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_secrets') === false) {
			return null;
		}

		$table   = $schema->getTable('keepiq_secrets');
		$changed = false;
		foreach (['trashed_at', 'archived_at'] as $column) {
			if ($table->hasColumn($column) === false) {
				$table->addColumn($column, Types::DATETIME, ['notnull' => false]);
				$changed = true;
			}
		}

		if ($table->hasIndex('keepiq_secrets_owner_trash') === false) {
			$table->addIndex(['owner_id', 'trashed_at'], 'keepiq_secrets_owner_trash');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
