<?php

/**
 * Keepiq Extension Challenge Store
 *
 * The WebAuthn challenges of browser-extension passkey ceremonies. The
 * extension authenticates with an app password and sends no cookies, so it
 * has no session to hold a challenge; the challenge lives in the database
 * instead, bound to the user, the app password's token id and the purpose
 * (`enrol` or `unlock`). Issuing replaces the pending challenge for that
 * binding. Taking is one delete of the row: only the request whose delete
 * removed it may verify, so a challenge is spent exactly once even under
 * parallel requests. A challenge expires after 300 seconds.
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
use OCA\Keepiq\Db\WebAuthnChallengeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Single-use, short-lived extension challenges bound to an app password.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
 */
class ExtensionChallengeStore {
	/**
	 * Seconds a challenge stays valid.
	 *
	 * @var int
	 */
	public const LIFETIME = 300;

	/**
	 * The purposes a challenge can serve.
	 *
	 * @var string[]
	 */
	public const PURPOSES = ['enrol', 'unlock'];

	/**
	 * Constructor for ExtensionChallengeStore.
	 *
	 * @param WebAuthnChallengeMapper $mapper The challenge rows
	 * @param ISecureRandom $random The random source
	 * @param ITimeFactory $timeFactory The clock
	 *
	 * @return void
	 */
	public function __construct(
		private WebAuthnChallengeMapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $timeFactory,
	) {
	}//end __construct()

	/**
	 * Mint and store a challenge, replacing the pending one for the same
	 * binding. An enrolment also gets the challenge of its proof get().
	 *
	 * @param string $uid The user
	 * @param int $tokenId The app password's token id
	 * @param string $purpose enrol or unlock
	 *
	 * @return array{challenge: string, assertionChallenge: string|null} Raw challenge bytes
	 *
	 * @throws InvalidArgumentException On an unknown purpose
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function issue(string $uid, int $tokenId, string $purpose): array {
		self::assertPurpose(purpose: $purpose);
		$pair = ['challenge' => $this->randomBytes(), 'assertionChallenge' => null];
		if ($purpose === 'enrol') {
			$pair['assertionChallenge'] = $this->randomBytes();
		}

		$assertionChallenge = null;
		if ($pair['assertionChallenge'] !== null) {
			$assertionChallenge = base64_encode($pair['assertionChallenge']);
		}

		$this->mapper->replace([
			'uid' => $uid,
			'token_id' => $tokenId,
			'purpose' => $purpose,
			'challenge' => base64_encode($pair['challenge']),
			'assertion_challenge' => $assertionChallenge,
			'expires_at' => $this->timeFactory->getTime() + self::LIFETIME,
		]);

		return $pair;
	}//end issue()

	/**
	 * Take the pending challenge, spending it. Null when none is pending for
	 * this binding, another request took it first, or it expired.
	 *
	 * @param string $uid The user
	 * @param int $tokenId The app password's token id
	 * @param string $purpose enrol or unlock
	 *
	 * @return array{challenge: string, assertionChallenge: string|null}|null Raw challenge bytes
	 *
	 * @throws InvalidArgumentException On an unknown purpose
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function take(string $uid, int $tokenId, string $purpose): ?array {
		self::assertPurpose(purpose: $purpose);
		$row = $this->mapper->findPending(uid: $uid, tokenId: $tokenId, purpose: $purpose);
		if ($row === null || $this->mapper->deleteById(id: (int)$row['id']) !== 1) {
			return null;
		}

		if ((int)$row['expires_at'] < $this->timeFactory->getTime()) {
			return null;
		}

		$challenge = base64_decode((string)$row['challenge'], true);
		if ($challenge === false || $challenge === '') {
			return null;
		}

		$assertionChallenge = null;
		if ($purpose === 'enrol') {
			$assertionChallenge = base64_decode((string)($row['assertion_challenge'] ?? ''), true);
			if ($assertionChallenge === false || $assertionChallenge === '') {
				return null;
			}
		}

		return ['challenge' => $challenge, 'assertionChallenge' => $assertionChallenge];
	}//end take()

	/**
	 * Delete every pending challenge of an app password.
	 *
	 * @param int $tokenId The token id
	 *
	 * @return int The number of challenges deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function forgetToken(int $tokenId): int {
		return $this->mapper->deleteByToken(tokenId: $tokenId);
	}//end forgetToken()

	/**
	 * Delete every expired challenge.
	 *
	 * @return int The number of challenges deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function purgeExpired(): int {
		return $this->mapper->deleteExpired(now: $this->timeFactory->getTime());
	}//end purgeExpired()

	/**
	 * 32 random bytes from Nextcloud's random source.
	 *
	 * @return string
	 */
	private function randomBytes(): string {
		return $this->random->generate(32, implode('', array_map('chr', range(0, 255))));
	}//end randomBytes()

	/**
	 * Refuse an unknown purpose.
	 *
	 * @param string $purpose The purpose
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When unknown
	 */
	private static function assertPurpose(string $purpose): void {
		if (in_array($purpose, self::PURPOSES, true) === false) {
			throw new InvalidArgumentException('Unknown challenge purpose: ' . $purpose);
		}
	}//end assertPurpose()
}//end class
