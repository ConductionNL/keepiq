<?php

/**
 * Keepiq Recipient Secret Copy Service
 *
 * Materialises the RECIPIENT'S encrypted copy of a shared secret from
 * browser-supplied ciphertext, and removes it again when the share is
 * revoked. Mirrors SecretService::create's entity construction: plaintext
 * metadata (name/url) is copied from the source, the type is re-resolved for
 * the recipient (their unavailable custom types fall back to the system
 * default), the folder is left null (the recipient organises their own tree),
 * and the ciphertext fields are stored verbatim — the server never decrypts
 * anything (ADR-003).
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Creates and deletes recipient copies of shared secrets.
 */
class RecipientSecretCopyService {
	/**
	 * Constructor for RecipientSecretCopyService.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (recipient suite)
	 * @param SecretTypeService $typeService The secret-type resolver (copy typing)
	 * @param LoggerInterface|null $logger The logger (skipped cross-user deletes)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretTypeService $typeService,
		private ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Create the recipient's encrypted Secret copy from browser-supplied
	 * ciphertext.
	 *
	 * @param string $sourceSecretId The owner's source secret ID
	 * @param string $targetUserId The recipient user ID
	 * @param string $encryptedKey The RSA-encrypted key blob
	 * @param string|null $encryptedLogin The RSA-encrypted login blob
	 * @param string|null $encryptedExtras The RSA-encrypted additional-fields blob
	 * @param string|null $encryptedTotp The RSA-encrypted TOTP seed blob
	 * @param string|null $encryptedPasskeys The RSA-encrypted passkeys blob
	 *
	 * @return Secret|null Null when the source is gone or the recipient has no active suite
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function create(
		string $sourceSecretId,
		string $targetUserId,
		string $encryptedKey,
		?string $encryptedLogin,
		?string $encryptedExtras,
		?string $encryptedTotp = null,
		?string $encryptedPasskeys = null,
	): ?Secret {
		try {
			$source = $this->secretMapper->findById($sourceSecretId);
		} catch (DoesNotExistException) {
			return null;
		}

		try {
			$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId);
		} catch (DoesNotExistException) {
			return null;
		}

		try {
			$typeId = $this->typeService->resolveTypeForSecret($source->getTypeId(), $targetUserId);
		} catch (InvalidArgumentException) {
			// Owner's custom type is not visible to the recipient — default.
			$typeId = $this->typeService->resolveTypeForSecret(null, $targetUserId);
		}

		$now = new DateTime();
		$copy = new Secret();
		$copy->setId(Uuid::uuid4()->toString());
		$copy->setName($source->getName());
		$copy->setUrl($source->getUrl());
		$copy->setTypeId($typeId);
		$copy->setFolderId(null);
		$copy->setKey($encryptedKey);
		$copy->setLogin($encryptedLogin);
		$copy->setAdditionalFields($encryptedExtras);
		$copy->setTotp($encryptedTotp);
		$copy->setPasskeys($encryptedPasskeys);
		$copy->setEncryptionSuiteId($suite->getId());
		$copy->setOwnerType('user');
		$copy->setOwnerId($targetUserId);
		$copy->setCreatedAt($now);
		$copy->setUpdatedAt($now);
		$copy->setKeyUpdatedAt($now);
		$this->secretMapper->insert($copy);

		return $copy;
	}//end create()

	/**
	 * Delete a recipient's encrypted Secret copy (best-effort — a missing
	 * copy is already gone). Defence in depth: a copy not genuinely held by
	 * the share's target (a poisoned or drifted `secret_id`) is kept, so
	 * revoking a team-folder share can never delete another user's secret.
	 *
	 * @param ShareTarget $share The share row whose copy is being removed
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function deleteByShare(ShareTarget $share): void {
		try {
			$copy = $this->secretMapper->findById($share->getSecretId());
		} catch (DoesNotExistException) {
			// Already gone.
			return;
		}

		if ($share->isHeldByTarget(copy: $copy) === false) {
			$this->logger?->warning(
				'Kept a secret not held by the revoked team-folder share target',
				['app' => 'keepiq', 'shareId' => $share->getId(), 'secretId' => $share->getSecretId()]
			);
			return;
		}

		$this->secretMapper->delete($copy);
	}//end deleteByShare()
}//end class
