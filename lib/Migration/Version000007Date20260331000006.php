<?php

/**
 * Doriath Migration Version 7
 *
 * Create the doriath_secret_shares table for user-to-user secret sharing.
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
 * Create the doriath_secret_shares table.
 */
class Version000007Date20260331000006 extends SimpleMigrationStep
{
    /**
     * Change the database schema to add the secret shares table.
     *
     * @param IOutput             $output        The output interface
     * @param Closure             $schemaClosure The schema closure
     * @param array<string,mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_secret_shares') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_secret_shares');

        $table->addColumn(
                'id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'source_secret_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'target_user_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'secret_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'group_share_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'created_at',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['source_secret_id'], 'doriath_ss_source_idx');
        $table->addIndex(['target_user_id'], 'doriath_ss_target_idx');
        $table->addIndex(['group_share_id'], 'doriath_ss_gshare_idx');

        return $schema;
    }//end changeSchema()
}//end class
