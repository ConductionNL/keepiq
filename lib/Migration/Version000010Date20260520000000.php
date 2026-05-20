<?php

/**
 * Doriath Migration Version 10
 *
 * Add custom_icon and custom_color columns to the doriath_folders table.
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
 * Add custom_icon and custom_color columns to the doriath_folders table.
 */
class Version000010Date20260520000000 extends SimpleMigrationStep
{
    /**
     * Add the custom icon and custom color columns to the folders table.
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

        if ($schema->hasTable('doriath_folders') === false) {
            return null;
        }

        $table   = $schema->getTable('doriath_folders');
        $changed = false;

        if ($table->hasColumn('custom_icon') === false) {
            $table->addColumn(
                    'custom_icon',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                    ]
                    );
            $changed = true;
        }

        if ($table->hasColumn('custom_color') === false) {
            $table->addColumn(
                    'custom_color',
                    Types::STRING,
                    [
                        'notnull' => false,
                        'length'  => 255,
                    ]
                    );
            $changed = true;
        }

        if ($changed === false) {
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
