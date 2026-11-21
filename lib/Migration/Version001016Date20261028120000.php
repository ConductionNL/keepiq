<?php

/**
 * Keepiq migration: TOTP and passkeys value fields
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
 * Adds the nullable `totp` and `passkeys` ciphertext columns to every table that holds a copy of a secret's value,
 * and `value_fields` to the federation partners: the value fields a partner advertises it can store (keepiq#1325).
 * Existing rows have neither field.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class Version001016Date20261028120000 extends SimpleMigrationStep {
	/**
	 * The tables that hold a secret's value ciphertext.
	 *
	 * @var string[]
	 */
	public const VALUE_TABLES = ['keepiq_secrets', 'keepiq_secret_versions', 'keepiq_federated_shares'];

	/**
	 * The value columns this step adds to each of VALUE_TABLES.
	 *
	 * @var string[]
	 */
	public const VALUE_COLUMNS = ['totp', 'passkeys'];

	/**
	 * The federation partner table.
	 *
	 * @var string
	 */
	public const PARTNER_TABLE = 'keepiq_federation_partners';

	/**
	 * The partner column holding its advertised value fields as a JSON array.
	 *
	 * @var string
	 */
	public const PARTNER_COLUMN = 'value_fields';

	/**
	 * Add the columns where they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-create-secret
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema  = $schemaClosure();
		$changed = false;

		foreach (self::VALUE_TABLES as $tableName) {
			if ($schema->hasTable($tableName) === false) {
				continue;
			}

			$table = $schema->getTable($tableName);
			foreach (self::VALUE_COLUMNS as $column) {
				if ($table->hasColumn($column) === false) {
					$table->addColumn($column, Types::TEXT, ['notnull' => false]);
					$changed = true;
				}
			}
		}

		if ($schema->hasTable(self::PARTNER_TABLE) === true) {
			$table = $schema->getTable(self::PARTNER_TABLE);
			if ($table->hasColumn(self::PARTNER_COLUMN) === false) {
				$table->addColumn(self::PARTNER_COLUMN, Types::STRING, ['notnull' => false, 'length' => 255]);
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
