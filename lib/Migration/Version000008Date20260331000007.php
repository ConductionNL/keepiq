<?php

/**
 * Doriath Migration Version 8
 *
 * Create the doriath_grp_shares table.
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
 * Create the doriath_grp_shares table.
 */
class Version000008Date20260331000007 extends SimpleMigrationStep
{
    /**
     * Create the schema for group shares.
     *
     * @param IOutput             $output        The output interface
     * @param Closure             $schemaClosure The schema closure
     * @param array<string,mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_grp_shares') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_grp_shares');

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
                'group_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
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

        $table->setPrimaryKey(['id']);
        $table->addIndex(['secret_id', 'group_id'], 'doriath_gs_sec_grp_idx');

        return $schema;
    }//end changeSchema()
}//end class
