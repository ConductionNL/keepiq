<?php

/**
 * Doriath Share Revocation Service
 *
 * Undoing a user-to-user share (implement-user-sharing §3.3): the
 * recipient's encrypted Secret copy, its attachment grants and the
 * share-target row all die together in one transaction, and the
 * cascade used when the source secret itself is deleted.
 *
 * Extracted from ShareService — revocation is the only path that owns a
 * delete cascade across three tables, and it is the only reason the
 * share lifecycle would otherwise need the attachment service.
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

use InvalidArgumentException;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Revokes share targets and cascades their dependent rows.
 */
class ShareRevocationService
{

    /**
     * The share audit trail.
     *
     * @var ShareAuditTrail
     */
    private ShareAuditTrail $auditTrail;

    /**
     * Constructor for ShareRevocationService.
     *
     * @param ShareTargetMapper         $mapper            The share-target mapper
     * @param SecretMapper              $secretMapper      The Secret mapper (recipient copy)
     * @param IDBConnection             $db                The DB connection (revoke transaction)
     * @param LoggerInterface           $logger            The logger interface
     * @param ShareAuthorizationService $auth              The share authorization service
     * @param AttachmentService|null    $attachmentService The attachment service (revoke cascade)
     * @param ShareAuditTrail|null      $auditTrail        The share audit trail
     *
     * @return void
     *
     * @spec exclude Constructor wiring only; the revocation behaviour carries the spec anchors.
     */
    public function __construct(
        private ShareTargetMapper $mapper,
        private SecretMapper $secretMapper,
        private IDBConnection $db,
        private LoggerInterface $logger,
        private ShareAuthorizationService $auth,
        private ?AttachmentService $attachmentService=null,
        ?ShareAuditTrail $auditTrail=null,
    ) {
        $this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
    }//end __construct()

    /**
     * Revoke a single share target — deletes the recipient's encrypted
     * Secret copy and the share-target row in one transaction.
     *
     * @param string $shareId The share-target row ID
     * @param string $userId  The Nextcloud user ID requesting the revoke
     *
     * @return void
     *
     * @throws InvalidArgumentException When the row does not exist or
     *                                  the caller is not authorized.
     *
     * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
     */
    public function revokeShare(string $shareId, string $userId): void
    {
        try {
            $entity = $this->mapper->findById($shareId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException(message: 'Share not found');
        }

        $source = $this->auth->loadSecret(secretId: $entity->getSourceSecretId());
        $this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);

        $this->db->beginTransaction();
        try {
            // Delete the recipient's encrypted Secret copy (best-effort —
            // a stale row is harmless because the Secret is already
            // unreachable through the share index, but a clean delete
            // keeps the table small).
            try {
                $recipientCopy = $this->secretMapper->findById($entity->getSecretId());
                $this->secretMapper->delete($recipientCopy);
            } catch (DoesNotExistException) {
                // Already gone — continue.
            }

            // Attachment grants of the revoked copy go with it; the
            // owner's blob and grants are untouched (encrypted-attachments
            // §3.3).
            $this->attachmentService?->deleteGrantsForSecretCopy($entity->getSecretId());

            $this->mapper->delete($entity);
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }//end try

        $this->logger->info(
            'Revoked share '.$shareId.' for source '.$entity->getSourceSecretId(),
            ['app' => 'doriath']
        );

        $this->auditTrail->recordShareRevoked(
            userId: $userId,
            shareId: $shareId,
            secretName: $source->getName(),
            recipientId: $entity->getTargetUserId(),
        );
    }//end revokeShare()

    /**
     * Cascade-delete all share targets for a secret (called on secret delete).
     *
     * @param string $sourceSecretId The source secret ID
     *
     * @return void
     *
     * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
     */
    public function deleteAllForSecret(string $sourceSecretId): void
    {
        $this->mapper->deleteBySourceSecret($sourceSecretId);
    }//end deleteAllForSecret()
}//end class
