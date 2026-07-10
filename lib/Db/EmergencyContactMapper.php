<?php

/**
 * Doriath Emergency Contact Mapper
 *
 * Database mapper for emergency-contact designations.
 *
 * @category Db
 * @package  OCA\Doriath\Db
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

namespace OCA\Doriath\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for EmergencyContact entities.
 *
 * @extends QBMapper<EmergencyContact>
 */
class EmergencyContactMapper extends QBMapper
{
    /**
     * Constructor for EmergencyContactMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_emergency_contacts', entityClass: EmergencyContact::class);
    }//end __construct()

    /**
     * Find an emergency-contact designation by its UUID.
     *
     * @param string $id The designation ID
     *
     * @return EmergencyContact
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): EmergencyContact
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all designations owned by a given user (contacts they named).
     *
     * @param string $ownerId The owner's Nextcloud user ID
     *
     * @return EmergencyContact[]
     */
    public function findByOwner(string $ownerId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)));

        return $this->findEntities(query: $qb);
    }//end findByOwner()

    /**
     * Find all designations naming a given user as the contact (vaults they
     * may be able to recover).
     *
     * @param string $contactId The contact's Nextcloud user ID
     *
     * @return EmergencyContact[]
     */
    public function findByContact(string $contactId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId)));

        return $this->findEntities(query: $qb);
    }//end findByContact()

    /**
     * Find an active designation for a specific owner/contact pair.
     *
     * @param string $ownerId   The owner's Nextcloud user ID
     * @param string $contactId The contact's Nextcloud user ID
     *
     * @return EmergencyContact
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findActiveForPair(string $ownerId, string $contactId): EmergencyContact
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('owner_id', $qb->createNamedParameter($ownerId)))
            ->andWhere($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(EmergencyContact::STATUS_ACTIVE)));

        return $this->findEntity(query: $qb);
    }//end findActiveForPair()
}//end class
