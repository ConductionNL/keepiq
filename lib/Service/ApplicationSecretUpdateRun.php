<?php

/**
 * Keepiq Application Secret Update Run
 *
 * One machine write-back of a secret while SecretService applies it: the row
 * being changed, the row as it was loaded, the submitted fields, the owning
 * application and the time the write is stamped with. Built by
 * SecretService::updateByApplication() and never passed outside it.
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
 * The state one machine write-back of a secret shares between its steps.
 *
 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
 */
final class ApplicationSecretUpdateRun {

	/**
	 * The time the write is stamped with: the row's new version and, when
	 * the key changes, its ciphertext age.
	 *
	 * @var DateTime
	 */
	public readonly DateTime $now;

	/**
	 * The row as loaded, before any field is applied (secret-version-history §2.2).
	 *
	 * @var Secret
	 */
	public readonly Secret $preUpdate;

	/**
	 * Capture the write-back, cloning the pre-update snapshot before any field is applied.
	 *
	 * @param Secret              $secret        The row being changed, already scoped to the application's vault
	 * @param array<string,mixed> $data          The submitted fields (ciphertext + metadata)
	 * @param string              $applicationId The owning application ID
	 *
	 * @return void
	 */
	public function __construct(
		public readonly Secret $secret,
		public readonly array $data,
		public readonly string $applicationId,
	) {
		$this->now = new DateTime();
		$this->preUpdate = clone $secret;
	}//end __construct()

	/**
	 * Whether the write-back sends the given field.
	 *
	 * @param string $field The field name
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function sends(string $field): bool {
		return array_key_exists($field, $this->data);
	}//end sends()

	/**
	 * Whether the write-back replaced the key ciphertext.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function keyChanged(): bool {
		return $this->secret->getKey() !== $this->preUpdate->getKey();
	}//end keyChanged()
}//end class
