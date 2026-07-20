<?php

/**
 * Doriath Passkey Service
 *
 * Passkey (WebAuthn PRF) vault-unlock envelope store
 * (passkey-vault-login §2). Every method is owner-scoped — a credential
 * is always loaded by id AND asserted to belong to the calling user
 * (no-admin-IDOR, ADR-005). The server holds the wrapped unlock-key
 * envelope but never the PRF secret, the master password, or the
 * plaintext unlock key; the unlock's security rests on the client-side
 * unwrap, not a server-side assertion check (§D3).
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
use OCA\Doriath\Db\PasskeyCredential;
use OCA\Doriath\Db\PasskeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ISecureRandom;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for passkey vault login.
 */
class PasskeyService
{
    /**
     * Constructor for PasskeyService.
     *
     * @param PasskeyMapper         $mapper       The passkey mapper
     * @param EncryptionSuiteMapper $suiteMapper  The suite mapper (epoch source)
     * @param ISecureRandom         $secureRandom The challenge source
     *
     * @return void
     */
    public function __construct(
        private PasskeyMapper $mapper,
        private EncryptionSuiteMapper $suiteMapper,
        private ISecureRandom $secureRandom,
    ) {
    }//end __construct()

    /**
     * The owner's enrolled passkeys (management view — never the
     * envelope or PRF salt).
     *
     * @param string $uid The calling user
     *
     * @return PasskeyCredential[]
     *
     * @spec openspec/changes/passkey-vault-login/specs/passkey-vault-login/spec.md#requirement-passkey-management
     */
    public function listForOwner(string $uid): array
    {
        return $this->mapper->findByOwner($uid);
    }//end listForOwner()

