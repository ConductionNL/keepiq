<?php

/**
 * Keepiq Migration: extension passkey challenges and app password binding
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `keepiq_webauthn_challenges`, the server-side store of extension
 * passkey challenges, and `token_id` and `user_handle` to the passkey
 * credentials. Extension credentials enrolled before were bound to the
 * extension's own relying party, which is no longer accepted, so active
 * ones without a token id are marked stale and the user enrols again.
 *
 * @psalm-suppress UnusedClass Loaded by the Nextcloud migration framework.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
 */
class Version001019Date20261111130000 extends SimpleMigrationStep {
	/**
	 * The credentials table.
	 *
	 * @var string
	 */
	public const CREDENTIALS = 'keepiq_passkey_credentials';

	/**
	 * The challenges table.
	 *
	 * @var string
	 */
	public const CHALLENGES = 'keepiq_webauthn_challenges';

	/**
	 * Constructor for the migration.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Add the table and the columns where they are missing.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;
		if ($schema->hasTable(self::CREDENTIALS) === true) {
			$table = $schema->getTable(self::CREDENTIALS);
			if ($table->hasColumn('token_id') === false) {
				$table->addColumn('token_id', Types::BIGINT, ['notnull' => false]);
				$changed = true;
			}

			if ($table->hasColumn('user_handle') === false) {
				$table->addColumn('user_handle', Types::STRING, ['notnull' => false, 'length' => 128]);
				$changed = true;
			}
		}

		if ($schema->hasTable(self::CHALLENGES) === false) {
			$table = $schema->createTable(self::CHALLENGES);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('token_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('purpose', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('challenge', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('assertion_challenge', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['uid', 'token_id', 'purpose'], 'keepiq_wac_binding');
			$table->addIndex(['token_id'], 'keepiq_wac_token');
			$table->addIndex(['expires_at'], 'keepiq_wac_expires');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()

	/**
	 * Mark active extension credentials without an app password binding stale.
	 *
	 * @param IOutput $output        The migration output
	 * @param Closure $schemaClosure Returns the current schema
	 * @param array   $options       Migration options
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by SimpleMigrationStep.
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if ($this->db->tableExists(self::CREDENTIALS) === false) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::CREDENTIALS)
			->set('status', $qb->createNamedParameter('stale'))
			->where($qb->expr()->eq('client_kind', $qb->createNamedParameter('extension')))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('active')))
			->andWhere($qb->expr()->isNull('token_id'));
		$marked = $qb->executeStatement();
		if ($marked > 0) {
			$output->info('Marked ' . $marked . ' extension passkey(s) stale; their owners enrol again.');
		}
	}//end postSchemaChange()
}//end class
