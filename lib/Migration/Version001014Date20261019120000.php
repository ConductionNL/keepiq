<?php

/**
 * Keepiq migration: the maker of a link share that was handed over
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
 * Adds `original_created_by` to `keepiq_link_shares`, on every install: the
 * user who made a link that an ownership transfer handed to someone else, so
 * that user's compromise revoke still cuts it. Null when the holder made the
 * link; LinkShareMapper::DELETED_ACCOUNT_MARKER once the maker's account is
 * deleted.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
 */
class Version001014Date20261019120000 extends SimpleMigrationStep {
	/**
	 * The index the compromise revoke and account deletion look the column up by.
	 *
	 * @var string
	 */
	public const ORIGINAL_CREATOR_INDEX = 'keepiq_ls_orig_creator_idx';

	/**
	 * Add the column and its index where they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable('keepiq_link_shares') === false) {
			return null;
		}

		$table   = $schema->getTable('keepiq_link_shares');
		$changed = false;
		if ($table->hasColumn('original_created_by') === false) {
			$table->addColumn('original_created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}

		if ($table->hasIndex(self::ORIGINAL_CREATOR_INDEX) === false) {
			$table->addIndex(['original_created_by'], self::ORIGINAL_CREATOR_INDEX);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
