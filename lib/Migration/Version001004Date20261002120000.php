<?php

/**
 * Keepiq Migration: used vault-key proofs
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
 * Adds the `keepiq_used_proofs` table. A unique index on the hash of each
 * consumed vault-key-proof challenge makes every proof single-use, with or
 * without a memcache and across cluster nodes (keepiq#868). Nothing is
 * migrated: proofs in flight at upgrade time live five minutes at most.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
 */
class Version001004Date20261002120000 extends SimpleMigrationStep {

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
	 * @spec openspec/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_used_proofs') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_used_proofs');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('nonce_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['nonce_hash'], 'keepiq_used_proofs_hash');
		$table->addIndex(['expires_at'], 'keepiq_used_proofs_exp');

		return $schema;
	}//end changeSchema()
}//end class
