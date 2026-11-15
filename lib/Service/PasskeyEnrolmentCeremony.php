<?php

/**
 * Keepiq Passkey Enrolment Ceremony
 *
 * The server side of a passkey enrolment: issues the session-bound challenge
 * pair, takes it back exactly once, and verifies the create() attestation
 * and proof get() assertion against it for the right relying party. Every
 * refusal is logged with the failed check and surfaces to the caller as one
 * generic message.
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
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Issues and verifies passkey enrolment ceremonies.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */
class PasskeyEnrolmentCeremony {
	/**
	 * Constructor for PasskeyEnrolmentCeremony.
	 *
	 * @param PasskeyChallengeStore $challenges The pending enrolment challenges
	 * @param WebAuthnVerifier $verifier The WebAuthn verifier
	 * @param IRequest $request The request (the web relying party id)
	 * @param LoggerInterface $logger The logger for refused enrolments
	 *
	 * @return void
	 */
	public function __construct(
		private PasskeyChallengeStore $challenges,
		private WebAuthnVerifier $verifier,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function issue(string $uid): array {
		$pair = $this->challenges->issue(uid: $uid);

		return [
			'challenge' => $this->b64url(raw: $pair['challenge']),
			'assertionChallenge' => $this->b64url(raw: $pair['assertionChallenge']),
		];
	}//end issue()

	/**
	 * Take the pending challenge pair, spending it.
	 *
	 * @param string $uid The calling owner
	 *
	 * @return array{challenge: string, assertionChallenge: string}|null
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function take(string $uid): ?array {
		return $this->challenges->take(uid: $uid);
	}//end take()

	/**
	 * Verify the enrolment ceremony, refusing and logging on any failure.
	 * A web credential is bound to this server's host, an extension
	 * credential to its own relying party.
	 *
	 * @param string $uid The calling owner
	 * @param array{challenge: string, assertionChallenge: string}|null $pending The taken challenge pair
	 * @param string|null $clientRpId The extension's relying party, null for the web client
	 * @param array<string,mixed> $dto The enrolment request
	 *
	 * @return VerifiedPasskey
	 *
	 * @throws InvalidArgumentException When the ceremony does not verify
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function verify(string $uid, ?array $pending, ?string $clientRpId, array $dto): VerifiedPasskey {
		if ($pending === null) {
			$this->refuse(uid: $uid, reason: 'no pending or an expired enrolment challenge');
		}

		$attestation = $dto['attestation'] ?? null;
		$assertion = $dto['assertion'] ?? null;
		if (is_array($attestation) === false || is_array($assertion) === false) {
			$this->refuse(uid: $uid, reason: 'attestation or assertion missing');
		}

		try {
			$verified = $this->verifier->verifyEnrolment(
				uid: $uid,
				rpId: $clientRpId ?? $this->webRpId(),
				challenge: $pending['challenge'],
				assertionChallenge: $pending['assertionChallenge'],
				attestation: $attestation,
				assertion: $assertion,
			);
		} catch (PasskeyVerificationException $exception) {
			$this->refuse(uid: $uid, reason: $exception->getMessage());
		}

		if (hash_equals($verified->credentialId, (string)($dto['credentialId'] ?? '')) === false) {
			$this->refuse(uid: $uid, reason: 'credential id differs from the attested one');
		}

		return $verified;
	}//end verify()

	/**
	 * Log a refused enrolment and refuse it with a generic message.
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
			'Passkey enrolment refused for {uid}: {reason}',
			['app' => 'keepiq', 'uid' => $uid, 'reason' => $reason]
		);

		throw new InvalidArgumentException('The passkey could not be verified');
	}//end refuse()

	/**
	 * The web client's relying party id: the server host without its port,
	 * as core uses for its own passkeys.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function webRpId(): string {
		return (string)preg_replace('/:\d+$/', '', $this->request->getServerHost());
	}//end webRpId()

	/**
	 * Unpadded base64url.
	 *
	 * @param string $raw Raw bytes
	 *
	 * @return string
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function b64url(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end b64url()
}//end class
