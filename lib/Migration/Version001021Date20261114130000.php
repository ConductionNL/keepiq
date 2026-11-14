<?php

/**
 * Keepiq Migration: per-item master password re-prompt
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `reprompt` to `keepiq_secrets`: whether the holder asks to type the
 * master password again before the item is shown, copied, edited or filled.
 * Every existing row starts without it.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-1.1
 */
class Version001021Date20261114130000 extends SimpleMigrationStep {
	/**
	 * The secrets table.
	 *
	 * @var string
	 */
	public const TABLE = 'keepiq_secrets';

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
	 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-1.1
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// The closure returns the current schema.
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return null;
		}

		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn('reprompt') === true) {
			return null;
		}

		$table->addColumn('reprompt', Types::BOOLEAN, ['notnull' => false, 'default' => false]);

		return $schema;
	}//end changeSchema()
}//end class
