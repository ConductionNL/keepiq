<?php

/**
 * Keepiq Passkey Challenge Store
 *
 * Holds the pending enrolment and unlock challenges in the user's Nextcloud
 * session, as core does for its own WebAuthn ceremonies. A challenge is
 * single-use: taking it removes it, whether or not the ceremony that
 * presents it succeeds, and it expires after a short lifetime.
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

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;

/**
 * Single-use, short-lived enrolment challenges bound to the session user.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */
class PasskeyChallengeStore {
	/**
	 * The session key of the pending enrolment.
	 *
	 * @var string
	 */
	public const SESSION_KEY = 'keepiq_passkey_enrolment';

	/**
	 * The session key of the pending unlock.
	 *
	 * @var string
	 */
	public const UNLOCK_SESSION_KEY = 'keepiq_passkey_unlock';

	/**
	 * Seconds a challenge stays valid.
	 *
	 * @var int
	 */
	public const LIFETIME = 300;

	/**
	 * Constructor for PasskeyChallengeStore.
	 *
	 * @param ISession $session The user's Nextcloud session
	 * @param ITimeFactory $timeFactory The clock
	 *
	 * @return void
	 */
	public function __construct(
		private ISession $session,
		private ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Mint and store a fresh challenge pair, replacing any pending one: one
	 * for the create() ceremony, one for the proof-of-possession get().
	 *
	 * @param string $uid The session user
	 *
	 * @return array{challenge: string, assertionChallenge: string} Raw challenge bytes
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function issue(string $uid): array {
		$pair = [
			'challenge' => random_bytes(32),
			'assertionChallenge' => random_bytes(32),
		];
		$this->session->set(
			self::SESSION_KEY,
			[
				'uid' => $uid,
				'challenge' => base64_encode($pair['challenge']),
				'assertionChallenge' => base64_encode($pair['assertionChallenge']),
				'expiresAt' => $this->timeFactory->getTime() + self::LIFETIME,
			]
		);

		return $pair;
	}//end issue()

	/**
	 * Take the pending challenge pair. It is removed before anything is
	 * checked, so it can never be presented twice. Null when none is
	 * pending, it expired, or it was issued to another user.
	 *
	 * @param string $uid The session user
	 *
	 * @return array{challenge: string, assertionChallenge: string}|null Raw challenge bytes
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function take(string $uid): ?array {
		$pending = $this->takePending(key: self::SESSION_KEY, uid: $uid);
		if ($pending === null) {
			return null;
		}

		$challenge = base64_decode((string)($pending['challenge'] ?? ''), true);
		$assertionChallenge = base64_decode((string)($pending['assertionChallenge'] ?? ''), true);
		if ($challenge === false || $challenge === '' || $assertionChallenge === false || $assertionChallenge === '') {
			return null;
		}

		return ['challenge' => $challenge, 'assertionChallenge' => $assertionChallenge];
	}//end take()

	/**
	 * Mint and store a fresh unlock challenge, replacing any pending one.
	 * It is kept apart from the enrolment pair, so neither replaces the other.
	 *
	 * @param string $uid The session user
	 *
	 * @return string Raw challenge bytes
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function issueUnlock(string $uid): string {
		$challenge = random_bytes(32);
		$this->session->set(
			self::UNLOCK_SESSION_KEY,
			[
				'uid' => $uid,
				'challenge' => base64_encode($challenge),
				'expiresAt' => $this->timeFactory->getTime() + self::LIFETIME,
			]
		);

		return $challenge;
	}//end issueUnlock()

	/**
	 * Take the pending unlock challenge, removing it before anything is
	 * checked. Null when none is pending, it expired, or it was issued to
	 * another user.
	 *
	 * @param string $uid The session user
	 *
	 * @return string|null Raw challenge bytes
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function takeUnlock(string $uid): ?string {
		$pending = $this->takePending(key: self::UNLOCK_SESSION_KEY, uid: $uid);
		if ($pending === null) {
			return null;
		}

		$challenge = base64_decode((string)($pending['challenge'] ?? ''), true);
		if ($challenge === false || $challenge === '') {
			return null;
		}

		return $challenge;
	}//end takeUnlock()

	/**
	 * Remove a pending entry from the session and return it when it belongs
	 * to the user and has not expired.
	 *
	 * @param string $key The session key
	 * @param string $uid The session user
	 *
	 * @return array<string,mixed>|null
	 */
	private function takePending(string $key, string $uid): ?array {
		$pending = $this->session->get($key);
		$this->session->remove($key);
		if (is_array($pending) === false
			|| ($pending['uid'] ?? null) !== $uid
			|| is_int($pending['expiresAt'] ?? null) === false
			|| $pending['expiresAt'] < $this->timeFactory->getTime()
		) {
			return null;
		}

		return $pending;
	}//end takePending()
}//end class
