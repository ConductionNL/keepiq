<?php

/**
 * Doriath Secret Ownership Resolver
 *
 * Resolves the original owner of a secret and the active encryption suite of a
 * recipient — the two server-side facts the sharing authorization model needs.
 *
 * Like SecretCopyGateway, this is a seam over the implement-secrets data model.
 * Ownership is read from `doriath_secrets.owner_id`; recipient suites are read
 * from the EncryptionSuiteMapper which already exists. When the secrets table is
 * absent (implement-secrets not yet shipped) ownership cannot be resolved and
 * the caller MUST treat the operation as unauthorized.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IDBConnection;

/**
 * Resolves secret ownership and recipient encryption suites.
 */
class SecretOwnershipResolver
{
    private const SECRETS_TABLE = 'doriath_secrets';

    /**
     * Constructor for SecretOwnershipResolver.
     *
     * @param IDBConnection          $db              The database connection
     * @param EncryptionSuiteMapper  $suiteMapper     The encryption suite mapper
     * @param SecretDelegationMapper $delegationMapper The delegation mapper
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private EncryptionSuiteMapper $suiteMapper,
        private SecretDelegationMapper $delegationMapper,
    ) {
    }//end __construct()

    /**
     * Resolve the original owner user ID of a secret, or null if unknown.
     *
     * @param string $secretId The secret ID
     *
     * @return string|null
     */
    public function getOwnerId(string $secretId): ?string
    {
        if ($this->db->tableExists(self::SECRETS_TABLE) === false) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('owner_id')
            ->from(self::SECRETS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)))
            ->setMaxResults(1);

        $result  = $qb->executeQuery();
        $ownerId = $result->fetchOne();
        $result->closeCursor();

        return ($ownerId === false ? null : (string) $ownerId);
    }//end getOwnerId()

    /**
     * Resolve the encryption suite ID a recipient secret copy uses.
     *
     * @param string $secretId The secret copy ID
     *
     * @return string|null
     */
    public function getSuiteId(string $secretId): ?string
    {
        if ($this->db->tableExists(self::SECRETS_TABLE) === false) {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('encryption_suite_id')
            ->from(self::SECRETS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)))
            ->setMaxResults(1);

        $result  = $qb->executeQuery();
        $suiteId = $result->fetchOne();
        $result->closeCursor();

        return ($suiteId === false ? null : (string) $suiteId);
    }//end getSuiteId()

    /**
     * Whether a user can manage shares of a secret (owner or active delegate).
     *
     * @param string $secretId The secret ID
     * @param string $userId   The acting user ID
     *
     * @return bool
     */
    public function canManageShares(string $secretId, string $userId): bool
    {
        $ownerId = $this->getOwnerId($secretId);
        if ($ownerId !== null && $ownerId === $userId) {
            return true;
        }

        return $this->delegationMapper->findActiveBySecretAndUser($secretId, $userId) !== null;
    }//end canManageShares()

    /**
     * Whether a user is the original owner of a secret.
     *
     * @param string $secretId The secret ID
     * @param string $userId   The acting user ID
     *
     * @return bool
     */
    public function isOwner(string $secretId, string $userId): bool
    {
        return $this->getOwnerId($secretId) === $userId;
    }//end isOwner()

    /**
     * Fetch a recipient's active encryption suite, or null if none.
     *
     * @param string $userId The recipient user ID
     *
     * @return EncryptionSuite|null
     */
    public function getActiveSuiteForUser(string $userId): ?EncryptionSuite
    {
        try {
            return $this->suiteMapper->findActiveByOwner('user', $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException $e) {
            return null;
        }
    }//end getActiveSuiteForUser()
}//end class
