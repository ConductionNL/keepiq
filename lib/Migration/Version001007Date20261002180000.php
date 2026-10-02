<?php

/**
 * Keepiq Migration: client kind and relying party on passkey credentials
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
 * Adds `client_kind` (web or extension, default web) and the nullable
 * `rp_id` to the passkey credentials, so the browser extension can enrol
 * its own platform passkey next to the web app's and each client is offered
 * only its own credentials (keepiq#784). Existing rows become web.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
 */
class Version001007Date20261002180000 extends SimpleMigrationStep {

	/**
	 * Add the columns when they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_passkey_credentials') === false) {
			return null;
		}

		$table   = $schema->getTable('keepiq_passkey_credentials');
		$changed = false;
		if ($table->hasColumn('client_kind') === false) {
			$table->addColumn('client_kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'web']);
			$changed = true;
		}

		if ($table->hasColumn('rp_id') === false) {
			$table->addColumn('rp_id', Types::STRING, ['notnull' => false, 'length' => 255]);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
