<?php

/**
 * Doriath Share Service
 *
 * Smallest scaffold for the user-to-user secret-sharing capability —
 * provides create/list/revoke methods over ShareTarget rows. The full
 * sharing graph (group shares, delegations, notifications, sync-on-update,
 * cascade-on-suite-revocation) is deferred to the coordinated build cycle
 * in implement-user-sharing.
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
use InvalidArgumentException;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Business logic for user-to-user secret-share lifecycle (scaffold).
 *
 * Authorization at this layer is intentionally minimal: every method takes
 * an explicit $userId and the caller (controller) is responsible for
 * resolving the current user. Owner-vs-recipient policy (full IDOR matrix,
 * delegation power-grab, group expansion) is deferred to the coordinated
 * sharing build cycle.
 */
class ShareService
{
    /**
     * Constructor for ShareService.
     *
     * @param ShareTargetMapper $mapper The share-target mapper
     * @param LoggerInterface   $logger The logger interface
     *
     * @return void
     */
    public function __construct(
        private ShareTargetMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Create a single share target record.
     *
     * The caller (controller) is responsible for:
     *  - confirming the user owns $sourceSecretId,
     *  - confirming the recipient has an active EncryptionSuite,
     *  - creating the recipient's encrypted Secret copy ($recipientSecretId),
     *  - persisting the resulting Secret row.
     *
     * This service only records the share-target row.
     *
     * @param string      $sourceSecretId    The owner's source secret ID
     * @param string      $targetUserId      The recipient's Nextcloud user ID
     * @param string      $recipientSecretId The recipient's encrypted Secret copy ID
     * @param string|null $groupShareId      Optional group-share linkage
     * @param string      $userId            The Nextcloud user ID creating the share
     *
     * @return ShareTarget
     *
     * @throws InvalidArgumentException When validation fails
     */
    public function createShare(
        string $sourceSecretId,
        string $targetUserId,
        string $recipientSecretId,
        ?string $groupShareId,
        string $userId,
    ): ShareTarget {
        if ($sourceSecretId === '') {
            throw new InvalidArgumentException(message: 'sourceSecretId is required');
        }

        if ($targetUserId === '') {
            throw new InvalidArgumentException(message: 'targetUserId is required');
        }

        if ($targetUserId === $userId) {
            throw new InvalidArgumentException(message: 'Cannot share a secret with yourself');
        }

        if ($recipientSecretId === '') {
            throw new InvalidArgumentException(message: 'recipientSecretId is required');
        }

        $entity = new ShareTarget();
        $entity->setId(Uuid::uuid4()->toString());
        $entity->setSourceSecretId($sourceSecretId);
        $entity->setTargetUserId($targetUserId);
        $entity->setSecretId($recipientSecretId);
        $entity->setGroupShareId($groupShareId);
        $entity->setCreatedBy($userId);
        $entity->setCreatedAt(new DateTime());

        return $this->mapper->insert($entity);
    }//end createShare()

    /**
     * List all share targets for a given source secret.
     *
     * The caller (controller) must enforce that only the secret owner sees
     * the recipient list; this service returns the raw list.
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return ShareTarget[]
     */
    public function listSharesForSecret(string $sourceSecretId): array
    {
        return $this->mapper->findBySourceSecret($sourceSecretId);
    }//end listSharesForSecret()

    /**
     * Revoke a single share target.
     *
     * The caller (controller) must enforce that only the secret owner (or a
     * future delegate) can revoke. This service only validates that the
     * row exists.
     *
     * @param string $shareId The share-target row ID
     * @param string $userId  The Nextcloud user ID requesting the revoke
     *
     * @return void
     *
     * @throws InvalidArgumentException When the row does not exist
     */
    public function revokeShare(string $shareId, string $userId): void
    {
        try {
            $entity = $this->mapper->findById($shareId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Share not found');
        }

        // Scaffold authorization: only the row's creator can revoke. The
        // full owner/delegate policy ships with implement-user-sharing.
        if ($entity->getCreatedBy() !== $userId) {
            throw new InvalidArgumentException(message: 'Not authorized to revoke this share');
        }

        $this->mapper->delete($entity);
        $this->logger->info(
            'Revoked share '.$shareId.' for source '.$entity->getSourceSecretId(),
            ['app' => 'doriath']
        );
    }//end revokeShare()

    /**
     * Cascade-delete all share targets for a secret (called on secret delete).
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return void
     */
    public function deleteAllForSecret(string $sourceSecretId): void
    {
        $this->mapper->deleteBySourceSecret($sourceSecretId);
    }//end deleteAllForSecret()
}//end class
