<?php

/**
 * Keepiq migration: the staged share-save table
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
 * Adds `keepiq_share_save_stages` on existing installs: the chunks of a
 * share-save too large for one request, held for 30 minutes at most. Fresh
 * installs get it from Version001000's SCHEMA, which reads TABLE below.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */
class Version001013Date20261013120000 extends SimpleMigrationStep {
	/**
	 * The table, in Version001000's SCHEMA shape.
	 *
	 * @var array{columns:list<array{0:string,1:string,2:array<string,mixed>}>,primary:list<string>,indexes:list<array{0:string,1:list<string>}>,uniqueIndexes:list<array{0:string,1:list<string>}>}
	 */
	public const TABLE = [
		'columns' => [
			['id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['stage_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['user_id', Types::STRING, ['notnull' => true, 'length' => 64]],
			['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['base_updated_at', Types::DATETIME, ['notnull' => true]],
			['source_base_updated_at', Types::DATETIME, ['notnull' => false]],
			['entries', Types::TEXT, ['notnull' => true]],
			['copy_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
			['federated_count', Types::INTEGER, ['notnull' => true, 'default' => 0]],
			['expires_at', Types::DATETIME, ['notnull' => true]],
			['created_at', Types::DATETIME, ['notnull' => true]],
		],
		'primary' => ['id'],
		'indexes' => [
			['keepiq_sss_stage_idx', ['stage_id']],
			['keepiq_sss_user_idx', ['user_id', 'secret_id']],
			['keepiq_sss_expires_idx', ['expires_at']],
		],
		'uniqueIndexes' => [],
	];

	/**
	 * Create the table when it is missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_share_save_stages') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_share_save_stages');
		foreach (self::TABLE['columns'] as [$column, $type, $columnOptions]) {
			$table->addColumn($column, $type, $columnOptions);
		}

		$table->setPrimaryKey(self::TABLE['primary']);
		foreach (self::TABLE['indexes'] as [$indexName, $columns]) {
			$table->addIndex($columns, $indexName);
		}

		return $schema;
	}//end changeSchema()
}//end class
