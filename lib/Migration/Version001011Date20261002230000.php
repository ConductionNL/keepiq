<?php

/**
 * Keepiq migration: federation partners table
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
 * Adds `keepiq_federation_partners` on existing installs: the partner
 * instances an administrator approved, with the pinned root fingerprint and
 * the outbound and inbound permissions (sharing-federated-recipients D1).
 * Fresh installs get it from Version001000's SCHEMA.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class Version001011Date20261002230000 extends SimpleMigrationStep {

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
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_federation_partners') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_federation_partners');
		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('base_url', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('host', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('root_fingerprint', Types::STRING, ['notnull' => true, 'length' => 64]);
		// Nextcloud boolean columns must be nullable (Oracle has no false).
		$table->addColumn('allow_outbound', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('allow_inbound', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('added_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('added_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['host'], 'keepiq_fedp_host_uniq');

		return $schema;
	}//end changeSchema()
}//end class
