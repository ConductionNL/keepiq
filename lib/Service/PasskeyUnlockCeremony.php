<?php

/**
 * Keepiq Passkey Unlock Ceremony
 *
 * The server side of a passkey unlock: issues the session-bound unlock
 * challenge, takes it back exactly once, and verifies the get() assertion
 * of the answering credential against it, its stored public key and its
 * counter. Only a verified assertion releases that credential's wrapped
 * unlock key. Every refusal is logged with the failed check and surfaces
 * to the caller as one generic message.
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
use OCA\Keepiq\Exception\PasskeyVerificationException;
use Psr\Log\LoggerInterface;

/**
 * Issues and verifies passkey unlock ceremonies.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
 */
class PasskeyUnlockCeremony {
	/**
	 * Constructor for PasskeyUnlockCeremony.
	 *
	 * @param PasskeyChallengeStore $challenges The pending challenges
	 * @param WebAuthnVerifier $verifier The WebAuthn verifier
	 * @param PasskeyEnrolmentCeremony $enrolment The web relying party id and encoding
	 * @param LoggerInterface $logger The logger for refused unlocks
	 *
	 * @return void
	 */
	public function __construct(
		private PasskeyChallengeStore $challenges,
		private WebAuthnVerifier $verifier,
		private PasskeyEnrolmentCeremony $enrolment,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Issue the unlock challenge (base64url) and store it in the session as
	 * the only one the next unlock may present.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return string
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function issue(string $uid): string {
		return $this->enrolment->b64url(raw: $this->challenges->issueUnlock(uid: $uid));
	}//end issue()

	/**
	 * Take the pending unlock challenge, spending it.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function take(string $uid): ?string {
		return $this->challenges->takeUnlock(uid: $uid);
	}//end take()

	/**
	 * The base64url credential id the assertion names.
	 *
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return string
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function credentialIdOf(array $assertion): string {
		$rawId = $assertion['rawId'] ?? null;
		if (is_string($rawId) === false || $rawId === '') {
			return '';
		}

		return $rawId;
	}//end credentialIdOf()

	/**
	 * Verify an unlock assertion of the given credential, refusing and
	 * logging on any failure. A web credential is bound to this server's
	 * host, an extension credential to its own relying party.
	 *
	 * @param string $uid The calling owner
	 * @param string|null $challenge The taken unlock challenge
	 * @param PasskeyCredential|null $credential The answering credential, null when none is offered
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 *
	 * @return int The new signature counter
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function verify(string $uid, ?string $challenge, ?PasskeyCredential $credential, array $assertion): int {
		if ($challenge === null) {
			$this->refuse(uid: $uid, reason: 'no pending or an expired unlock challenge');
		}

		if ($credential === null) {
			$this->refuse(uid: $uid, reason: 'the assertion names no offered passkey');
		}

		$rpId = $this->enrolment->webRpId();
		if ($credential->getClientKind() === 'extension') {
			$rpId = (string)$credential->getRpId();
		}

		try {
			return $this->verifier->verifyUnlock(
				uid: $uid,
				rpId: $rpId,
				challenge: $challenge,
				assertion: $assertion,
				credentialId: $credential->getCredentialId(),
				publicKey: (string)$credential->getPublicKey(),
				counter: $credential->getSignCount(),
			);
		} catch (PasskeyVerificationException $exception) {
			$this->refuse(uid: $uid, reason: $exception->getMessage());
		}
	}//end verify()

	/**
	 * Log a refused unlock and refuse it with a generic message.
	 *
	 * @param string $uid The calling owner
	 * @param string $reason The failed check, for the log only
	 *
	 * @return never
	 *
	 * @throws InvalidArgumentException Always
	 */
	private function refuse(string $uid, string $reason): never {
		$this->logger->warning(
			'Passkey unlock refused for {uid}: {reason}',
			['app' => 'keepiq', 'uid' => $uid, 'reason' => $reason]
		);

		throw new InvalidArgumentException('The passkey could not be verified');
	}//end refuse()
}//end class
