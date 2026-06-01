<?php

/**
 * Doriath Secret Suite Guard
 *
 * Resolves a user's active encryption suite and decides whether a secret's
 * suite is in a blocked (revoked/compromised) state. Keeps the suite-status
 * coupling out of SecretService.
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
use OCA\Doriath\Db\Secret;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Encryption-suite access decisions for secrets.
 */
class SecretSuiteGuard
{
    /**
     * The suite statuses that block decryption.
     *
     * @var string[]
     */
    private const BLOCKED_STATUSES = ['revoked', 'compromised'];

    /**
     * Constructor for SecretSuiteGuard.
     *
     * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
     *
     * @return void
     */
    public function __construct(private EncryptionSuiteMapper $suiteMapper)
    {
    }//end __construct()

    /**
     * Resolve the user's active suite, throwing if none exists.
     *
     * @param string $userId The user UID
     *
     * @return EncryptionSuite
     *
     * @throws RuntimeException When no active suite exists
     */
    public function getActiveSuiteOrFail(string $userId): EncryptionSuite
    {
        try {
            return $this->suiteMapper->findActiveByOwner('user', $userId);
        } catch (DoesNotExistException) {
            throw new RuntimeException('No active encryption suite found for this user');
        }
    }//end getActiveSuiteOrFail()

    /**
     * Whether the given suite status blocks decryption.
     *
     * @param string $status The suite status
     *
     * @return bool
     */
    public function isStatusBlocked(string $status): bool
    {
        return in_array($status, self::BLOCKED_STATUSES, true);
    }//end isStatusBlocked()

    /**
     * Whether the secret's encryption suite is blocked (missing counts as blocked).
     *
     * @param Secret $secret The secret
     *
     * @return bool
     */
    public function isSecretBlocked(Secret $secret): bool
    {
        try {
            $suite = $this->suiteMapper->findById($secret->getEncryptionSuiteId());
        } catch (DoesNotExistException) {
            return true;
        }

        return $this->isStatusBlocked(status: $suite->getStatus());
    }//end isSecretBlocked()
}//end class
