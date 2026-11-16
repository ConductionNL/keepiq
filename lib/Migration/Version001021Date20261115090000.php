<?php

/**
 * Keepiq Migration: privileged logins and their access requests
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
 * Adds `keepiq_privileged_logins`, the owner's mark that a shared login
 * needs an approved time window, and `keepiq_access_requests`, a
 * recipient's request for such a window and the owner's answer
 * (capability-requirements-from-the-matrix task 3.1).
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */
class Version001021Date20261115090000 extends SimpleMigrationStep {
	/**
	 * The flags table, without prefix.
	 *
	 * @var string
	 */
	public const FLAGS_TABLE = 'keepiq_privileged_logins';

	/**
	 * The requests table, without prefix.
	 *
	 * @var string
	 */
	public const REQUESTS_TABLE = 'keepiq_access_requests';

	/**
	 * The flags table's shape, also declared in the consolidated schema
	 * (Version001000Date20260908000000::SCHEMA) so a fresh install and the
	 * backup registry know it.
	 *
	 * @var array<string, list<mixed>>
	 */
	public const FLAGS_DEFINITION = [
		'columns' => [
			['id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
			['created_at', Types::DATETIME, ['notnull' => true]],
		],
		'primary' => ['id'],
		'indexes' => [
			['keepiq_pl_owner', ['owner_id']],
		],
		'uniqueIndexes' => [
			['keepiq_pl_secret', ['secret_id']],
		],
	];

	/**
	 * The requests table's shape, also declared in the consolidated schema.
	 *
	 * @var array<string, list<mixed>>
	 */
	public const REQUESTS_DEFINITION = [
		'columns' => [
			['id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
			['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
			['requester_id', Types::STRING, ['notnull' => true, 'length' => 64]],
			['reason', Types::STRING, ['notnull' => true, 'length' => 500]],
			['status', Types::STRING, ['notnull' => true, 'length' => 16]],
			['requested_start', Types::DATETIME, ['notnull' => true]],
			['requested_end', Types::DATETIME, ['notnull' => true]],
			['approved_start', Types::DATETIME, ['notnull' => false]],
			['approved_end', Types::DATETIME, ['notnull' => false]],
			['ranges', Types::TEXT, ['notnull' => false]],
			['decided_at', Types::DATETIME, ['notnull' => false]],
			['created_at', Types::DATETIME, ['notnull' => true]],
		],
		'primary' => ['id'],
		'indexes' => [
			['keepiq_ar_pair', ['secret_id', 'requester_id']],
			['keepiq_ar_owner', ['owner_id', 'status']],
			['keepiq_ar_requester', ['requester_id']],
		],
		'uniqueIndexes' => [],
	];

	/**
	 * Create the tables when they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema  = $schemaClosure();
		$changed = false;
		foreach ([self::FLAGS_TABLE => self::FLAGS_DEFINITION, self::REQUESTS_TABLE => self::REQUESTS_DEFINITION] as $name => $definition) {
			if ($schema->hasTable($name) === true) {
				continue;
			}

			$table = $schema->createTable($name);
			foreach ($definition['columns'] as [$column, $type, $columnOptions]) {
				$table->addColumn($column, $type, $columnOptions);
			}

			$table->setPrimaryKey($definition['primary']);
			foreach ($definition['indexes'] as [$indexName, $columns]) {
				$table->addIndex($columns, $indexName);
			}

			foreach ($definition['uniqueIndexes'] as [$indexName, $columns]) {
				$table->addUniqueIndex($columns, $indexName);
			}

			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
