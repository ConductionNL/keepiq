<?php

/**
 * Doriath Migration Version 6
 *
 * Create the doriath_folders table.
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
 * Create the doriath_folders table.
 */
class Version000006Date20260601000001 extends SimpleMigrationStep
{
    /**
     * Change the database schema to add the folders table.
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
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_folders') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_folders');

        $table->addColumn(
                'id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'name',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 255,
                ]
                );
        $table->addColumn(
                'parent_id',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 36,
                ]
                );
        $table->addColumn(
                'owner_type',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 16,
                    'default' => 'user',
                ]
                );
        $table->addColumn(
                'owner_id',
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
                'updated_at',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner_type', 'owner_id', 'parent_id'], 'doriath_fld_owner_parent_idx');
        $table->addIndex(['parent_id'], 'doriath_fld_parent_idx');

        return $schema;
    }//end changeSchema()
}//end class
