<?php

/**
 * Keepiq Credential Fields
 *
 * The rules for the three credentials a secret can hold, `key`, `totp` and `passkeys` (keepiq#1325): when they last
 * changed, when a write answers the possibly-compromised warning, and whether a copy mirrors its source's `totp` and
 * `passkeys`.
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

use DateTime;
use OCA\Keepiq\Db\Secret;

/**
 * Change times, the compromise rule and copy mirroring for a secret's credentials.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
 */
class CredentialFields {
	/**
	 * The rotation-flag reasons a TOTP or passkeys change answers: the values were exposed with the password.
	 * Every other reason (`user_flagged`, `policy_expiry`, `lease_*`) asks for a new password.
	 *
	 * @var string[]
	 */
	public const COMPROMISE_REASONS = ['suite_compromise'];

	/**
	 * The three credentials a secret can hold.
	 *
	 * @var string[]
	 */
	public const CREDENTIALS = ['key', 'totp', 'passkeys'];

	/**
	 * The change time a rotation flag with this reason is snapshotted at: the latest credential change for a
	 * compromise, `keyUpdatedAt` alone otherwise.
	 *
	 * @param Secret $secret The row
	 * @param string $reason The flag's reason
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md
	 */
	public function rotationProofAt(Secret $secret, string $reason): ?DateTime {
		if (in_array($reason, self::COMPROMISE_REASONS, true) === true) {
			return $this->lastChangedAt(secret: $secret);
		}

		return $secret->getKeyUpdatedAt();
	}//end rotationProofAt()

	/**
	 * Whether the secret now proves the rotation a flag with this reason asks for. `keyUpdatedAt` must have advanced
	 * past `$frozen` for every reason but a compromise; a compromise exposed all three credentials, so each one the
	 * secret still holds must have changed after `$frozen`, as the possibly-compromised warning requires.
	 *
	 * @param Secret $secret The row
	 * @param string $reason The flag's reason
	 * @param DateTime|null $frozen The snapshot taken when the flag was raised
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md
	 */
	public function provesRotation(Secret $secret, string $reason, ?DateTime $frozen): bool {
		if (in_array($reason, self::COMPROMISE_REASONS, true) === false) {
			$keyAt = $secret->getKeyUpdatedAt();

			return $keyAt !== null && ($frozen === null || $keyAt > $frozen);
		}

		$credentials = [
			[$secret->getKey(), $secret->getKeyUpdatedAt()],
			[$secret->getTotp(), $secret->getTotpUpdatedAt()],
			[$secret->getPasskeys(), $secret->getPasskeysUpdatedAt()],
		];
		foreach ($credentials as [$value, $changedAt]) {
			if ((string)$value !== '' && ($changedAt === null || ($frozen !== null && $changedAt <= $frozen))) {
				return false;
			}
		}

		return true;
	}//end provesRotation()

