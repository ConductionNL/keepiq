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
use OCA\Keepiq\Kernel\Contracts\PasskeyServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for passkey vault login.
 */
class PasskeyService implements PasskeyServiceInterface {
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
	 * @param ExtensionPasskeyCeremony $extension The browser-extension ceremonies
	 *
	 * @return void
	 */
	public function __construct(
		private PasskeyMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private PasskeyEnrolmentCeremony $ceremony,
		private PasskeyUnlockCeremony $unlockCeremony,
		private ExtensionPasskeyCeremony $extension,
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
	 * Issue the enrolment challenge pair (base64url) as the only one the next
	 * enrolment may present: in the session for the web app, server-side and
	 * bound to the app password for the extension, which also gets the
	 * relying party and its random user handle.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException When the extension may not enrol
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function enrolmentChallenge(string $uid, string $client='web'): array {
		if ($client === 'extension') {
			return $this->extension->issueEnrolment(uid: $uid);
		}

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
		$clientKind = $this->clientKindOf(dto: $dto);
		if ($clientKind === 'extension') {
			return $this->enrollExtension(uid: $uid, dto: $dto);
		}

		$pending = $this->ceremony->take(uid: $uid);
		$this->assertEnvelope(dto: $dto);
		if (in_array($clientKind, self::CLIENT_KINDS, true) === false) {
			throw new InvalidArgumentException('clientKind must be web or extension');
		}

		$verified = $this->ceremony->verify(uid: $uid, pending: $pending, clientRpId: null, dto: $dto);
		$credential = $this->newCredential(uid: $uid, dto: $dto, verified: $verified);
		$credential->setClientKind('web');
		$credential->setRpId($this->optionalString(value: $dto['rpId'] ?? null));

		return $this->mapper->insert($credential);
	}//end enroll()

	/**
	 * Enroll a browser-extension passkey: the server-side challenge pair of
	 * the request's app password is taken first, the ceremony is verified
	 * with the extension step list against this server's host, and the
	 * credential is bound to that app password. A client rpId is ignored.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $dto The enrolment request
	 *
	 * @return PasskeyCredential
	 *
	 * @throws InvalidArgumentException On a missing envelope, a failed verification or a duplicate credential
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	private function enrollExtension(string $uid, array $dto): PasskeyCredential {
		$pending = $this->extension->takeEnrolment(uid: $uid);
		$this->assertEnvelope(dto: $dto);
		$result = $this->extension->verifyEnrolment(uid: $uid, pending: $pending, dto: $dto);
		$credential = $this->newCredential(uid: $uid, dto: $dto, verified: $result['verified']);
		$credential->setClientKind('extension');
		$credential->setRpId($result['rpId']);
		$credential->setTokenId($result['tokenId']);
		$credential->setUserHandle($result['userHandle']);

		return $this->mapper->insert($credential);
	}//end enrollExtension()

	/**
	 * Refuse an enrolment without its credential id, PRF salt or envelope.
	 *
	 * @param array<string,mixed> $dto The enrolment request
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a field is missing
	 */
	private function assertEnvelope(array $dto): void {
		foreach (['credentialId', 'prfSalt', 'wrappedUnlockKey'] as $field) {
			if ((string)($dto[$field] ?? '') === '') {
				throw new InvalidArgumentException('credentialId, prfSalt and wrappedUnlockKey are required');
			}
		}
	}//end assertEnvelope()

	/**
	 * A new active credential row from a verified enrolment, refusing a
	 * credential id the owner already enrolled.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $dto The enrolment request
	 * @param VerifiedPasskey $verified The verified credential data
	 *
	 * @return PasskeyCredential
	 *
	 * @throws InvalidArgumentException On a duplicate credential
	 */
	private function newCredential(string $uid, array $dto, VerifiedPasskey $verified): PasskeyCredential {
		$credentialId = (string)$dto['credentialId'];
		if ($this->mapper->findByCredentialId($uid, $credentialId) !== null) {
			throw new InvalidArgumentException('This passkey is already enrolled');
		}

		$credential = new PasskeyCredential();
		$credential->setId(Uuid::uuid4()->toString());
		$credential->setOwnerId($uid);
		$credential->setCredentialId($credentialId);
		$credential->setPublicKey($verified->publicKey);
		$credential->setPrfSalt((string)$dto['prfSalt']);
		$credential->setWrappedUnlockKey((string)$dto['wrappedUnlockKey']);
		$credential->setUnlockKeyEpoch($this->currentEpoch(uid: $uid));
		$credential->setLabel($this->optionalString(value: $dto['label'] ?? null));
		$credential->setTransports($this->optionalString(value: $dto['transports'] ?? null));
		$credential->setAaguid($verified->aaguid);
		$credential->setSignCount($verified->counter);
		$credential->setStatus('active');
		$credential->setCreatedAt(new DateTime());

		return $credential;
	}//end newCredential()

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
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-extension-credentials-are-visible-and-revocable-in-the-web-app
	 */
	public function loginOptions(string $uid, string $client='web'): array {
		if ($client === 'extension') {
			return $this->extensionLoginOptions(uid: $uid);
		}

		$credentials = self::unlockEntries(credentials: $this->offeredCredentials(uid: $uid, client: $client));

		return [
			'unlockKeyEpoch' => $this->currentEpoch(uid: $uid),
			'challenge' => $this->unlockCeremony->issue(uid: $uid),
			'credentials' => $credentials,
		];
	}//end loginOptions()

	/**
	 * The extension's unlock options: a server-side challenge bound to the
	 * request's app password, the relying party, and the ids and PRF salts
	 * of the extension credentials bound to that app password. No envelope.
	 * Without an app password, or while extension passkeys are closed, no
	 * challenge and no credential, so the extension offers the master
	 * password only.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-unlock-the-extension-with-the-enrolled-passkey
	 */
	private function extensionLoginOptions(string $uid): array {
		$issued = $this->extension->issueUnlock(uid: $uid);
		$credentials = [];
		if ($issued !== null) {
			$credentials = self::unlockEntries(
				credentials: $this->offeredCredentials(uid: $uid, client: 'extension', tokenId: $issued['tokenId'])
			);
		}

		return [
			'unlockKeyEpoch' => $this->currentEpoch(uid: $uid),
			'challenge' => $issued['challenge'] ?? null,
			'rpId' => $this->extension->rpId(),
			'credentials' => $credentials,
		];
	}//end extensionLoginOptions()

	/**
	 * The unlock-options entry of each credential: ids, PRF salt and epoch,
	 * never the envelope.
	 *
	 * @param PasskeyCredential[] $credentials The offered credentials
	 *
	 * @return list<array{id: string, credentialId: string, prfSalt: string, unlockKeyEpoch: int}>
	 */
	private static function unlockEntries(array $credentials): array {
		return array_values(array_map(
			static fn (PasskeyCredential $credential): array => [
				'id' => $credential->getId(),
				'credentialId' => $credential->getCredentialId(),
				'prfSalt' => $credential->getPrfSalt(),
				'unlockKeyEpoch' => $credential->getUnlockKeyEpoch(),
			],
			$credentials
		));
	}//end unlockEntries()

	/**
	 * Verify an unlock assertion and release the answering credential's
	 * wrapped envelope. The pending unlock challenge is taken first, so any
	 * attempt spends it. The credential must be one loginOptions() offers to
	 * this client; its counter and last-used stamp are updated.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 * @param string $client web or extension
	 *
	 * @return array{id: string, credentialId: string, wrappedUnlockKey: string, unlockKeyEpoch: int}
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function unlock(string $uid, array $assertion, string $client='web'): array {
		[$answering, $counter] = match ($client) {
			'extension' => $this->verifiedExtensionAnswer(uid: $uid, assertion: $assertion),
			default => $this->verifiedWebAnswer(uid: $uid, client: $client, assertion: $assertion),
		};

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
	 * Take the web app's session challenge and verify the assertion of an
	 * offered credential.
	 *
	 * @param string $uid The calling owner
	 * @param string $client The asking client
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return array{0: PasskeyCredential, 1: int} The credential and its new counter
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 */
	private function verifiedWebAnswer(string $uid, string $client, array $assertion): array {
		$challenge = $this->unlockCeremony->take(uid: $uid);
		$answering = $this->answering(credentials: $this->offeredCredentials(uid: $uid, client: $client), assertion: $assertion);
		$counter = $this->unlockCeremony->verify(uid: $uid, challenge: $challenge, credential: $answering, assertion: $assertion);

		return [$answering, $counter];
	}//end verifiedWebAnswer()

	/**
	 * Take the extension's server-side challenge and verify the assertion of
	 * a credential bound to the request's app password.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return array{0: PasskeyCredential, 1: int} The credential and its new counter
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 */
	private function verifiedExtensionAnswer(string $uid, array $assertion): array {
		$pending = $this->extension->takeUnlock(uid: $uid);
		$answering = $this->answering(
			credentials: $this->offeredCredentials(uid: $uid, client: 'extension', tokenId: $pending['tokenId'] ?? null),
			assertion: $assertion,
		);
		$counter = $this->extension->verifyUnlock(uid: $uid, pending: $pending, credential: $answering, assertion: $assertion);

		return [$answering, $counter];
	}//end verifiedExtensionAnswer()

	/**
	 * The offered credential the assertion names, or null.
	 *
	 * @param PasskeyCredential[] $credentials The offered credentials
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return PasskeyCredential|null
	 */
	private function answering(array $credentials, array $assertion): ?PasskeyCredential {
		$credentialId = $this->unlockCeremony->credentialIdOf(assertion: $assertion);
		foreach ($credentials as $credential) {
			if ($credentialId !== '' && hash_equals($credential->getCredentialId(), $credentialId) === true) {
				return $credential;
			}
		}

		return null;
	}//end answering()

	/**
	 * The owner's active, epoch-current, verified credentials of the asking
	 * client. A credential whose epoch trails the suite wraps a dead key, and
	 * one without a verified public key cannot be checked: both are marked
	 * stale so the UI can prompt re-enrollment.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 * @param int|null $tokenId The extension's app password token id (extension only)
	 *
	 * @return PasskeyCredential[]
	 */
	private function offeredCredentials(string $uid, string $client, ?int $tokenId=null): array {
		$epoch = $this->currentEpoch(uid: $uid);
		$offered = [];
		foreach ($this->mapper->findActiveByOwner($uid) as $credential) {
			if ($credential->getUnlockKeyEpoch() !== $epoch || (string)$credential->getPublicKey() === '') {
				$credential->setStatus('stale');
				$this->mapper->update($credential);
				continue;
			}

			if ($this->offeredTo(credential: $credential, client: $client, tokenId: $tokenId) === true) {
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
	 * @param int|null $tokenId The asking extension's app password token id
	 *
	 * @return bool
	 */
	private function offeredTo(PasskeyCredential $credential, string $client, ?int $tokenId): bool {
		if ($client !== 'extension') {
			return $credential->getClientKind() !== 'extension';
		}

		return $credential->getClientKind() === 'extension'
			&& $tokenId !== null
			&& $credential->getTokenId() === $tokenId
			&& $credential->getRpId() === $this->extension->rpId();
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
