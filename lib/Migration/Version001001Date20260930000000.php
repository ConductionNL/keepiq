<?php

/**
 * Keepiq migration: the field list an item type carries.
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
 * Adds a nullable `fields` JSON text column to the secret types table.
 *
 * Built-in types keep a null list and so keep their own forms.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 */
class Version001001Date20260930000000 extends SimpleMigrationStep {

	/**
	 * Add the column when it is missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_secret_types') === false) {
			return null;
		}

		$table = $schema->getTable('keepiq_secret_types');
		if ($table->hasColumn('fields') === true) {
			return null;
		}

		$table->addColumn('fields', Types::TEXT, ['notnull' => false]);

		return $schema;
	}//end changeSchema()
}//end class
