<?php

/**
 * Keepiq Migration: extra website addresses per login
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
 * Adds `keepiq_secret_urls`: the extra website addresses of a login, in
 * plain text like its main address, so search and the extension's match
 * read them without the master password (vault-website-addresses D1).
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */
class Version001020Date20261114090000 extends SimpleMigrationStep {
	/**
	 * The addresses table.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_secret_urls';

	/**
	 * The table's shape, also declared in the consolidated schema
	 * (Version001000Date20260908000000::SCHEMA) so a fresh install and the
	 * backup registry know it.
	 *
	 * @var array<string, list<mixed>>
	 */
	public const DEFINITION = [
		'columns' => [
			['id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]],
			['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
			['url', Types::STRING, ['notnull' => true, 'length' => 512]],
			['position', Types::SMALLINT, ['notnull' => true, 'default' => 0]],
		],
		'primary' => ['id'],
		'indexes' => [
			['keepiq_su_owner_url', ['owner_id', 'url']],
			['keepiq_su_secret', ['secret_id']],
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
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === true) {
			return null;
		}

		$table = $schema->createTable(self::TABLE);
		foreach (self::DEFINITION['columns'] as [$column, $type, $columnOptions]) {
			$table->addColumn($column, $type, $columnOptions);
		}

		$table->setPrimaryKey(self::DEFINITION['primary']);
		foreach (self::DEFINITION['indexes'] as [$indexName, $columns]) {
			$table->addIndex($columns, $indexName);
		}

		return $schema;
	}//end changeSchema()
}//end class
