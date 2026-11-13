<?php

/**
 * Keepiq Resealed Fields
 *
 * The ciphertext the browser re-encrypted for one secret head or version
 * under the migration's new suite: the key, login and additional fields, and
 * the totp and passkeys when the record holds them. The server never
 * decrypts (ADR-003); it only checks the set is complete and stores it.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Exception\FieldsNotReencryptedException;

/**
 * The re-encrypted value fields of one record.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class ResealedFields {

	/**
	 * Capture the re-encrypted fields.
	 *
	 * @param string      $key              The re-encrypted key
	 * @param string|null $login            The re-encrypted login
	 * @param string|null $additionalFields The re-encrypted additional fields
	 * @param string|null $totp             The re-encrypted totp; null or '' when not sent
	 * @param string|null $passkeys         The re-encrypted passkeys; null or '' when not sent
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $key,
		public readonly ?string $login,
		public readonly ?string $additionalFields,
		public readonly ?string $totp,
		public readonly ?string $passkeys,
	) {
	}//end __construct()

	/**
	 * Refuse a commit that leaves a stored optional value field behind, or that sends one the record does not hold.
	 *
	 * The record is re-pointed to the new suite as a whole, so a field the client did not send would stay readable only under the revoked key.
	 * A not-sent field is fine when the record holds no value for it, which is what keeps clients that predate these fields working.
	 * Re-encrypting an empty field never yields a value, so a sent value for one would be a write past the version history and the audit.
	 * An empty string counts as not sent, and as no value stored.
	 *
	 * @param Secret|SecretVersion $record The record about to be committed
	 *
	 * @return void
	 *
	 * @throws FieldsNotReencryptedException When a stored field has no re-encrypted counterpart
	 * @throws InvalidArgumentException When a field the record holds no value for is sent one
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function assertCovers(Secret|SecretVersion $record): void {
		[$missing, $unheld] = $this->gaps(record: $record);
		if ($unheld !== []) {
			throw new InvalidArgumentException(
				'The record holds no value to re-encrypt for: ' . implode(', ', $unheld)
			);
		}

		if ($missing !== []) {
			throw (new FieldsNotReencryptedException(
				message: 'Stored value fields were not re-encrypted: ' . implode(', ', $missing)
			))->withFields($missing);
		}
	}//end assertCovers()

	/**
	 * The optional value fields the record holds but the commit left out
	 * (missing), and those the commit sent but the record holds none for
	 * (unheld). An empty string counts as not sent, and as no value stored.
	 *
	 * @param Secret|SecretVersion $record The record about to be committed
	 *
	 * @return array{0:list<string>,1:list<string>} The missing and the unheld fields
	 */
	private function gaps(Secret|SecretVersion $record): array {
		$checks = [
			'totp' => [$record->getTotp(), $this->totp],
			'passkeys' => [$record->getPasskeys(), $this->passkeys],
		];

		$missing = [];
		$unheld = [];
		foreach ($checks as $field => [$stored, $sent]) {
			$holds = (string)$stored !== '';
			if ($holds === ((string)$sent !== '')) {
				continue;
			}

			if ($holds === true) {
				$missing[] = $field;
				continue;
			}

			$unheld[] = $field;
		}

		return [$missing, $unheld];
	}//end gaps()

	/**
	 * Write the re-encrypted fields onto the record: the key, login and
	 * additional fields always, the optional value fields only when sent;
	 * leave the others as stored. An empty string counts as not sent.
	 *
	 * @param Secret|SecretVersion $record The record being committed
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function applyTo(Secret|SecretVersion $record): void {
		$record->setKey($this->key);
		$record->setLogin($this->login);
		$record->setAdditionalFields($this->additionalFields);
		if ($this->totp !== null && $this->totp !== '') {
			$record->setTotp($this->totp);
		}

		if ($this->passkeys !== null && $this->passkeys !== '') {
			$record->setPasskeys($this->passkeys);
		}
	}//end applyTo()
}//end class
