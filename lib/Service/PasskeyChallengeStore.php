<?php

/**
 * Keepiq Passkey Challenge Store
 *
 * Holds the pending enrolment challenges in the user's Nextcloud session,
 * as core does for its own WebAuthn registration. A challenge pair is
 * single-use: taking it removes it, whether or not the enrolment that
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
		$pending = $this->session->get(self::SESSION_KEY);
		$this->session->remove(self::SESSION_KEY);
		if (is_array($pending) === false
			|| ($pending['uid'] ?? null) !== $uid
			|| is_int($pending['expiresAt'] ?? null) === false
			|| $pending['expiresAt'] < $this->timeFactory->getTime()
		) {
			return null;
		}

		$challenge = base64_decode((string)($pending['challenge'] ?? ''), true);
		$assertionChallenge = base64_decode((string)($pending['assertionChallenge'] ?? ''), true);
		if ($challenge === false || $challenge === '' || $assertionChallenge === false || $assertionChallenge === '') {
			return null;
		}

		return ['challenge' => $challenge, 'assertionChallenge' => $assertionChallenge];
	}//end take()
}//end class
