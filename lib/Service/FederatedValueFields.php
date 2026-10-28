<?php

/**
 * Keepiq Federated Value Fields
 *
 * The `totp` and `passkeys` value fields over federation (keepiq#1325). An older Keepiq ignores keys it does not know,
 * so it would build a copy without them; instead each side advertises the fields it stores as `valueFields`,
 * and these rules decide what may be written for a partner and served to a pull.
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

use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;

/**
 * Which value fields a partner can hold, and what a share stores and serves.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedValueFields {
	/**
	 * The value fields beyond `key`, `login` and `additionalFields` that this instance stores and advertises.
	 *
	 * @var string[]
	 */
	public const VALUE_FIELDS = ['totp', 'passkeys'];

	/**
	 * The known value fields in an advertised `valueFields` list, or null when nothing was advertised.
	 *
	 * @param mixed $advertised The `valueFields` of a lookup answer or a pull request
	 *
	 * @return list<string>|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function advertised(mixed $advertised): ?array {
		if (is_array($advertised) === false || array_is_list($advertised) === false) {
			return null;
		}

		return array_values(array_intersect(self::VALUE_FIELDS, array_filter($advertised, 'is_string')));
	}//end advertised()

	/**
	 * What to record on a partner row for an advertisement: a JSON list, or null when it advertised nothing.
	 *
	 * @param mixed $advertised The `valueFields` of its lookup answer
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function toRecord(mixed $advertised): ?string {
		$fields = $this->advertised(advertised: $advertised);
		if ($fields === null) {
			return null;
		}

		return (string)json_encode($fields);
	}//end toRecord()

	/**
	 * Whether a ciphertext carries a `totp` or `passkeys` value.
	 *
	 * @param array<string,mixed> $ciphertext The ciphertext
	 *
	 * @return bool
	 *
	 */
	private function carriesAny(array $ciphertext): bool {
		foreach (self::VALUE_FIELDS as $field) {
			if ($this->blob(value: $ciphertext[$field] ?? null) !== null) {
				return true;
			}
		}

		return false;
	}//end carriesAny()

	/**
	 * Refuse a `totp` or `passkeys` value for a partner that has not advertised it can store that field.
	 *
	 * @param FederationPartner|null $partner The recipient's partner, null when it is gone
	 * @param array<string,mixed> $ciphertext The ciphertext
	 *
	 * @return void
	 *
	 * @throws PartnerUpgradeRequiredException
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function assertPartnerStores(?FederationPartner $partner, array $ciphertext): void {
		$stores = $this->stores(partner: $partner);
		foreach (self::VALUE_FIELDS as $field) {
			if ($this->blob(value: $ciphertext[$field] ?? null) !== null && in_array($field, $stores, true) === false) {
				throw new PartnerUpgradeRequiredException();
			}
		}
	}//end assertPartnerStores()

	/**
	 * Refuse a write to an existing share that carries a value field its partner has not advertised and,
	 * on a share-save, one that does not mirror the patch's `totp` and `passkeys` for a partner that stores them.
	 * The partner is read only when the write or the patch carries one of those fields.
	 *
	 * @param FederatedShare $row The share
	 * @param array<string,mixed> $ciphertext The ciphertext
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param array<string,mixed>|null $patch The share-save's patch of the owner's row, null outside a share-save
	 *
	 * @return void
	 *
	 * @throws PartnerUpgradeRequiredException
	 * @throws NotFoundException When the share's partner is gone
	 * @throws ShareSaveRefusal `copy_fields_mismatch`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function assertShareStores(
		FederatedShare $row,
		array $ciphertext,
		FederationPartnerService $partners,
		?array $patch = null,
	): void {
		$mirrors = array_intersect(self::VALUE_FIELDS, array_keys($patch ?? [])) !== [];
		if ($this->carriesAny(ciphertext: $ciphertext) === false && $mirrors === false) {
			return;
		}

		$partner = $partners->find(id: (string)$row->getPartnerId());
		if ($partner === null) {
			throw new NotFoundException(message: 'Partner not found');
		}

		$this->assertPartnerStores(partner: $partner, ciphertext: $ciphertext);
		if ($mirrors === true) {
			$this->assertMirrorsPatch(partner: $partner, shareId: (string)$row->getId(), ciphertext: $ciphertext, patch: (array)$patch);
		}
	}//end assertShareStores()

	/**
	 * Refuse a share-save entry for a partner that stores `totp` or `passkeys` when it does not carry the
	 * patch's field with the same null-ness: left out it would keep the old value, null it would drop the new one.
	 * A partner that cannot store a field may get it null or not at all.
	 *
	 * @param FederationPartner $partner The share's partner
	 * @param string $shareId The share
	 * @param array<string,mixed> $ciphertext The entry's ciphertext
	 * @param array<string,mixed> $patch The patch of the owner's row
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `copy_fields_mismatch`
	 */
	private function assertMirrorsPatch(FederationPartner $partner, string $shareId, array $ciphertext, array $patch): void {
		$stores = $this->stores(partner: $partner);
		foreach (self::VALUE_FIELDS as $field) {
			if (array_key_exists($field, $patch) === false || in_array($field, $stores, true) === false) {
				continue;
			}

			if (array_key_exists($field, $ciphertext) === false
				|| ($this->blob(value: $ciphertext[$field]) === null) !== ($this->blob(value: $patch[$field]) === null)
			) {
				throw new ShareSaveRefusal(
					error: 'copy_fields_mismatch',
					message: 'Partner share '.$shareId.' does not carry the fields the patch carries'
				);
			}
		}
	}//end assertMirrorsPatch()

	/**
	 * The value fields a partner advertised it stores; none when it advertised nothing or is gone.
	 *
	 * @param FederationPartner|null $partner The partner
	 *
	 * @return list<string>
	 */
	private function stores(?FederationPartner $partner): array {
		if ($partner === null || $partner->getValueFields() === null) {
			return [];
		}

		return ($this->advertised(advertised: json_decode($partner->getValueFields(), true)) ?? []);
	}//end stores()

	/**
	 * The value a share's `totp` or `passkeys` gets on a write: the one sent, else the stored one while the
	 * certificate is unchanged, so nothing stays encrypted for a key the recipient no longer holds.
	 *
	 * @param array<string,mixed> $ciphertext The ciphertext
	 * @param string $field `totp` or `passkeys`
	 * @param string|null $stored The value stored now
	 * @param bool $sameCertificate Whether the browser encrypted for the certificate the share already had
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
	 */
	public function next(array $ciphertext, string $field, ?string $stored, bool $sameCertificate): ?string {
		if (array_key_exists($field, $ciphertext) === true) {
			return $this->blob(value: $ciphertext[$field]);
		}

		if ($sameCertificate === true) {
			return $stored;
		}

		return null;
	}//end next()

	/**
	 * Whether a pull declared it can store every value field the share holds.
	 * An older Keepiq declares none and would build a copy without them.
	 *
	 * @param FederatedShare $row The share
	 * @param array<array-key,mixed> $payload The pull request, `{valueFields?}`
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function servable(FederatedShare $row, array $payload): bool {
		$accepts = ($this->advertised(advertised: $payload['valueFields'] ?? null) ?? []);
		$held = ['totp' => $row->getTotp(), 'passkeys' => $row->getPasskeys()];
		foreach ($held as $field => $value) {
			if ($value !== null && in_array($field, $accepts, true) === false) {
				return false;
			}
		}

		return true;
	}//end servable()

	/**
	 * A ciphertext value, or null for anything that is not a non-empty string.
	 *
	 * @param mixed $value The value as sent
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function blob(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end blob()
}//end class
