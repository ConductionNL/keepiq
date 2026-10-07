<?php

/**
 * Keepiq Migration: favourites, tags and last used
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
 * Adds `is_favourite` and `last_used_at` to the secrets table and the
 * `keepiq_secret_tags` table. Existing rows are not favourites and have
 * never been used.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
 */
class Version001003Date20261002000000 extends SimpleMigrationStep {

	/**
	 * Add the columns and the tag table when they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_secrets') === false) {
			return null;
		}

		$changed = $this->addSecretColumns(schema: $schema);
		if ($schema->hasTable('keepiq_secret_tags') === false) {
			$table = $schema->createTable('keepiq_secret_tags');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('tag', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['secret_id', 'tag'], 'keepiq_sec_tags_secret_tag');
			$table->addIndex(['owner_id', 'tag'], 'keepiq_sec_tags_owner_tag');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Add `is_favourite` and `last_used_at` to the secrets table.
	 *
	 * @param ISchemaWrapper $schema The schema
	 *
	 * @return bool Whether anything was added
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 */
	private function addSecretColumns(ISchemaWrapper $schema): bool {
		$table   = $schema->getTable('keepiq_secrets');
		$changed = false;
		if ($table->hasColumn('is_favourite') === false) {
			// Nextcloud boolean columns must be nullable (Oracle has no false).
			$table->addColumn('is_favourite', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}

		if ($table->hasColumn('last_used_at') === false) {
			$table->addColumn('last_used_at', Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}

		return $changed;
	}//end addSecretColumns()
}//end class
