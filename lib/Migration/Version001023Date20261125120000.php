<?php

/**
 * Keepiq Migration: announced root renewals
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
 * Adds the columns for announced root renewals (keepiq#1283). On `keepiq_ca_certs`, a renewed root keeps the
 * signature of the root it replaced. On `keepiq_federation_partners`, a partner's verified renewal waits for an
 * administrator, and this instance's renewal notice to the partner keeps its retry state. Everything here is public
 * (fingerprints, signatures, times) and stored in plain text; every column is nullable, so existing rows have none.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class Version001023Date20261125120000 extends SimpleMigrationStep {
	/**
	 * The columns this step adds, per table.
	 *
	 * @var array<string, list<array{0: string, 1: string, 2: array<string, mixed>}>>
	 */
	public const COLUMNS = [
		'keepiq_ca_certs' => [
			['predecessor_signature', Types::TEXT, ['notnull' => false]],
		],
		'keepiq_federation_partners' => [
			['pending_root_fingerprint', Types::STRING, ['notnull' => false, 'length' => 64]],
			['pending_root_verified_at', Types::DATETIME, ['notnull' => false]],
			['pending_root_window_ends_at', Types::DATETIME, ['notnull' => false]],
			['pending_root_reminded', Types::SMALLINT, ['notnull' => false]],
			['renewal_notice_attempts', Types::SMALLINT, ['notnull' => false]],
			['renewal_notice_next_at', Types::DATETIME, ['notnull' => false]],
		],
	];

	/**
	 * Add the columns where they are missing.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array<string, mixed> $options The migration options
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		foreach (self::COLUMNS as $tableName => $columns) {
			if ($schema->hasTable($tableName) === false) {
				continue;
			}

			$table = $schema->getTable($tableName);
			foreach ($columns as [$column, $type, $columnOptions]) {
				if ($table->hasColumn($column) === false) {
					$table->addColumn($column, $type, $columnOptions);
					$changed = true;
				}
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
