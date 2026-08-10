<?php

/**
 * Doriath Secret Version Access Guard
 *
 * The read-authorization surface of secret version history
 * (secret-version-history §2/§3). One place answers "may this caller read
 * this version": the owner-only rule, the "a version of an inaccessible
 * secret is indistinguishable from a missing one" no-oracle rule, and the
 * revoked/compromised suite read-gate that mirrors the head-read posture.
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
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretVersion;
use OCA\Doriath\Db\SecretVersionMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Read authorization for secret versions.
 */
class SecretVersionAccessGuard
{
    /**
     * Constructor for SecretVersionAccessGuard.
     *
     * @param SecretVersionMapper   $mapper       The version mapper
     * @param SecretMapper          $secretMapper The secret mapper (ownership)
     * @param EncryptionSuiteMapper $suiteMapper  The suite mapper (read gating)
     *
     * @return void
     *
     * @spec exclude Constructor wiring only — no domain logic.
     */
    public function __construct(
        private SecretVersionMapper $mapper,
        private SecretMapper $secretMapper,
        private EncryptionSuiteMapper $suiteMapper,
    ) {
    }//end __construct()

    /**
     * Whether the caller owns the given secret.
     *
     * @param string $secretId The secret UUID
     * @param string $userId   The caller
     *
     * @return bool
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
     */
    public function isOwned(string $secretId, string $userId): bool
    {
        try {
            $secret = $this->secretMapper->findById($secretId);
        } catch (DoesNotExistException) {
            return false;
        }

        return $secret->getOwnerType() === 'user' && $secret->getOwnerId() === $userId;
    }//end isOwned()

    /**
     * Load a version the caller is allowed to read WITH its ciphertext
     * blobs. Refused when the caller does not own the secret (reported as
     * "not found", no existence oracle) or when the version's wrapping
     * suite is revoked/compromised — matching the head-read posture.
     *
     * @param string $versionId The version UUID
     * @param string $userId    The caller
     *
     * @return SecretVersion
     *
     * @throws InvalidArgumentException On not found / not owned / suite blocked
     *
     * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
     */
    public function requireReadableVersion(string $versionId, string $userId): SecretVersion
    {
        $version = $this->loadOwnedVersion(versionId: $versionId, userId: $userId);

        if ($this->isSuiteBlocked(suiteId: $version->getEncryptionSuiteId()) === true) {
            throw new InvalidArgumentException(
                'This version is locked because its encryption suite was revoked'
            );
        }

        return $version;
    }//end requireReadableVersion()

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
