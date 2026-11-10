<?php

/**
 * Keepiq Fan Out Share Row
 *
 * One row a team-folder owner's browser submits for a fan-out: the source
 * secret, the recipient, and the ciphertext encrypted for them (key, login,
 * additional fields, totp, passkeys; empty optional blobs count as none).
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

/**
 * One submitted fan-out row.
 *
 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
 */
final class FanOutShareRow {

	/**
	 * The source secret ID, '' when the row names none.
	 *
	 * @var string
	 */
	public readonly string $sourceSecretId;

	/**
	 * The recipient user ID, '' when the row names none.
	 *
	 * @var string
	 */
	public readonly string $targetUserId;

	/**
	 * The recipient-encrypted key blob, '' when the row carries none.
	 *
	 * @var string
	 */
	public readonly string $encryptedKey;

	/**
	 * Read the row.
	 *
	 * @param array<string,mixed> $row One {sourceSecretId, targetUserId, encryptedKey, ...} row
	 *
	 * @return void
	 */
	public function __construct(private readonly array $row) {
		$this->sourceSecretId = (string)($row['sourceSecretId'] ?? '');
		$this->targetUserId = (string)($row['targetUserId'] ?? '');
		$this->encryptedKey = (string)($row['encryptedKey'] ?? '');
	}//end __construct()

	/**
	 * Whether the row names a source, a recipient and a key.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function isComplete(): bool {
		return $this->sourceSecretId !== '' && $this->targetUserId !== '' && $this->encryptedKey !== '';
	}//end isComplete()

	/**
	 * The recipient and the ciphertext of their copy.
	 *
	 * @return RecipientCiphertext
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function ciphertext(): RecipientCiphertext {
		return new RecipientCiphertext(
			recipientId: $this->targetUserId,
			key: $this->encryptedKey,
			login: self::optional(value: $this->row['encryptedLogin'] ?? null),
			additionalFields: self::optional(value: $this->row['encryptedAdditionalFields'] ?? null),
			totp: self::optional(value: $this->row['encryptedTotp'] ?? null),
			passkeys: self::optional(value: $this->row['encryptedPasskeys'] ?? null)
		);
	}//end ciphertext()

	/**
	 * The report of this row skipped, with the reason.
	 *
	 * @param string $reason Why no share was created
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,reason:string}
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function skipped(string $reason): array {
		return [
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'reason' => $reason,
		];
	}//end skipped()

	/**
	 * The report of this row's share created, with the recipient's copy.
	 *
	 * @param string $copyId The recipient's copy
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,recipientSecretId:string}
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function created(string $copyId): array {
		return [
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'recipientSecretId' => $copyId,
		];
	}//end created()

	/**
	 * An optional blob: empty becomes null.
	 *
	 * @param mixed $value The submitted value
	 *
	 * @return string|null
	 */
	private static function optional(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$string = (string)$value;
		if ($string === '') {
			return null;
		}

		return $string;
	}//end optional()
}//end class
