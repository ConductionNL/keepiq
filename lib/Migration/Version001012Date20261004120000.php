<?php

/**
 * Keepiq migration: federated share tables and the secrets federation columns
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
 * Adds, on existing installs, the two tables of federated sharing
 * (sharing-federated-recipients, Migration section of the design):
 *
 * - `keepiq_federated_shares`: the sending side, one row per secret shared
 *   with a user of a partner instance, holding the latest ciphertext made in
 *   the owner's browser for that recipient and the hash of the share's shared
 *   secret, plus the state of a notification still to be delivered.
 * - `keepiq_federated_inbound`: the receiving side, one row per share a
 *   partner announced, with the shared secret encrypted by ICrypto so the
 *   server can present it unattended.
 *
 * And it adds `federated_source` (the sender's cloud id) and `read_only` to
 * `keepiq_secrets`. Fresh installs get the tables from Version001000's
 * SCHEMA; the two columns come from this step on every install, as the
 * use-only columns come from Version001008.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class Version001012Date20261004120000 extends SimpleMigrationStep {
	/**
	 * The two tables, as Version001000's SCHEMA declares them.
	 *
	 * @var array<string,array{columns:list<array{0:string,1:string,2:array<string,mixed>}>,primary:list<string>,indexes:list<array{0:string,1:list<string>}>,uniqueIndexes:list<array{0:string,1:list<string>}>}>
	 */
	private const TABLES = [
		'keepiq_federated_shares' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['source_secret_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['owner_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['recipient_cloud_id', Types::STRING, ['notnull' => true, 'length' => 255]],
				['partner_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['recipient_cert_fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]],
				['key', Types::TEXT, ['notnull' => false]],
				['login', Types::TEXT, ['notnull' => false]],
				['additional_fields', Types::TEXT, ['notnull' => false]],
				['shared_secret_hash', Types::STRING, ['notnull' => true, 'length' => 64]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']],
				['pending_notification', Types::STRING, ['notnull' => false, 'length' => 32]],
				['notify_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]],
				['next_notify_at', Types::DATETIME, ['notnull' => false]],
				['created_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => true]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_fs_source_idx', ['source_secret_id']],
				['keepiq_fs_owner_idx', ['owner_id']],
				['keepiq_fs_partner_idx', ['partner_id']],
				['keepiq_fs_notify_idx', ['next_notify_at']],
			],
			'uniqueIndexes' => [],
		],
		'keepiq_federated_inbound' => [
			'columns' => [
				['id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['recipient_uid', Types::STRING, ['notnull' => true, 'length' => 64]],
				['sender_cloud_id', Types::STRING, ['notnull' => true, 'length' => 255]],
				['partner_id', Types::STRING, ['notnull' => true, 'length' => 36]],
				['remote_share_id', Types::STRING, ['notnull' => true, 'length' => 64]],
				['name', Types::STRING, ['notnull' => true, 'length' => 255]],
				['shared_secret_enc', Types::TEXT, ['notnull' => true]],
				['secret_id', Types::STRING, ['notnull' => false, 'length' => 36]],
				['status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']],
				['received_at', Types::DATETIME, ['notnull' => true]],
				['updated_at', Types::DATETIME, ['notnull' => false]],
			],
			'primary' => ['id'],
			'indexes' => [
				['keepiq_fi_recipient_idx', ['recipient_uid']],
				['keepiq_fi_secret_idx', ['secret_id']],
			],
			'uniqueIndexes' => [
				['keepiq_fi_remote_uniq', ['partner_id', 'remote_share_id']],
			],
		],
	];

	/**
	 * Create the tables and add the columns where they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema  = $schemaClosure();
		$created = $this->createMissingTables(schema: $schema);
		$added   = $this->addSecretColumns(schema: $schema);

		if ($created === false && $added === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Create each of the two tables that is missing.
	 *
	 * @param ISchemaWrapper $schema The schema
	 *
	 * @return bool Whether a table was created
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	private function createMissingTables(ISchemaWrapper $schema): bool {
		$changed = false;
		foreach (self::TABLES as $name => $spec) {
			if ($schema->hasTable($name) === true) {
				continue;
			}

			$table = $schema->createTable($name);
			foreach ($spec['columns'] as [$column, $type, $columnOptions]) {
				$table->addColumn($column, $type, $columnOptions);
			}

			$table->setPrimaryKey($spec['primary']);
			foreach ($spec['indexes'] as [$indexName, $columns]) {
				$table->addIndex($columns, $indexName);
			}

			foreach ($spec['uniqueIndexes'] as [$indexName, $columns]) {
				$table->addUniqueIndex($columns, $indexName);
			}

			$changed = true;
		}//end foreach

		return $changed;
	}//end createMissingTables()

	/**
	 * Add `federated_source` and `read_only` to secrets where missing.
	 *
	 * @param ISchemaWrapper $schema The schema
	 *
	 * @return bool Whether a column was added
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-remote-copies-are-read-only
	 */
	private function addSecretColumns(ISchemaWrapper $schema): bool {
		if ($schema->hasTable('keepiq_secrets') === false) {
			return false;
		}

		$secrets = $schema->getTable('keepiq_secrets');
		$changed = false;
		if ($secrets->hasColumn('federated_source') === false) {
			$secrets->addColumn('federated_source', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}

		if ($secrets->hasColumn('read_only') === false) {
			// Nextcloud boolean columns must be nullable (Oracle has no false).
			$secrets->addColumn('read_only', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$changed = true;
		}

		return $changed;
	}//end addSecretColumns()
}//end class
