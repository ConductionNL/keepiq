<?php

declare(strict_types=1);

namespace OCA\Doriath\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the doriath_ca_certificates table.
 */
class Version000002Date20260331000001 extends SimpleMigrationStep
{
    /**
     * @param IOutput        $output
     * @param Closure        $schemaClosure
     * @param array<string,mixed> $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('doriath_ca_certificates') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_ca_certificates');

        $table->addColumn('id', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('type', Types::STRING, [
            'notnull' => true,
            'length'  => 20,
        ]);
        $table->addColumn('certificate', Types::TEXT, [
            'notnull' => true,
        ]);
        $table->addColumn('private_key', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('expires_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('is_active', Types::BOOLEAN, [
            'notnull' => true,
            'default' => false,
        ]);
        $table->addColumn('revoked_at', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('successor_id', Types::STRING, [
            'notnull' => false,
            'length'  => 36,
        ]);

        $table->setPrimaryKey(['id']);

        return $schema;
    }//end changeSchema()
}//end class
