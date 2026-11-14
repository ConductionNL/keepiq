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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

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
	 * @param SecretUrlService|null $urls The extra website addresses a copy carries from its source
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
		private ?SecretUrlService $urls = null,
	) {
	}//end __construct()

	/**
	 * Create the recipient's encrypted Secret copy from the ciphertext their
	 * sharer's browser encrypted for them.
	 *
	 * @param string              $sourceSecretId The owner's source secret ID
	 * @param RecipientCiphertext $ciphertext     The recipient and their copy's blobs
	 *
	 * @return Secret|string The copy, or why none was minted: `not_found` (the source is gone), `no_suite`
	 *   (the recipient has no active suite) or `copy_fields_mismatch` (the totp or passkeys sent does not
	 *   mirror the source's)
	 *
	 * @spec openspec/changes/team-folder-sharing/tasks.md#2.4
	 */
	public function createCopy(string $sourceSecretId, RecipientCiphertext $ciphertext): Secret|string {
		try {
			$source = $this->secretMapper->findById($sourceSecretId);
		} catch (DoesNotExistException) {
			return 'not_found';
		}

		// A copy without the source's TOTP seed or passkeys, or with ones it
		// does not hold, is not minted; the pair stays missing until a row
		// carries them.
		if ((new CredentialFields())->notMirrored(source: $source, copy: ['totp' => $ciphertext->totp, 'passkeys' => $ciphertext->passkeys]) !== []) {
			return 'copy_fields_mismatch';
		}

		$copies = new RecipientSecretCopyFactory(
			secretMapper: $this->secretMapper,
			suiteMapper: $this->suiteMapper,
			typeService: $this->typeService,
			urls: $this->urls
		);
		return $copies->createCopy(source: $source, ciphertext: $ciphertext) ?? 'no_suite';
	}//end createCopy()

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
