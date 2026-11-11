<?php

/**
 * Keepiq Passkey Service
 *
 * Passkey (WebAuthn PRF) vault-unlock envelope store
 * (passkey-vault-login §2). Every method is owner-scoped — a credential
 * is always loaded by id AND asserted to belong to the calling user
 * (no-admin-IDOR, ADR-005). The server holds the wrapped unlock-key
 * envelope but never the PRF secret, the master password, or the
 * plaintext unlock key. Enrolment is verified on the server: a single-use
 * session challenge pair, the create() attestation and a get() proof
 * assertion of the same credential (WebAuthnVerifier). An unlock releases a
 * credential's wrapped envelope only after the server verified a get()
 * assertion of that credential against a single-use session challenge.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Db\PasskeyMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for passkey vault login.
 */
class PasskeyService {
	/**
	 * The clients that enrol their own credentials.
	 *
	 * @var string[]
	 */
	public const CLIENT_KINDS = ['web', 'extension'];
	/**
	 * Constructor for PasskeyService.
	 *
	 * @param PasskeyMapper $mapper The passkey mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (epoch source)
	 * @param PasskeyEnrolmentCeremony $ceremony The enrolment challenges and their verification
	 * @param PasskeyUnlockCeremony $unlockCeremony The unlock challenge and its verification
	 *
	 * @return void
	 */
	public function __construct(
		private PasskeyMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private PasskeyEnrolmentCeremony $ceremony,
		private PasskeyUnlockCeremony $unlockCeremony,
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
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function listForOwner(string $uid): array {
		return $this->mapper->findByOwner($uid);
	}//end listForOwner()

	/**
	 * Issue the enrolment challenge pair (base64url) and store it in the
	 * session as the only one the next enrolment may present.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{challenge: string, assertionChallenge: string}
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function enrolmentChallenge(string $uid): array {
		return $this->ceremony->issue(uid: $uid);
	}//end enrolmentChallenge()

	/**
	 * Enroll a passkey unlock envelope. The vault MUST be unlocked
	 * client-side — the server rejects a request that cannot present a
	 * wrapped envelope (§2.5). The envelope is bound to the CURRENT
	 * suite's unlock-key epoch so a later password change can stale it.
	 *
	 * The pending challenge pair is taken first, so it is spent by any
	 * attempt. The attestation and proof assertion must verify against it;
	 * the stored public key and authenticator model come from the verified
	 * data, and the claimed credential id must equal the verified one.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $dto {credentialId, prfSalt, wrappedUnlockKey, attestation, assertion, label, transports, clientKind, rpId}
	 *
	 * @return PasskeyCredential
	 *
	 * @throws InvalidArgumentException On a missing envelope, a failed verification or a duplicate credential
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function enroll(string $uid, array $dto): PasskeyCredential {
		$pending = $this->ceremony->take(uid: $uid);
		$credentialId = (string)($dto['credentialId'] ?? '');
		$prfSalt = (string)($dto['prfSalt'] ?? '');
		$wrappedUnlockKey = (string)($dto['wrappedUnlockKey'] ?? '');
		if ($credentialId === '' || $prfSalt === '' || $wrappedUnlockKey === '') {
			throw new InvalidArgumentException('credentialId, prfSalt and wrappedUnlockKey are required');
		}

		$clientKind = $this->clientKindOf(dto: $dto);
		$rpId = $this->optionalString(value: $dto['rpId'] ?? null);
		$this->assertClientBinding(clientKind: $clientKind, rpId: $rpId);
		$extensionRpId = null;
		if ($clientKind === 'extension') {
			$extensionRpId = $rpId;
		}

		$verified = $this->ceremony->verify(uid: $uid, pending: $pending, clientRpId: $extensionRpId, dto: $dto);
		if ($this->mapper->findByCredentialId($uid, $credentialId) !== null) {
			throw new InvalidArgumentException('This passkey is already enrolled');
		}

		$credential = new PasskeyCredential();
		$credential->setId(Uuid::uuid4()->toString());
		$credential->setOwnerId($uid);
		$credential->setCredentialId($credentialId);
		$credential->setPublicKey($verified->publicKey);
		$credential->setPrfSalt($prfSalt);
		$credential->setWrappedUnlockKey($wrappedUnlockKey);
		$credential->setUnlockKeyEpoch($this->currentEpoch(uid: $uid));
		$credential->setLabel($this->optionalString(value: $dto['label'] ?? null));
		$credential->setTransports($this->optionalString(value: $dto['transports'] ?? null));
		$credential->setAaguid($verified->aaguid);
		$credential->setClientKind($clientKind);
		$credential->setRpId($rpId);
		$credential->setSignCount($verified->counter);
		$credential->setStatus('active');
		$credential->setCreatedAt(new DateTime());

		return $this->mapper->insert($credential);
	}//end enroll()

	/**
	 * The client an enrolment is for, `web` when none is named.
	 *
	 * @param array<string,mixed> $dto The enrolment request
	 *
	 * @return string
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	private function clientKindOf(array $dto): string {
		$clientKind = (string)($dto['clientKind'] ?? 'web');
		if ($clientKind === '') {
			return 'web';
		}

		return $clientKind;
	}//end clientKindOf()

	/**
	 * Refuse an unknown client, and an extension credential without its
	 * relying party: an extension credential is bound to the extension's own
	 * relying party, so the extension's login options can be scoped to it.
	 *
	 * @param string      $clientKind The client the credential is for
	 * @param string|null $rpId       The relying party id, if any
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the client or its binding is wrong
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	private function assertClientBinding(string $clientKind, ?string $rpId): void {
		if (in_array($clientKind, self::CLIENT_KINDS, true) === false) {
			throw new InvalidArgumentException('clientKind must be web or extension');
		}

		if ($clientKind === 'extension' && $rpId === null) {
			throw new InvalidArgumentException('An extension passkey needs its relying party id');
		}
	}//end assertClientBinding()

	/**
	 * The unlock options for the lock screen (§2.2): the owner's ACTIVE
	 * credentials with their PRF salts, the current suite epoch, and a fresh
	 * unlock challenge held in the session. No wrapped envelope: unlock()
	 * returns one only after a verified assertion. Stale/revoked credentials
	 * are refused (not returned), and so is a credential enrolled without a
	 * verified public key, which no assertion can be checked against.
	 *
	 * Only the asking client's credentials are offered: the web app never
	 * gets an extension credential, and the extension only gets the
	 * credentials bound to its own relying party.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 * @param string $rpId The extension's relying party id (extension only)
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-extension-credentials-are-visible-and-revocable-in-the-web-app
	 */
	public function loginOptions(string $uid, string $client='web', string $rpId=''): array {
		$credentials = [];
		foreach ($this->offeredCredentials(uid: $uid, client: $client, rpId: $rpId) as $credential) {
			$credentials[] = [
				'id' => $credential->getId(),
				'credentialId' => $credential->getCredentialId(),
				'prfSalt' => $credential->getPrfSalt(),
				'unlockKeyEpoch' => $credential->getUnlockKeyEpoch(),
			];
		}

		return [
			'unlockKeyEpoch' => $this->currentEpoch(uid: $uid),
			'challenge' => $this->unlockCeremony->issue(uid: $uid),
			'credentials' => $credentials,
		];
	}//end loginOptions()

	/**
	 * Verify an unlock assertion and release the answering credential's
	 * wrapped envelope. The pending unlock challenge is taken first, so any
	 * attempt spends it. The credential must be one loginOptions() offers to
	 * this client; its counter and last-used stamp are updated.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 * @param string $client web or extension
	 * @param string $rpId The extension's relying party id (extension only)
	 *
	 * @return array{id: string, credentialId: string, wrappedUnlockKey: string, unlockKeyEpoch: int}
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function unlock(string $uid, array $assertion, string $client='web', string $rpId=''): array {
		$challenge = $this->unlockCeremony->take(uid: $uid);
		$credentialId = $this->unlockCeremony->credentialIdOf(assertion: $assertion);
		$answering = null;
		foreach ($this->offeredCredentials(uid: $uid, client: $client, rpId: $rpId) as $credential) {
			if ($credentialId !== '' && hash_equals($credential->getCredentialId(), $credentialId) === true) {
				$answering = $credential;
			}
		}

		$counter = $this->unlockCeremony->verify(uid: $uid, challenge: $challenge, credential: $answering, assertion: $assertion);

		$answering->setSignCount($counter);
		$answering->setLastUsedAt(new DateTime());
		$this->mapper->update($answering);

		return [
			'id' => $answering->getId(),
			'credentialId' => $answering->getCredentialId(),
			'wrappedUnlockKey' => $answering->getWrappedUnlockKey(),
			'unlockKeyEpoch' => $answering->getUnlockKeyEpoch(),
		];
	}//end unlock()

	/**
	 * The owner's active, epoch-current, verified credentials of the asking
	 * client. A credential whose epoch trails the suite wraps a dead key, and
	 * one without a verified public key cannot be checked: both are marked
	 * stale so the UI can prompt re-enrollment.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 * @param string $rpId The extension's relying party id (extension only)
	 *
	 * @return PasskeyCredential[]
	 */
	private function offeredCredentials(string $uid, string $client, string $rpId): array {
		$epoch = $this->currentEpoch(uid: $uid);
		$offered = [];
		foreach ($this->mapper->findActiveByOwner($uid) as $credential) {
			if ($credential->getUnlockKeyEpoch() !== $epoch || (string)$credential->getPublicKey() === '') {
				$credential->setStatus('stale');
				$this->mapper->update($credential);
				continue;
			}

			if ($this->offeredTo(credential: $credential, client: $client, rpId: $rpId) === true) {
				$offered[] = $credential;
			}
		}

		return $offered;
	}//end offeredCredentials()

	/**
	 * Record a successful unlock (last-used stamp).
	 *
	 * @param string $uid The calling owner
	 * @param string $id The credential UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function recordUse(string $uid, string $id): void {
		try {
			$credential = $this->ownedCredential(uid: $uid, id: $id);
		} catch (DoesNotExistException|InvalidArgumentException) {
			return;
		}

		$credential->setLastUsedAt(new DateTime());
		$this->mapper->update($credential);
	}//end recordUse()

	/**
	 * Revoke (delete) one passkey — removes its unlock option immediately.
	 *
	 * @param string $uid The calling owner
	 * @param string $id The credential UUID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When missing
	 * @throws InvalidArgumentException When not owned
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function revoke(string $uid, string $id): void {
		$credential = $this->ownedCredential(uid: $uid, id: $id);
		$this->mapper->delete($credential);
	}//end revoke()

	/**
	 * Mark an owner's envelopes stale after a routine master-password
	 * change (§D4). Called from the suite controller.
	 *
	 * @param string $uid The owner
	 *
	 * @return void
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-envelopes-are-invalidated-when-the-unlock-key-changes
	 */
	public function markStaleOnPasswordChange(string $uid): void {
		$this->mapper->markOwnerStale($uid);
	}//end markStaleOnPasswordChange()

	/**
	 * Delete all of an owner's envelopes after a compromise-recovery
	 * suite rotation (§D4).
	 *
	 * @param string $uid The owner
	 *
	 * @return void
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#scenario-compromise-recovery-deletes-all-passkey-envelopes
	 */
	public function deleteAllOnRotation(string $uid): void {
		$this->mapper->deleteByOwner($uid);
	}//end deleteAllOnRotation()

	/**
	 * Whether a credential belongs to the asking client.
	 *
	 * @param PasskeyCredential $credential The credential
	 * @param string $client web or extension
	 * @param string $rpId The asking extension's relying party id
	 *
	 * @return bool
	 */
	private function offeredTo(PasskeyCredential $credential, string $client, string $rpId): bool {
		if ($client !== 'extension') {
			return $credential->getClientKind() !== 'extension';
		}

		return $credential->getClientKind() === 'extension'
			&& $rpId !== ''
			&& $credential->getRpId() === $rpId;
	}//end offeredTo()

	/**
	 * Load a credential and enforce the owner guard.
	 *
	 * @param string $uid The calling owner
	 * @param string $id The credential UUID
	 *
	 * @return PasskeyCredential
	 *
	 * @throws DoesNotExistException When missing
	 * @throws InvalidArgumentException When not owned
	 */
	private function ownedCredential(string $uid, string $id): PasskeyCredential {
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
	private function currentEpoch(string $uid): int {
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
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end optionalString()
}//end class