    /**
     * A fresh 32-byte base64 WebAuthn challenge.
     *
     * @return string
     */
    public function freshChallenge(): string
    {
        return base64_encode($this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC.'+/='));
    }//end freshChallenge()

    /**
     * Enroll a passkey unlock envelope. The vault MUST be unlocked
     * client-side — the server rejects a request that cannot present a
     * wrapped envelope (§2.5). The envelope is bound to the CURRENT
     * suite's unlock-key epoch so a later password change can stale it.
     *
     * @param string              $uid The calling owner
     * @param array<string,mixed> $dto {credentialId, publicKey, prfSalt, wrappedUnlockKey, label, transports, aaguid}
     *
     * @return PasskeyCredential
     *
     * @throws InvalidArgumentException On a missing envelope / duplicate credential
     */
    public function enroll(string $uid, array $dto): PasskeyCredential
    {
        $credentialId     = (string) ($dto['credentialId'] ?? '');
        $prfSalt          = (string) ($dto['prfSalt'] ?? '');
        $wrappedUnlockKey = (string) ($dto['wrappedUnlockKey'] ?? '');
        if ($credentialId === '' || $prfSalt === '' || $wrappedUnlockKey === '') {
            throw new InvalidArgumentException('credentialId, prfSalt and wrappedUnlockKey are required');
        }

        if ($this->mapper->findByCredentialId($uid, $credentialId) !== null) {
            throw new InvalidArgumentException('This passkey is already enrolled');
        }

        $credential = new PasskeyCredential();
        $credential->setId(Uuid::uuid4()->toString());
        $credential->setOwnerId($uid);
        $credential->setCredentialId($credentialId);
        $credential->setPublicKey($this->optionalString($dto['publicKey'] ?? null));
        $credential->setPrfSalt($prfSalt);
        $credential->setWrappedUnlockKey($wrappedUnlockKey);
        $credential->setUnlockKeyEpoch($this->currentEpoch($uid));
        $credential->setLabel($this->optionalString($dto['label'] ?? null));
        $credential->setTransports($this->optionalString($dto['transports'] ?? null));
        $credential->setAaguid($this->optionalString($dto['aaguid'] ?? null));
        $credential->setStatus('active');
        $credential->setCreatedAt(new DateTime());

        return $this->mapper->insert($credential);
    }//end enroll()

    /**
     * The unlock options for the lock screen (§2.2): the owner's ACTIVE
     * credentials with their PRF salts + wrapped envelopes, the current
     * suite epoch, and a fresh challenge. Stale/revoked envelopes are
     * refused (not returned).
     *
     * @param string $uid The calling owner
     *
     * @return array<string,mixed>
     */
    public function loginOptions(string $uid): array
    {
        $epoch       = $this->currentEpoch($uid);
        $credentials = [];
        foreach ($this->mapper->findActiveByOwner($uid) as $credential) {
            // A credential whose epoch trails the suite wraps a dead key —
            // refuse it and mark it stale so the UI can prompt re-enrollment.
            if ($credential->getUnlockKeyEpoch() !== $epoch) {
                $credential->setStatus('stale');
                $this->mapper->update($credential);
                continue;
            }

            $credentials[] = [
                'id'               => $credential->getId(),
                'credentialId'     => $credential->getCredentialId(),
                'prfSalt'          => $credential->getPrfSalt(),
                'wrappedUnlockKey' => $credential->getWrappedUnlockKey(),
                'unlockKeyEpoch'   => $credential->getUnlockKeyEpoch(),
            ];
        }

        return [
            'unlockKeyEpoch' => $epoch,
            'challenge'      => $this->freshChallenge(),
            'credentials'    => $credentials,
        ];
    }//end loginOptions()

    /**
     * Record a successful unlock (last-used stamp).
     *
     * @param string $uid The calling owner
     * @param string $id  The credential UUID
     *
     * @return void
     */
    public function recordUse(string $uid, string $id): void
    {
        try {
            $credential = $this->ownedCredential($uid, $id);
        } catch (DoesNotExistException | InvalidArgumentException) {
            return;
        }

        $credential->setLastUsedAt(new DateTime());
        $this->mapper->update($credential);
    }//end recordUse()

    /**
     * Revoke (delete) one passkey — removes its unlock option immediately.
     *
     * @param string $uid The calling owner
     * @param string $id  The credential UUID
     *
     * @return void
     *
     * @throws DoesNotExistException    When missing
     * @throws InvalidArgumentException When not owned
     */
    public function revoke(string $uid, string $id): void
    {
        $credential = $this->ownedCredential($uid, $id);
        $this->mapper->delete($credential);
    }//end revoke()

    /**
     * Mark an owner's envelopes stale after a routine master-password
     * change (§D4). Called from the suite controller.
     *
     * @param string $uid The owner
     *
     * @return void
     */
    public function markStaleOnPasswordChange(string $uid): void
    {
        $this->mapper->markOwnerStale($uid);
    }//end markStaleOnPasswordChange()

    /**
     * Delete all of an owner's envelopes after a compromise-recovery
     * suite rotation (§D4).
     *
     * @param string $uid The owner
     *
     * @return void
     */
    public function deleteAllOnRotation(string $uid): void
    {
        $this->mapper->deleteByOwner($uid);
    }//end deleteAllOnRotation()

    /**
     * Load a credential and enforce the owner guard.
     *
     * @param string $uid The calling owner
     * @param string $id  The credential UUID
     *
     * @return PasskeyCredential
     *
     * @throws DoesNotExistException    When missing
     * @throws InvalidArgumentException When not owned
     */
    private function ownedCredential(string $uid, string $id): PasskeyCredential
    {
        $credential = $this->mapper->findById($id);
        if ($credential->getOwnerId() !== $uid) {
            throw new InvalidArgumentException('This passkey belongs to another user');
        }

        return $credential;
    }//end ownedCredential()

    /**
     * The owner's active suite unlock-key epoch (default 1).
     *
     * @param string $uid The owner
     *
     * @return int
     */
    private function currentEpoch(string $uid): int
    {
        try {
            return $this->suiteMapper->findActiveByOwner('user', $uid)->getUnlockKeyEpoch();
        } catch (DoesNotExistException) {
            return 1;
        }
    }//end currentEpoch()

    /**
     * Trimmed string or null.
     *
     * @param mixed $value The submitted value
     *
     * @return string|null
     */
    private function optionalString(mixed $value): ?string
    {
        if (is_string($value) === false || trim($value) === '') {
            return null;
        }

        return trim($value);
    }//end optionalString()
}//end class
