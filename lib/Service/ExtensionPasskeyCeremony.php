<?php

/**
 * Keepiq Extension Passkey Ceremony
 *
 * The server side of browser-extension passkey enrolment and unlock. The
 * relying party is this server's host, never one the client names. Every
 * ceremony needs a request authenticated with an app password; its token id
 * binds the challenges and the credential. Challenges come from the
 * server-side ExtensionChallengeStore, and the assertions are verified with
 * the extension step list, which accepts only trusted extension origins and
 * requires user verification. The WebAuthn user handle of an extension
 * credential is random, derived from its enrolment challenge, so it never
 * replaces the user's other passkeys on the same relying party. A counter
 * that did not increase suspends the credential. Each refusal is logged with
 * the failed check, audited, and surfaces as one generic message.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\PasskeyCredential;
use OCA\Keepiq\Db\PasskeyMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\PasskeyCounterException;
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Issues and verifies browser-extension passkey ceremonies.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The ceremony joins the app password, challenge, origin, verifier and audit services.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-unlock-the-extension-with-the-enrolled-passkey
 */
class ExtensionPasskeyCeremony {
	/**
	 * Constructor for ExtensionPasskeyCeremony.
	 *
	 * @param ExtensionAppPassword $appPassword The request's app password
	 * @param ExtensionChallengeStore $challenges The server-side challenges
	 * @param TrustedExtensionOrigins $origins The trusted extension origins
	 * @param WebAuthnVerifier $verifier The WebAuthn verifier
	 * @param PasskeyEnrolmentCeremony $web The server host relying party and encoding
	 * @param PasskeyMapper $mapper The credentials (suspension)
	 * @param IEventDispatcher $dispatcher The audit dispatcher
	 * @param LoggerInterface $logger The logger for refusals
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One collaborator per Nextcloud service the ceremony builds on.
	 */
	public function __construct(
		private ExtensionAppPassword $appPassword,
		private ExtensionChallengeStore $challenges,
		private TrustedExtensionOrigins $origins,
		private WebAuthnVerifier $verifier,
		private PasskeyEnrolmentCeremony $web,
		private PasskeyMapper $mapper,
		private IEventDispatcher $dispatcher,
		private LoggerInterface $logger,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * The relying party of every extension credential: this server's host.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function rpId(): string {
		return $this->web->webRpId();
	}//end rpId()

