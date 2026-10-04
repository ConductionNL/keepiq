<?php

/**
 * Keepiq Migration: new device approval
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
 * Adds `keepiq_device_approvals` to existing installs. A fresh install gets
 * it from Version001000's SCHEMA, which declares the same table.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/archive/2026-10-04-crypto-new-device-approval/tasks.md#task-1.1
 */
class Version001009Date20261002182000 extends SimpleMigrationStep {

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
	 * @spec openspec/changes/archive/2026-10-04-crypto-new-device-approval/tasks.md#task-1.1
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_device_approvals') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_device_approvals');
		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('client_kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('device_label', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('requester_ip', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('requester_agent', Types::STRING, ['notnull' => true, 'length' => 512]);
		$table->addColumn('request_public_key', Types::TEXT, ['notnull' => true]);
		$table->addColumn('request_secret_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('sealed_unlock_key', Types::TEXT, ['notnull' => false]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id', 'status'], 'keepiq_dev_appr_user_idx');
		$table->addIndex(['status', 'expires_at'], 'keepiq_dev_appr_exp_idx');

		return $schema;
	}//end changeSchema()
}//end class
