<?php

/**
 * Keepiq Recipient Ciphertext
 *
 * The blobs a sharer's browser encrypted for one recipient of a shared
 * secret under that recipient's active suite: the key, login and additional
 * fields, and the totp and passkeys when the source holds them. The server
 * never decrypts (ADR-003); it only stores them on the recipient's copy.
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
 * The recipient and the ciphertext of their copy.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
 */
final class RecipientCiphertext {

	/**
	 * Capture the ciphertext of one recipient's copy.
	 *
	 * @param string      $recipientId      The recipient user ID
	 * @param string      $key              The recipient-encrypted key blob
	 * @param string|null $login            The recipient-encrypted login blob
	 * @param string|null $additionalFields The recipient-encrypted additional-fields blob
	 * @param string|null $totp             The recipient-encrypted TOTP seed blob
	 * @param string|null $passkeys         The recipient-encrypted passkeys blob
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $recipientId,
		public readonly string $key,
		public readonly ?string $login,
		public readonly ?string $additionalFields,
		public readonly ?string $totp,
		public readonly ?string $passkeys,
	) {
	}//end __construct()
}//end class
