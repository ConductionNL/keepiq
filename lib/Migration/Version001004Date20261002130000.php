<?php

/**
 * Keepiq Migration: pending extra fields from a filled secret request
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
 * Adds the nullable `pending_additional_fields` column to the secrets table:
 * extra-field ciphertexts a secret request filled in, kept apart from the
 * owner's own blob until the owner's browser merges them (keepiq#750).
 * Existing rows have nothing pending.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
 */
class Version001004Date20261002130000 extends SimpleMigrationStep {

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
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_secrets') === false) {
			return null;
		}

		$table = $schema->getTable('keepiq_secrets');
		if ($table->hasColumn('pending_additional_fields') === true) {
			return null;
		}

		$table->addColumn('pending_additional_fields', Types::TEXT, ['notnull' => false]);

		return $schema;
	}//end changeSchema()
}//end class
