<?php

/**
 * Keepiq migration: change times of the TOTP and passkeys value fields
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
 * Adds the nullable `totp_updated_at` and `passkeys_updated_at` columns to `keepiq_secrets`: when each field's
 * ciphertext last changed, as `key_updated_at` records it for `key`. A rotation proof and the possibly-compromised
 * warning read them (keepiq#1325). Existing rows have neither.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 */
class Version001017Date20261028130000 extends SimpleMigrationStep {
	/**
	 * The table that gets the columns.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_secrets';

	/**
	 * The columns this step adds.
	 *
	 * @var string[]
	 */
	public const COLUMNS = ['totp_updated_at', 'passkeys_updated_at'];

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
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table   = $schema->getTable(self::TABLE);
		$changed = false;
		foreach (self::COLUMNS as $column) {
			if ($table->hasColumn($column) === false) {
				$table->addColumn($column, Types::DATETIME, ['notnull' => false]);
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
