<?php

/**
 * Doriath Secret Version Service
 *
 * Business logic for secret version history (secret-version-history §2/§3):
 * pre-update snapshots (ciphertext copied verbatim — never decrypted),
 * metadata listing, blob reads gated exactly like head reads, and restore
 * (snapshot the current head first, then set the head to the selected
 * version; the CLIENT then drives sync-on-update for recipients).
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretVersion;
use OCA\Doriath\Db\SecretVersionMapper;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the secret-version lifecycle.
 */
class SecretVersionService
{
    /**
     * Constructor for SecretVersionService.
     *
     * @param SecretVersionMapper   $mapper       The version mapper
     * @param SecretMapper          $secretMapper The secret mapper
     * @param EncryptionSuiteMapper $suiteMapper  The suite mapper (read gating)
     * @param LoggerInterface       $logger       The logger
     * @param AuditTrail|null       $auditTrail   The audit trail
     *
     * @return void
     */
    public function __construct(
        private SecretVersionMapper $mapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $suiteMapper,
        private LoggerInterface $logger,
        private ?AuditTrail $auditTrail=null,
    ) {
    }//end __construct()

    /**
     * Snapshot a secret's PRE-UPDATE state as an immutable version. The
     * ciphertext fields are copied verbatim — no decryption anywhere.
     *
     * @param Secret $preUpdate The secret row BEFORE the head overwrite
     * @param string $actorType The actor type (`user`|`application`)
     * @param string $actorId   The actor id
     *
     * @return SecretVersion
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md#requirement-snapshot-on-update
     */
    public function snapshot(Secret $preUpdate, string $actorType, string $actorId): SecretVersion
    {
        $version = new SecretVersion();
        $version->setId(Uuid::uuid4()->toString());
        $version->setSecretId($preUpdate->getId());
        $version->setVersionNumber($this->mapper->nextVersionNumber(secretId: $preUpdate->getId()));
        $version->setName($preUpdate->getName());
        $version->setUrl($preUpdate->getUrl());
        $version->setKey($preUpdate->getKey());
        $version->setLogin($preUpdate->getLogin());
        $version->setAdditionalFields($preUpdate->getAdditionalFields());
        $version->setEncryptionSuiteId($preUpdate->getEncryptionSuiteId());
        $version->setActorType($actorType);
        $version->setActorId($actorId);
        $version->setCreatedAt(new DateTime());

        return $this->mapper->insert($version);
    }//end snapshot()

    /**
     * List a secret's versions (metadata only), newest first — owner-only;
     * an inaccessible secret yields an empty list (no existence oracle).
     *
     * @param string $secretId The secret UUID
     * @param string $userId   The caller
     *
     * @return SecretVersion[]
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md#requirement-listing-and-viewing
     */
    public function list(string $secretId, string $userId): array
    {
        if ($this->isOwned(secretId: $secretId, userId: $userId) === false) {
            return [];
        }

        return $this->mapper->findBySecret(secretId: $secretId);
    }//end list()

    /**
     * One version WITH its ciphertext blobs for client-side decrypt.
     * Refused when the version's wrapping suite is revoked/compromised —
     * matching the head-read posture.
     *
     * @param string $versionId The version UUID
     * @param string $userId    The caller
     *
     * @return SecretVersion
     *
     * @throws InvalidArgumentException On not found / not owned / suite blocked
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md#requirement-listing-and-viewing
     */
    public function getVersion(string $versionId, string $userId): SecretVersion
    {
        $version = $this->loadOwnedVersion(versionId: $versionId, userId: $userId);

        if ($this->isSuiteBlocked(suiteId: $version->getEncryptionSuiteId()) === true) {
            throw new InvalidArgumentException(
                'This version is locked because its encryption suite was revoked'
            );
        }

        return $version;
    }//end getVersion()

    /**
     * Restore a version: snapshot the current head first, then set the
     * head's fields to the version's stored ciphertext. The caller (the
     * browser) then drives the existing sync-on-update fan-out so shared
     * recipients receive the restored value re-encrypted for them.
     *
     * @param string $versionId The version UUID
     * @param string $userId    The caller (must own the secret)
     *
     * @return Secret The updated head
     *
     * @throws InvalidArgumentException On not found / not owned / suite blocked
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md#requirement-restore
     */
    public function restore(string $versionId, string $userId): Secret
    {
        $version = $this->getVersion(versionId: $versionId, userId: $userId);
        $secret  = $this->secretMapper->findById($version->getSecretId());

        // Snapshot the current head so the restore itself is undoable.
        $this->snapshot(preUpdate: $secret, actorType: 'user', actorId: $userId);

        $secret->setName($version->getName());
        $secret->setUrl($version->getUrl());
        $secret->setKey($version->getKey());
        $secret->setLogin($version->getLogin());
        $secret->setAdditionalFields($version->getAdditionalFields());
        $secret->setKeyUpdatedAt(new DateTime());
        $secret->setUpdatedAt(new DateTime());
        $this->secretMapper->update($secret);

        $this->auditTrail?->forUser(
            actorId: $userId,
            eventType: AuditEventTypes::SECRET_VERSION_RESTORED,
            objectType: 'secret',
            objectId: $secret->getId(),
            objectName: $secret->getName(),
            metadata: ['versionNumber' => $version->getVersionNumber()],
        );

        $this->logger->info(
            'Doriath: secret '.$secret->getId().' restored to version '
            .$version->getVersionNumber().' by '.$userId,
            ['app' => 'doriath']
        );

        return $secret;
    }//end restore()

    /**
     * Delete every version of a secret (delete cascade; idempotent).
     *
     * @param string $secretId The secret UUID
     *
     * @return void
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md#requirement-retention-and-cascades
     */
    public function deleteForSecret(string $secretId): void
    {
        $this->mapper->deleteBySecret(secretId: $secretId);
    }//end deleteForSecret()

    /**
     * Whether the caller owns the given secret.
     *
     * @param string $secretId The secret UUID
     * @param string $userId   The caller
     *
     * @return bool
     */
    private function isOwned(string $secretId, string $userId): bool
    {
        try {
            $secret = $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            return false;
        }

        return $secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId;
    }//end isOwned()

    /**
     * Load a version and assert the caller owns its secret. A version of
     * an inaccessible secret is indistinguishable from a missing one.
     *
     * @param string $versionId The version UUID
     * @param string $userId    The caller
     *
     * @return SecretVersion
     *
     * @throws InvalidArgumentException On not found / not owned
     */
    private function loadOwnedVersion(string $versionId, string $userId): SecretVersion
    {
        try {
            $version = $this->mapper->findById($versionId);
        } catch (DoesNotExistException) {
            throw new InvalidArgumentException('Version not found');
        }

        if ($this->isOwned(secretId: $version->getSecretId(), userId: $userId) === false) {
            throw new InvalidArgumentException('Version not found');
        }

        return $version;
    }//end loadOwnedVersion()

    /**
     * Whether a wrapping suite is revoked/compromised (read gating).
     *
     * @param string|null $suiteId The suite UUID
     *
     * @return bool
     */
    private function isSuiteBlocked(?string $suiteId): bool
    {
        if ($suiteId === null || $suiteId === '') {
            return false;
        }

        try {
            $suite = $this->suiteMapper->findById($suiteId);
        } catch (DoesNotExistException) {
            return false;
        }

        return in_array($suite->getStatus(), ['revoked', 'compromised'], true);
    }//end isSuiteBlocked()
}//end class
