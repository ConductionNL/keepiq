<?php

/**
 * Keepiq migration: the field schemas of a secret request
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
 * Adds the nullable `field_schemas` column to `keepiq_secret_requests`: the schema of each requested custom field,
 * by field name, so the fill page can render and check it. Existing requests have none.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
 */
class Version001018Date20261110120000 extends SimpleMigrationStep {
	/**
	 * The table that gets the column.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_secret_requests';

	/**
	 * The column this step adds.
	 *
	 * @var string
	 */
	public const COLUMN = 'field_schemas';

	/**
	 * Add the column where it is missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/vault-custom-field-kinds-and-ssh-key/specs/vault-custom-fields/spec.md#requirement-a-request-carries-the-schemas-of-its-fields
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === true) {
			$table = $schema->getTable(self::TABLE);
			if ($table->hasColumn(self::COLUMN) === false) {
				$table->addColumn(self::COLUMN, Types::TEXT, ['notnull' => false]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
