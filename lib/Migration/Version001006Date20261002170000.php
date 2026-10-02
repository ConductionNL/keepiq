<?php

/**
 * Keepiq Migration: organisation account recovery
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
 * Adds the five account recovery tables to existing installs. A fresh
 * install gets them from Version001000's SCHEMA, which declares the same.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/crypto-organisation-account-recovery/tasks.md#task-1.1
 */
class Version001006Date20261002170000 extends SimpleMigrationStep {

	/**
	 * Create each table that is missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One flat column list per table.
	 *
	 * @spec openspec/changes/crypto-organisation-account-recovery/tasks.md#task-1.1
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema  = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('keepiq_recovery_keys') === false) {
			$table = $schema->createTable('keepiq_recovery_keys');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('certificate', Types::TEXT, ['notnull' => true]);
			$table->addColumn('fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('threshold', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('retired_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['status'], 'keepiq_rk_status_idx');
			$changed = true;
		}

		if ($schema->hasTable('keepiq_recovery_officers') === false) {
			$table = $schema->createTable('keepiq_recovery_officers');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('recovery_key_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('officer_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('officer_suite_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('wrapped_private_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('added_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('added_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['officer_uid'], 'keepiq_ro_officer_idx');
			$table->addUniqueIndex(['recovery_key_id', 'officer_uid'], 'keepiq_ro_key_officer_uniq');
			$changed = true;
		}

		if ($schema->hasTable('keepiq_recovery_enrolments') === false) {
			$table = $schema->createTable('keepiq_recovery_enrolments');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('suite_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('recovery_key_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('envelope', Types::TEXT, ['notnull' => true]);
			$table->addColumn('enrolled_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'keepiq_re_user_idx');
			$table->addIndex(['suite_id'], 'keepiq_re_suite_idx');
			$table->addIndex(['recovery_key_id'], 'keepiq_re_key_idx');
			$changed = true;
		}

		if ($schema->hasTable('keepiq_recovery_requests') === false) {
			$table = $schema->createTable('keepiq_recovery_requests');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('suite_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('enrolment_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('request_public_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('handled_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('sealed_result', Types::TEXT, ['notnull' => false]);
			$table->addColumn('fulfilled_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id'], 'keepiq_rr_user_idx');
			$table->addIndex(['status', 'expires_at'], 'keepiq_rr_status_idx');
			$changed = true;
		}

		if ($schema->hasTable('keepiq_recovery_approvals') === false) {
			$table = $schema->createTable('keepiq_recovery_approvals');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('request_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('officer_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('decision', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['request_id', 'officer_uid'], 'keepiq_ra_request_officer_uniq');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
