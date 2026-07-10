<?php

/**
 * Doriath Emergency Request Mapper
 *
 * Database mapper for emergency-access requests.
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
 * Mapper for EmergencyRequest entities.
 *
 * @extends QBMapper<EmergencyRequest>
 */
class EmergencyRequestMapper extends QBMapper
{
    /**
     * Constructor for EmergencyRequestMapper.
     *
     * @param IDBConnection $db The database connection
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'doriath_emergency_requests', entityClass: EmergencyRequest::class);
    }//end __construct()

    /**
     * Find an emergency-access request by its UUID.
     *
     * @param string $id The request ID
     *
     * @return EmergencyRequest
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     */
    public function findById(string $id): EmergencyRequest
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        return $this->findEntity(query: $qb);
    }//end findById()

    /**
     * Find all requests raised by a given contact.
     *
     * @param string $contactId The contact's Nextcloud user ID
     *
     * @return EmergencyRequest[]
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
     * Find all requests addressed to a given owner.
     *
     * @param string $ownerId The owner's Nextcloud user ID
     *
     * @return EmergencyRequest[]
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
     * Find every request still in the `requested` state — the working set
     * the expiry background job scans for auto-grant.
     *
     * @return EmergencyRequest[]
     */
    public function findAllRequested(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(EmergencyRequest::STATUS_REQUESTED)));

        return $this->findEntities(query: $qb);
    }//end findAllRequested()
}//end class
