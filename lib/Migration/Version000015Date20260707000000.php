<?php

/**
 * Doriath Migration Version 15
 *
 * Create the doriath_emergency_contacts and doriath_emergency_requests
 * tables backing the emergency-access feature (recover vault access when
 * the owner is unavailable — Bitwarden-style trusted-contact takeover).
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
 * Create the emergency-access tables.
 *
 * Table doriath_emergency_contacts stores a designation (owner names a
 * trusted contact, a wait period, and an access level) plus the owner's vault
 * key material re-wrapped under the contact's suite public key at confirmation
 * time. Table doriath_emergency_requests stores a contact-initiated request
 * whose wait-period timer, once elapsed without owner rejection, auto-grants.
 */
class Version000015Date20260707000000 extends SimpleMigrationStep
{
    /**
     * Change the database schema to add the emergency-access tables.
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

        if ($schema->hasTable('doriath_emergency_contacts') === false) {
            $contacts = $schema->createTable('doriath_emergency_contacts');
            $contacts->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
            $contacts->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $contacts->addColumn('contact_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $contacts->addColumn('wait_period_hours', Types::INTEGER, ['notnull' => true, 'default' => 24]);
            $contacts->addColumn('level', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'view']);
            $contacts->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'pending-confirmation']);
            $contacts->addColumn('wrapped_key_material', Types::TEXT, ['notnull' => false]);
            $contacts->addColumn('contact_suite_fingerprint', Types::STRING, ['notnull' => false, 'length' => 128]);
            $contacts->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $contacts->addColumn('confirmed_at', Types::DATETIME, ['notnull' => false]);
            $contacts->setPrimaryKey(['id']);
            $contacts->addIndex(['owner_id'], 'doriath_ec_owner_idx');
            $contacts->addIndex(['contact_id'], 'doriath_ec_contact_idx');
            $contacts->addIndex(['owner_id', 'contact_id'], 'doriath_ec_pair_idx');
        }

        if ($schema->hasTable('doriath_emergency_requests') === false) {
            $requests = $schema->createTable('doriath_emergency_requests');
            $requests->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
            $requests->addColumn('emergency_contact_id', Types::STRING, ['notnull' => true, 'length' => 36]);
            $requests->addColumn('contact_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $requests->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $requests->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'requested']);
            $requests->addColumn('requested_at', Types::DATETIME, ['notnull' => true]);
            $requests->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
            $requests->setPrimaryKey(['id']);
            $requests->addIndex(['emergency_contact_id'], 'doriath_er_ec_idx');
            $requests->addIndex(['owner_id'], 'doriath_er_owner_idx');
            $requests->addIndex(['contact_id'], 'doriath_er_contact_idx');
            $requests->addIndex(['status'], 'doriath_er_status_idx');
        }

        return $schema;
    }//end changeSchema()
}//end class
