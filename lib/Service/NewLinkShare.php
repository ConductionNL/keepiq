<?php

/**
 * Keepiq New Link Share
 *
 * One public link share a user asks to create for a secret.
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\LinkShare;
use Ramsey\Uuid\Uuid;

/**
 * The secret, the browser-encrypted snapshot and its Argon2id salt, the suite
 * it was taken under, the usage limit, the optional expiry and the creator.
 *
 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
 */
final class NewLinkShare {
	/**
	 * Constructor for NewLinkShare.
	 *
	 * @param string $secretId The secret ID the snapshot is taken from
	 * @param string $encryptedSnapshot The AES-256-GCM encrypted snapshot blob (base64)
	 * @param string $salt The base64-encoded Argon2id salt
	 * @param string $encryptionSuiteId The active encryption suite ID at creation
	 * @param int $usageLimit The usage limit (1-10)
	 * @param DateTime|null $expiresAt Optional expiry timestamp
	 * @param string $userId The owning Nextcloud user ID
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $secretId,
		public readonly string $encryptedSnapshot,
		public readonly string $salt,
		public readonly string $encryptionSuiteId,
		public readonly int $usageLimit,
		public readonly ?DateTime $expiresAt,
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * Refuse a link share without its secret, snapshot or salt, or with a
	 * usage limit outside 1-10.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When validation fails
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 */
	public function assertValid(): void {
		if ($this->secretId === '' || $this->encryptedSnapshot === '' || $this->salt === '') {
			throw new InvalidArgumentException('Missing required link share fields');
		}

		if ($this->usageLimit < LinkShareService::MIN_USAGE_LIMIT || $this->usageLimit > LinkShareService::MAX_USAGE_LIMIT) {
			throw new InvalidArgumentException(
				'Usage limit must be between ' . LinkShareService::MIN_USAGE_LIMIT . ' and ' . LinkShareService::MAX_USAGE_LIMIT
			);
		}
	}//end assertValid()

	/**
	 * The link-share row, unused and with no failed attempts, created now.
	 *
	 * @param string $token The access token
	 *
	 * @return LinkShare
	 *
	 * @spec openspec/specs/link-sharing/spec.md#requirement-who-may-create-a-link-share
	 */
	public function toRow(string $token): LinkShare {
		$linkShare = new LinkShare();
		$linkShare->setId(Uuid::uuid4()->toString());
		$linkShare->setSecretId($this->secretId);
		$linkShare->setToken($token);
		$linkShare->setEncryptedSecretSnapshot($this->encryptedSnapshot);
		$linkShare->setArgon2idSalt($this->salt);
		$linkShare->setEncryptionSuiteId($this->encryptionSuiteId);
		$linkShare->setUsageLimit($this->usageLimit);
		$linkShare->setUsageCount(0);
		$linkShare->setFailedAttempts(0);
		$linkShare->setCreatedBy($this->userId);
		$linkShare->setCreatedAt(new DateTime());
		$linkShare->setExpiresAt($this->expiresAt);

		return $linkShare;
	}//end toRow()
}//end class
