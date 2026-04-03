<?php

/**
 * Doriath Migration Version 9
 *
 * Create the doriath_sec_deleg table.
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
 * Create the doriath_sec_deleg table.
 */
class Version000009Date20260331000008 extends SimpleMigrationStep
{
    /**
     * Create the schema for secret delegations.
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

        if ($schema->hasTable('doriath_sec_deleg') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_sec_deleg');

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
                'original_owner_id',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'delegated_to',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'delegated_at',
                Types::DATETIME,
                [
                    'notnull' => true,
                ]
                );
        $table->addColumn(
                'initiated_by',
                Types::STRING,
                [
                    'notnull' => true,
                    'length'  => 64,
                ]
                );
        $table->addColumn(
                'is_permanent',
                Types::BOOLEAN,
                [
                    'notnull' => false,
                    'default' => false,
                ]
                );
        $table->addColumn(
                'made_permanent_at',
                Types::DATETIME,
                [
                    'notnull' => false,
                ]
                );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['secret_id'], 'doriath_sd_secret_idx');

        return $schema;
    }//end changeSchema()
}//end class