	/**
	 * The token id of the request's app password, or null.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function tokenId(string $uid): ?int {
		return $this->appPassword->tokenId(uid: $uid);
	}//end tokenId()

	/**
	 * Issue an enrolment challenge pair for the request's app password.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{challenge: string, assertionChallenge: string, rpId: string, userHandle: string} base64url
	 *
	 * @throws InvalidArgumentException When the request may not enrol an extension passkey
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function issueEnrolment(string $uid): array {
		$tokenId = $this->binding(uid: $uid, action: 'enrol');
		$pair = $this->challenges->issue(uid: $uid, tokenId: $tokenId, purpose: 'enrol');

		return [
			'challenge' => $this->web->b64url(raw: $pair['challenge']),
			'assertionChallenge' => $this->web->b64url(raw: (string)$pair['assertionChallenge']),
			'rpId' => $this->rpId(),
			'userHandle' => self::userHandle(challenge: $pair['challenge']),
		];
	}//end issueEnrolment()

	/**
	 * Take the pending enrolment challenge pair of the request's app
	 * password, spending it. Null when there is none.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{tokenId: int, challenge: string, assertionChallenge: string}|null
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function takeEnrolment(string $uid): ?array {
		$tokenId = $this->tokenId(uid: $uid);
		if ($tokenId === null) {
			return null;
		}

		$pair = $this->challenges->take(uid: $uid, tokenId: $tokenId, purpose: 'enrol');
		if ($pair === null) {
			return null;
		}

		return ['tokenId' => $tokenId, 'challenge' => $pair['challenge'], 'assertionChallenge' => (string)$pair['assertionChallenge']];
	}//end takeEnrolment()

	/**
	 * Verify an extension enrolment against the taken challenge pair.
	 *
	 * @param string $uid The calling owner
	 * @param array{tokenId: int, challenge: string, assertionChallenge: string}|null $pending The taken pair
	 * @param array<string,mixed> $dto The enrolment request
	 *
	 * @return array{verified: VerifiedPasskey, tokenId: int, userHandle: string, rpId: string}
	 *
	 * @throws InvalidArgumentException When the enrolment does not verify
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function verifyEnrolment(string $uid, ?array $pending, array $dto): array {
		if ($pending === null) {
			$this->refuse(uid: $uid, action: 'enrol', reason: 'no app password, or no pending or an expired enrolment challenge');
		}

		$attestation = $dto['attestation'] ?? null;
		$assertion = $dto['assertion'] ?? null;
		if (is_array($attestation) === false || is_array($assertion) === false) {
			$this->refuse(uid: $uid, action: 'enrol', reason: 'attestation or assertion missing');
		}

		$userHandle = self::userHandle(challenge: $pending['challenge']);
		try {
			$verified = $this->verifier->verifyEnrolment(
				uid: $userHandle,
				rpId: $this->rpId(),
				challenge: $pending['challenge'],
				assertionChallenge: $pending['assertionChallenge'],
				attestation: $attestation,
				assertion: $assertion,
				extension: true,
			);
		} catch (PasskeyVerificationException $exception) {
			$this->refuse(uid: $uid, action: 'enrol', reason: $exception->getMessage());
		}

		if (hash_equals($verified->credentialId, (string)($dto['credentialId'] ?? '')) === false) {
			$this->refuse(uid: $uid, action: 'enrol', reason: 'credential id differs from the attested one');
		}

		$this->audit(uid: $uid, eventType: AuditEventTypes::PASSKEY_ENROLLED, metadata: ['clientKind' => 'extension']);

		return ['verified' => $verified, 'tokenId' => $pending['tokenId'], 'userHandle' => $userHandle, 'rpId' => $this->rpId()];
	}//end verifyEnrolment()

	/**
	 * Issue the unlock challenge for the request's app password. Null when
	 * the request has no app password or extension passkeys are closed, so
	 * the extension offers the master password only.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{tokenId: int, challenge: string, rpId: string}|null The challenge as base64url
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-unlock-the-extension-with-the-enrolled-passkey
	 */
	public function issueUnlock(string $uid): ?array {
		$tokenId = $this->tokenId(uid: $uid);
		if ($tokenId === null || $this->isOpen() === false) {
			return null;
		}

		$pair = $this->challenges->issue(uid: $uid, tokenId: $tokenId, purpose: 'unlock');

		return ['tokenId' => $tokenId, 'challenge' => $this->web->b64url(raw: $pair['challenge']), 'rpId' => $this->rpId()];
	}//end issueUnlock()

	/**
	 * Take the pending unlock challenge of the request's app password.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{tokenId: int, challenge: string}|null
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function takeUnlock(string $uid): ?array {
		$tokenId = $this->tokenId(uid: $uid);
		if ($tokenId === null) {
			return null;
		}

		$pair = $this->challenges->take(uid: $uid, tokenId: $tokenId, purpose: 'unlock');
		if ($pair === null) {
			return null;
		}

		return ['tokenId' => $tokenId, 'challenge' => $pair['challenge']];
	}//end takeUnlock()

	/**
	 * Verify an unlock assertion of an extension credential. A counter
	 * that did not increase suspends the credential.
	 *
	 * @param string $uid The calling owner
	 * @param array{tokenId: int, challenge: string}|null $pending The taken challenge
	 * @param PasskeyCredential|null $credential The answering credential, null when none is offered
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return int The new signature counter
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-unlock-the-extension-with-the-enrolled-passkey
	 */
	public function verifyUnlock(string $uid, ?array $pending, ?PasskeyCredential $credential, array $assertion): int {
		if ($pending === null) {
			$this->refuse(uid: $uid, action: 'unlock', reason: 'no app password, or no pending or an expired unlock challenge');
		}

		if ($credential === null || $credential->getTokenId() !== $pending['tokenId']) {
			$this->refuse(uid: $uid, action: 'unlock', reason: 'the assertion names no passkey of this app password');
		}

		try {
			$counter = $this->verifier->verifyUnlock(
				uid: (string)$credential->getUserHandle(),
				rpId: $this->rpId(),
				challenge: $pending['challenge'],
				assertion: $assertion,
				credentialId: $credential->getCredentialId(),
				publicKey: (string)$credential->getPublicKey(),
				counter: $credential->getSignCount(),
				extension: true,
			);
		} catch (PasskeyCounterException $exception) {
			$credential->setStatus('suspended');
			$this->mapper->update($credential);
			$this->audit(uid: $uid, eventType: AuditEventTypes::PASSKEY_SUSPENDED, metadata: ['clientKind' => 'extension']);
			$this->refuse(uid: $uid, action: 'unlock', reason: $exception->getMessage());
		} catch (PasskeyVerificationException $exception) {
			$this->refuse(uid: $uid, action: 'unlock', reason: $exception->getMessage());
		}

		$this->audit(uid: $uid, eventType: AuditEventTypes::PASSKEY_UNLOCKED, metadata: ['clientKind' => 'extension']);

		return $counter;
	}//end verifyUnlock()

