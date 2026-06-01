<?php

/**
 * Doriath Migration Version 10
 *
 * Create the `doriath_link_shares` table that backs password-protected
 * link sharing of secrets. A link share stores an AES-256-GCM encrypted
 * point-in-time snapshot of a secret together with the Argon2id salt used
 * to derive the snapshot key in the browser, usage/brute-force counters,
 * and an optional expiry. The server never stores the link password or
 * the derived key — only the encrypted blob and the salt.
 *
 * @category Migration
 * @package  OCA\Doriath\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the doriath_link_shares table.
 */
class Version000010Date20260331000009 extends SimpleMigrationStep
{
    /**
     * Create the doriath_link_shares table with its indexes.
     *
     * @param IOutput             $output        The output interface
     * @param Closure             $schemaClosure The schema closure
     * @param array<string,mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  A single declarative table definition; the
     *   length comes entirely from the per-column addColumn() calls, which are clearer kept inline.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_link_shares') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_link_shares');

        $table->addColumn(
                'id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'secret_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'token',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'encrypted_secret_snapshot',
                Types::TEXT,
                [
                    'notnull' => true,
                ]
                );
        $table->addColumn(
                'argon2id_salt',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'encryption_suite_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'usage_limit',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 1,
                ]
                );
        $table->addColumn(
                'usage_count',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 0,
                ]
                );
        $table->addColumn(
                'failed_attempts',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 0,
                ]
                );
        $table->addColumn(
                'blob_fetched',
                Types::BOOLEAN,
                [
                    'notnull' => true,
                    'default' => false,
                ]
                );
        $table->addColumn(
                'created_by',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'created_at',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
                );
        $table->addColumn(
                'expires_at',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['token'], 'doriath_ls_token_idx');
        $table->addIndex(['secret_id'], 'doriath_ls_secret_idx');
        $table->addIndex(['created_by'], 'doriath_ls_creator_idx');

        return $schema;
    }//end changeSchema()
}//end class
