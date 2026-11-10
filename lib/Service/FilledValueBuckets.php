<?php

/**
 * Keepiq - The two buckets of a secret request's fill
 *
 * A fill sends its values in two buckets: ciphertext for the Secret's value
 * columns and the one blob that carries every additional member, and
 * plaintext metadata the owner searches on. Each value must arrive in the
 * bucket its column belongs to, or the fill is refused before anything is
 * read or written.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;

/**
 * The values of a fill, checked bucket by bucket.
 *
 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
 */
final class FilledValueBuckets {
	/**
	 * The encrypted bucket: the Secret's two ciphertext value columns, plus the
	 * one blob that carries every additional member.
	 *
	 * @param array<string,mixed> $encryptedFields fieldName => ciphertext
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException When a field name has nowhere to be stored
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function encrypted(array $encryptedFields): array {
		$data    = [];
		$allowed = array_merge(SecretRequestPolicy::ENCRYPTED_FIELDS, [SecretRequestPolicy::ADDITIONAL_BLOB]);
		foreach ($encryptedFields as $field => $value) {
			if (in_array($field, SecretRequestPolicy::PLAINTEXT_FIELDS, true) === true) {
				// Refused rather than stored: this column is searchable
				// plaintext, so a ciphertext here would break search and render
				// the value unreadable to its owner.
				throw new InvalidArgumentException(
					message: sprintf('Field "%s" is plaintext metadata and must be sent in plainFields', $field),
					code: 400
				);
			}

			if (in_array($field, $allowed, true) === false) {
				throw $this->unstorableField(field: (string)$field);
			}

			$data[$field] = $value;
		}

		return $data;
	}//end encrypted()

	/**
	 * The refusal of a field name that has nowhere to be stored on a secret.
	 *
	 * @param string $field The field name
	 *
	 * @return InvalidArgumentException
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	private function unstorableField(string $field): InvalidArgumentException {
		return new InvalidArgumentException(
			message: sprintf(
				'Field "%s" cannot be stored on a secret. Additional members belong inside the '
				. '"%s" blob, keyed by their own names.',
				$field,
				SecretRequestPolicy::ADDITIONAL_BLOB
			),
			code: 400
		);
	}//end unstorableField()

	/**
	 * The plaintext bucket: metadata the owner searches on.
	 *
	 * @param array<string,mixed> $plainFields fieldName => plaintext metadata
	 *
	 * @return array<string,mixed>
	 *
	 * @throws InvalidArgumentException When a field is not plaintext metadata
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function plain(array $plainFields): array {
		$data = [];
		foreach ($plainFields as $field => $value) {
			if (in_array($field, SecretRequestPolicy::PLAINTEXT_FIELDS, true) === false) {
				throw new InvalidArgumentException(
					message: sprintf('Field "%s" is not plaintext metadata and must be encrypted', $field),
					code: 400
				);
			}

			$data[$field] = $value;
		}

		return $data;
	}//end plain()
}//end class