	/**
	 * The token id the ceremony binds to, refusing a request without an app
	 * password or while extension passkeys are closed.
	 *
	 * @param string $uid The calling owner
	 * @param string $action enrol or unlock
	 *
	 * @return int
	 *
	 * @throws InvalidArgumentException When refused
	 */
	private function binding(string $uid, string $action): int {
		$tokenId = $this->tokenId(uid: $uid);
		if ($tokenId === null) {
			$this->refuse(uid: $uid, action: $action, reason: 'the request did not authenticate with an app password');
		}

		try {
			$this->origins->trusted();
		} catch (PasskeyVerificationException $exception) {
			$this->refuse(uid: $uid, action: $action, reason: $exception->getMessage());
		}

		return $tokenId;
	}//end binding()

	/**
	 * Whether extension passkeys are switched on with a readable origin list.
	 *
	 * @return bool
	 */
	private function isOpen(): bool {
		try {
			$this->origins->trusted();
		} catch (PasskeyVerificationException) {
			return false;
		}

		return true;
	}//end isOpen()

	/**
	 * The random WebAuthn user handle of an enrolment: derived from its
	 * random challenge, so the server can recompute it without storing it.
	 *
	 * @param string $challenge The raw enrolment challenge
	 *
	 * @return string base64url
	 */
	private static function userHandle(string $challenge): string {
		return rtrim(strtr(base64_encode(hash_hmac('sha256', 'keepiq-extension-user-handle', $challenge, true)), '+/', '-_'), '=');
	}//end userHandle()

	/**
	 * Write an audit event for the owner.
	 *
	 * @param string $uid The owner
	 * @param string $eventType The event type
	 * @param array<string,mixed> $metadata Whitelisted metadata
	 *
	 * @return void
	 */
	private function audit(string $uid, string $eventType, array $metadata): void {
		$this->dispatcher->dispatchTyped(
			$this->auditEvents->forUser(actorId: $uid, eventType: $eventType, objectType: 'passkey', metadata: $metadata)
		);
	}//end audit()

	/**
	 * Log, audit and refuse with a generic message.
	 *
	 * @param string $uid The calling owner
	 * @param string $action enrol or unlock
	 * @param string $reason The failed check, for the log only
	 *
	 * @return never
	 *
	 * @throws InvalidArgumentException Always
	 */
	private function refuse(string $uid, string $action, string $reason): never {
		$this->logger->warning(
			'Extension passkey {action} refused for {uid}: {reason}',
			['app' => 'keepiq', 'uid' => $uid, 'action' => $action, 'reason' => $reason]
		);
		$this->audit(uid: $uid, eventType: AuditEventTypes::PASSKEY_REFUSED, metadata: ['clientKind' => 'extension', 'ceremony' => $action]);

		throw new InvalidArgumentException('The passkey could not be verified');
	}//end refuse()
}//end class
