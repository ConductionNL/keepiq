<?php

/**
 * Keepiq Migration: signature counter on passkey credentials
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
 * Adds `sign_count` (default 0) to the passkey credentials: the WebAuthn
 * signature counter of the last verified assertion, so an unlock can refuse
 * a counter that does not increase. Existing rows start at 0.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */
class Version001018Date20261111120000 extends SimpleMigrationStep {
	/**
	 * The table that gets the column.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_passkey_credentials';

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
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('sign_count') === true) {
			return null;
		}

		$table->addColumn('sign_count', Types::BIGINT, ['notnull' => true, 'default' => 0, 'unsigned' => true]);

		return $schema;
	}//end changeSchema()
}//end class
