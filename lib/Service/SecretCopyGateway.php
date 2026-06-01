<?php

/**
 * Doriath Secret Copy Gateway
 *
 * Thin seam over the `doriath_secrets` table for sharing operations that
 * create or delete a recipient's encrypted copy of a shared secret.
 *
 * The Secret entity / SecretMapper are owned by the implement-secrets change.
 * Until that change ships, this gateway operates directly on the
 * `doriath_secrets` table via IDBConnection when it exists, and degrades
 * gracefully (logged warning, no-op) when it does not. This keeps the
 * sharing services functional end-to-end the moment the secrets table is
 * present, without coupling this change to an unshipped PHP class.
 *
 * When implement-secrets lands, this gateway SHOULD be reduced to a thin
 * adapter over SecretMapper (ADR-022 — no parallel persistence path) and the
 * raw-SQL branch removed.
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

use DateTime;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Gateway for creating, updating and deleting recipient secret copies.
 */
class SecretCopyGateway
{
    private const SECRETS_TABLE = 'doriath_secrets';

    /**
     * Constructor for SecretCopyGateway.
     *
     * @param IDBConnection   $db     The database connection
     * @param LoggerInterface $logger The logger interface
     *
     * @return void
     */
    public function __construct(
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Whether the secrets table is available (implement-secrets shipped).
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->db->tableExists(self::SECRETS_TABLE);
    }//end isAvailable()

    /**
     * Create an encrypted copy of a secret in a recipient's vault.
     *
     * The encrypted fields are produced client-side (ADR-003) and passed in
     * as opaque blobs; the server only persists them.
     *
     * @param string              $targetUserId The recipient user ID
     * @param string              $suiteId      The recipient's encryption suite ID
     * @param array<string,mixed> $encrypted    Encrypted blobs + metadata: keys
     *                                          encrypted_key, encrypted_login,
     *                                          encrypted_additional_fields, name,
     *                                          url, type_id, folder_id
     *
     * @return string|null The new secret copy ID, or null when the secrets
     *                     table is not yet available
     */
    public function createCopy(string $targetUserId, string $suiteId, array $encrypted): ?string
    {
        if ($this->isAvailable() === false) {
            $this->logger->warning(
                'Doriath: secrets table not present; share copy creation deferred until implement-secrets ships',
                ['targetUserId' => $targetUserId]
            );
            return null;
        }

        $copyId = Uuid::uuid4()->toString();
        $qb     = $this->db->getQueryBuilder();
        $qb->insert(self::SECRETS_TABLE)
            ->values(
                [
                    'id'                          => $qb->createNamedParameter($copyId),
                    'owner_type'                  => $qb->createNamedParameter('user'),
                    'owner_id'                    => $qb->createNamedParameter($targetUserId),
                    'encryption_suite_id'         => $qb->createNamedParameter($suiteId),
                    'name'                        => $qb->createNamedParameter((string) ($encrypted['name'] ?? '')),
                    'url'                         => $qb->createNamedParameter($encrypted['url'] ?? null),
                    'type_id'                     => $qb->createNamedParameter($encrypted['type_id'] ?? null),
                    'folder_id'                   => $qb->createNamedParameter($encrypted['folder_id'] ?? null),
                    'encrypted_key'               => $qb->createNamedParameter($encrypted['encrypted_key'] ?? null),
                    'encrypted_login'             => $qb->createNamedParameter($encrypted['encrypted_login'] ?? null),
                    'encrypted_additional_fields' => $qb->createNamedParameter($encrypted['encrypted_additional_fields'] ?? null),
                    'created_at'                  => $qb->createNamedParameter(
                        (new DateTime())->format('Y-m-d H:i:s')
                    ),
                    'updated_at'                  => $qb->createNamedParameter(
                        (new DateTime())->format('Y-m-d H:i:s')
                    ),
                ]
            );
        $qb->executeStatement();

        return $copyId;
    }//end createCopy()

    /**
     * Overwrite the encrypted blobs of an existing secret copy (sync-on-update).
     *
     * @param string              $secretId  The secret copy ID
     * @param array<string,mixed> $encrypted Encrypted blobs: encrypted_key,
     *                                       encrypted_login,
     *                                       encrypted_additional_fields
     *
     * @return void
     */
    public function updateCopyBlobs(string $secretId, array $encrypted): void
    {
        if ($this->isAvailable() === false) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update(self::SECRETS_TABLE)
            ->set('encrypted_key', $qb->createNamedParameter($encrypted['encrypted_key'] ?? null))
            ->set('encrypted_login', $qb->createNamedParameter($encrypted['encrypted_login'] ?? null))
            ->set(
                'encrypted_additional_fields',
                $qb->createNamedParameter($encrypted['encrypted_additional_fields'] ?? null)
            )
            ->set('possibly_compromised_at', $qb->createNamedParameter(null))
            ->set('updated_at', $qb->createNamedParameter((new DateTime())->format('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)));
        $qb->executeStatement();
    }//end updateCopyBlobs()

    /**
     * Delete a recipient's secret copy.
     *
     * @param string $secretId The secret copy ID
     *
     * @return void
     */
    public function deleteCopy(string $secretId): void
    {
        if ($this->isAvailable() === false || $secretId === '') {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete(self::SECRETS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)));
        $qb->executeStatement();
    }//end deleteCopy()

    /**
     * Read the updated_at timestamp of a secret, for optimistic locking.
     *
     * @param string $secretId The secret ID
     *
     * @return string|null The updated_at value, or null when unavailable/missing
     */
    public function getUpdatedAt(string $secretId): ?string
    {
        if ($this->isAvailable() === false || $secretId === '') {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('updated_at')
            ->from(self::SECRETS_TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($secretId)))
            ->setMaxResults(1);

        $result    = $qb->executeQuery();
        $updatedAt = $result->fetchOne();
        $result->closeCursor();

        if ($updatedAt === false) {
            return null;
        }

        return (string) $updatedAt;
    }//end getUpdatedAt()
}//end class
