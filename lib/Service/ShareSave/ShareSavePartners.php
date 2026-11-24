<?php

/**
 * Keepiq ShareSavePartners
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCP\AppFramework\Http;
use Psr\Log\LoggerInterface;

/**
 * The partner shares a share-save reads: the source's active ones, whether they are its owner's,
 * and how many hang off the copies it writes.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
 */
class ShareSavePartners {
	/**
	 * Constructor.
	 *
	 * @param FederatedShareMapper $federatedShares Outbound partner shares
	 * @param LoggerInterface|null $logger Warns about partner shares that are not the secret owner's
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FederatedShareMapper $federatedShares,
		private ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * The source's active partner shares, keyed by id.
	 *
	 * @param Secret $source The source
	 *
	 * @return array<string,FederatedShare>
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function active(Secret $source): array {
		$active = [];
		foreach ($this->federatedShares->findBySourceSecret(sourceSecretId: $source->getId()) as $share) {
			if ($share->getStatus() === FederatedShare::STATUS_ACTIVE) {
				$active[$share->getId()] = $share;
			}
		}

		return $active;
	}//end active()

	/**
	 * How many active partner shares hang off these secrets.
	 *
	 * @param list<string> $sourceSecretIds The secrets
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-partner-shares-made-from-a-copy-are-reported
	 */
	public function countActive(array $sourceSecretIds): int {
		return $this->federatedShares->countActiveBySourceSecrets(sourceSecretIds: $sourceSecretIds);
	}//end countActive()

	/**
	 * Refuse a save that would write partner shares while one of them is not the source owner's:
	 * an ownership transfer left it behind, and only the `ReownTransferredPartnerShares` repair step hands it over.
	 *
	 * @param ShareSavePath $path The path
	 * @param array<string,FederatedShare> $federated The source's active partner shares
	 *
	 * @return void
	 *
	 * @throws ShareSaveRefusal `partner_share_owner_mismatch`
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-partner-share-the-owner-does-not-own-stops-the-save
	 */
	public function assertOwnedBySourceOwner(ShareSavePath $path, array $federated): void {
		$ownerId = $path->sourceOwnerId();
		$foreign = array_keys(array_filter($federated, static fn (FederatedShare $share): bool => $share->getOwnerId() !== $ownerId));
		if ($foreign === []) {
			return;
		}

		$this->logger?->warning(
			'Share-save refused: partner shares of the secret are not its owner\'s; run occ maintenance:repair to hand them over',
			['app' => 'keepiq', 'secretId' => $path->source->getId(), 'shareIds' => $foreign]
		);
		throw new ShareSaveRefusal(
			error: 'partner_share_owner_mismatch',
			message: 'A partner share of this secret is not its owner\'s; an administrator must repair the partner shares first',
			status: Http::STATUS_CONFLICT
		);
	}//end assertOwnedBySourceOwner()
}//end class
