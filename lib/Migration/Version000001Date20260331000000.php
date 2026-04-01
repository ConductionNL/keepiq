<?php

declare(strict_types=1);

namespace OCA\Doriath\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the doriath_encryption_suites table.
 */
class Version000001Date20260331000000 extends SimpleMigrationStep
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

        if ($schema->hasTable('doriath_encryption_suites') === true) {
            return null;
        }

        $table = $schema->createTable('doriath_encryption_suites');

        $table->addColumn('id', Types::STRING, [
            'notnull' => true,
            'length'  => 36,
        ]);
        $table->addColumn('owner_type', Types::STRING, [
            'notnull' => true,
            'length'  => 20,
        ]);
        $table->addColumn('owner_id', Types::STRING, [
            'notnull' => true,
            'length'  => 64,
        ]);
        $table->addColumn('certificate', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('private_key', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length'  => 20,
            'default' => 'active',
        ]);
        $table->addColumn('revoked_at', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('revoked_reason', Types::STRING, [
            'notnull' => false,
            'length'  => 255,
        ]);
        $table->addColumn('revoked_by', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('reinstated_at', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('reinstated_by', Types::STRING, [
            'notnull' => false,
            'length'  => 64,
        ]);
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['owner_type', 'owner_id'], 'doriath_es_owner_idx');

        return $schema;
    }//end changeSchema()
}//end class
