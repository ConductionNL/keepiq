<?php

/**
 * Keepiq Migration: broker mints
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
 * Adds `keepiq_broker_mints`: for an application-owned secret the credential
 * broker minted, the organisation and the consuming app it was minted for
 * (connection-certificate-expiry-for-integriq task 2.1). No material and no
 * name: only what the expiry answer to integriq is gated on.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
class Version001022Date20261117160000 extends SimpleMigrationStep {
	/**
	 * The table, without prefix.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_broker_mints';

	/**
	 * The table's shape, also declared in the consolidated schema
	 * (Version001000Date20260908000000::SCHEMA) so a fresh install and the
	 * backup registry know it.
	 *
	 * @var array<string, list<mixed>>
	 */
	public const DEFINITION = [
		'columns' => [
			['id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['organisation_id', Types::STRING, ['notnull' => false, 'length' => 255]],
			['consumer', Types::STRING, ['notnull' => false, 'length' => 64]],
			['created_at', Types::DATETIME, ['notnull' => true]],
		],
		'primary' => ['id'],
		'indexes' => [],
		'uniqueIndexes' => [
			['keepiq_bm_secret', ['secret_id']],
		],
	];

	/**
	 * Create the table when it does not exist yet.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array<string, mixed> $options The migration options
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
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
		foreach (self::DEFINITION['uniqueIndexes'] as [$indexName, $columns]) {
			$table->addUniqueIndex($columns, $indexName);
		}

		return $schema;
	}//end changeSchema()
}//end class