	/**
	 * When a credential of the row last changed: the latest of `keyUpdatedAt`, `totpUpdatedAt` and `passkeysUpdatedAt`.
	 *
	 * @param Secret $secret The row
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function lastChangedAt(Secret $secret): ?DateTime {
		$latest = null;
		foreach ([$secret->getKeyUpdatedAt(), $secret->getTotpUpdatedAt(), $secret->getPasskeysUpdatedAt()] as $changedAt) {
			if ($changedAt !== null && ($latest === null || $changedAt > $latest)) {
				$latest = $changedAt;
			}
		}

		return $latest;
	}//end lastChangedAt()

	/**
	 * Stamp the change times of a write: those of a restore when it writes back `$restored` credentials, else those of
	 * a change.
	 *
	 * @param Secret $after The row as it will be written
	 * @param Secret $before The row as loaded
	 * @param DateTime $now The time of the write
	 * @param string[] $restored The credentials written back from a version, none for an ordinary write
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function stamp(Secret $after, Secret $before, DateTime $now, array $restored = []): void {
		if ($restored !== []) {
			$this->stampRestore(after: $after, before: $before);
			return;
		}

		$this->stampChanges(after: $after, before: $before, now: $now);
	}//end stamp()

	/**
	 * Stamp `totpUpdatedAt` or `passkeysUpdatedAt` for each of the two fields whose ciphertext differs from `$before`.
	 *
	 * @param Secret $after The row as it will be written
	 * @param Secret $before The row as loaded
	 * @param DateTime $now The time of the write
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function stampChanges(Secret $after, Secret $before, DateTime $now): void {
		$changed = array_diff(['totp', 'passkeys'], $this->unchanged(after: $after, before: $before));
		if (in_array('totp', $changed, true) === true) {
			$after->setTotpUpdatedAt(clone $now);
		}

		if (in_array('passkeys', $changed, true) === true) {
			$after->setPasskeysUpdatedAt(clone $now);
		}
	}//end stampChanges()

	/**
	 * Clear the change time of each credential a restore changes. The version's ciphertext is old, and the row
	 * cannot tell whether it predates a compromise, so a restored value has no change time and reads as exposed until
	 * it is replaced; neither the head's time nor the time of the restore is true of it.
	 *
	 * @param Secret $after The row as it will be written
	 * @param Secret $before The row as loaded
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function stampRestore(Secret $after, Secret $before): void {
		if ((string)$after->getKey() !== (string)$before->getKey()) {
			$after->setKeyUpdatedAt(null);
		}

		$changed = array_diff(['totp', 'passkeys'], $this->unchanged(after: $after, before: $before));
		if (in_array('totp', $changed, true) === true) {
			$after->setTotpUpdatedAt(null);
		}

		if (in_array('passkeys', $changed, true) === true) {
			$after->setPasskeysUpdatedAt(null);
		}
	}//end stampRestore()

	/**
	 * Which of `totp` and `passkeys` a write leaves as they were.
	 *
	 * @param Secret $after The row as it will be written
	 * @param Secret $before The row as loaded
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/secret-audit-trail/spec.md#requirement-no-secret-material-in-audit-entries
	 */
	public function unchanged(Secret $after, Secret $before): array {
		$same = [];
		$pairs = [
			'totp' => [$after->getTotp(), $before->getTotp()],
			'passkeys' => [$after->getPasskeys(), $before->getPasskeys()],
		];
		foreach ($pairs as $field => [$now, $was]) {
			if ((string)$now === (string)$was) {
				$same[] = $field;
			}
		}

		return $same;
	}//end unchanged()

	/**
	 * Whether a write from `$before` to `$after` answers the possibly-compromised warning `$before` carries. It must
	 * change a credential, and leave none of the three exposed: each is empty, changed in this write, or changed
	 * after the warning was raised.
	 *
	 * @param Secret $after The row as it will be written
	 * @param Secret $before The row as loaded
	 * @param string[] $restored Fields written back from a version: old values: a change of one replaces nothing and leaves it exposed
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function answersCompromiseWarning(Secret $after, Secret $before, array $restored = []): bool {
		$flaggedAt = $before->getPossiblyCompromisedAt();
		if ($flaggedAt === null) {
			return false;
		}

		$credentials = [
			'key' => [$after->getKey(), $before->getKey(), $after->getKeyUpdatedAt()],
			'totp' => [$after->getTotp(), $before->getTotp(), $after->getTotpUpdatedAt()],
			'passkeys' => [$after->getPasskeys(), $before->getPasskeys(), $after->getPasskeysUpdatedAt()],
		];
		$replaced = false;
		foreach ($credentials as $field => [$value, $previous, $changedAt]) {
			if ((string)$value !== (string)$previous && in_array($field, $restored, true) === false) {
				$replaced = true;
				continue;
			}

			if ((string)$value !== '' && ($changedAt === null || $changedAt <= $flaggedAt)) {
				return false;
			}
		}

		return $replaced;
	}//end answersCompromiseWarning()

	/**
	 * The `totp` and `passkeys` a copy does not mirror: one the source holds that the copy lacks, or one the copy
	 * carries that the source does not hold.
	 *
	 * @param Secret $source The source
	 * @param array<string,mixed> $copy The copy's ciphertext by API name; an absent field is none
	 *
	 * @return string[] The API names that differ
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function notMirrored(Secret $source, array $copy): array {
		$blobs = new FederatedValueFields();
		$differ = [];
		foreach (['totp' => $source->getTotp(), 'passkeys' => $source->getPasskeys()] as $field => $held) {
			if (($blobs->blob(value: $held) === null) !== ($blobs->blob(value: $copy[$field] ?? null) === null)) {
				$differ[] = $field;
			}
		}

		return $differ;
	}//end notMirrored()
}//end class
