<?php

/**
 * Keepiq Migration: SIEM vendor connectors
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
 * Adds the connector columns to the SIEM sinks table: `format` (json or cef,
 * default json, so every existing sink keeps its behaviour), `credential_enc`
 * (the Splunk HEC token or Sentinel client secret, ICrypto-encrypted) and
 * `connector_options` (non-secret connector settings as JSON).
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/audit-siem-vendor-connectors/specs/siem-vendor-connectors/spec.md
 */
class Version001004Date20261002170000 extends SimpleMigrationStep {

	/**
	 * Add the three columns once.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure $schemaClosure Returns the schema wrapper
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Interface-mandated signature.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_siem_sinks') === false) {
			return null;
		}

		$table = $schema->getTable('keepiq_siem_sinks');
		$changed = false;
		$columns = [
			'format' => [Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'json']],
			'credential_enc' => [Types::TEXT, ['notnull' => false]],
			'connector_options' => [Types::TEXT, ['notnull' => false]],
		];
		foreach ($columns as $name => [$type, $columnOptions]) {
			if ($table->hasColumn($name) === false) {
				$table->addColumn($name, $type, $columnOptions);
				$changed = true;
			}
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
