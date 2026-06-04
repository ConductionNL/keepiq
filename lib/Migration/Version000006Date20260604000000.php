<?php

/**
 * Doriath Migration Version 6
 *
 * Create the doriath_applications table for registering external and
 * internal applications as vault consumers.
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
 * Create the doriath_applications table.
 */
class Version000006Date20260604000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema to add the applications table.
     *
     * @param IOutput             $output        The output interface
     * @param Closure             $schemaClosure The schema closure
     * @param array<string,mixed> $options       Migration options
     *
     * @return null|ISchemaWrapper
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Column definitions are inherently verbose.
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_applications') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_applications');

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
            'description',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'type',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 32,
                'default' => 'external',
            ]
        );
        $table->addColumn(
            'status',
            Types::STRING,
            [
                'notnull' => true,
                'length'  => 32,
                'default' => 'pending',
            ]
        );
        $table->addColumn(
            'csr',
            Types::TEXT,
            [
                'notnull' => false,
            ]
        );
        $table->addColumn(
            'registered_by',
            Types::STRING,
            [
                'notnull' => false,
                'length'  => 64,
            ]
        );
        $table->addColumn(
            'approved_by',
            Types::STRING,
            [
                'notnull' => false,
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
            'approved_at',
            Types::DATETIME,
            [
                'notnull' => false,
            ]
        );

        $table->setPrimaryKey(['id']);
        $table->addIndex(['status'], 'doriath_app_status_idx');
        $table->addIndex(['registered_by'], 'doriath_app_regby_idx');

        return $schema;
    }//end changeSchema()
}//end class
