<?php

/**
 * Keepiq Federated Share Sources
 *
 * The secrets a partner share is made from, as FederatedShareService reads them:
 * a secret by id, the source of the caller's own copy of a secret they own,
 * and whether a secret to share is a received copy.
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The source secrets of outbound partner shares.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShareSources {

	/**
	 * Constructor for FederatedShareSources.
	 *
	 * @param SecretMapper $secretMapper The owner's secrets
	 * @param ShareRowJudge $rowJudge Judged share rows: whether a secret to share is a received copy, and of what
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareRowJudge $rowJudge,
	) {
	}//end __construct()

	/**
	 * A secret by id: the caller's own secret to share, or the source a partner pulls.
	 *
	 * @param string $secretId The secret
	 *
	 * @return Secret
	 *
	 * @throws DoesNotExistException When there is no such secret
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function find(string $secretId): Secret {
		return $this->secretMapper->findById($secretId);
	}//end find()

	/**
	 * The source of a received copy, when the caller owns that source too. After a team offboarding or an account
	 * deletion the new owner works through their own copy, as the source is still encrypted for the previous owner;
	 * their partner shares belong on the source (sharing-share-save-missed-partner-shares D3). Only a share row the
	 * runtime judge trusts leads from the copy to its source. Null for an original, for a copy of a secret someone
	 * else owns, and for a row the judge does not trust: assertNotReceivedCopy() refuses those copies.
	 *
	 * @param Secret $copy The caller's own secret, which may be a received copy
	 * @param string $userId The caller
	 *
	 * @return Secret|null
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#scenario-the-new-owner-shares-their-own-copy-with-a-partner
	 */
	public function ownedSourceOf(Secret $copy, string $userId): ?Secret {
		$row = $this->rowJudge->forCopy(copy: $copy)->trustedRow();
		if ($row === null) {
			return null;
		}

		try {
			$source = $this->secretMapper->findById($row->getSourceSecretId());
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}

		if ($source->getOwnerType() !== 'user' || $source->getOwnerId() !== $userId) {
			return null;
		}

		return $source;
	}//end ownedSourceOf()

	/**
	 * Refuse to share a received copy with a partner:
	 * partner shares hang off the original, which only its owner's save writes.
	 *
	 * @param Secret $source The secret to share
	 *
	 * @return void
	 *
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge the row that may name it a copy
	 * @throws \InvalidArgumentException `not_shareable` when it is a received copy
	 *
	 * @spec openspec/changes/sharing-share-save-missed-partner-shares/specs/federated-sharing/spec.md#requirement-a-received-copy-is-not-shared-with-a-partner
	 */
	public function assertNotReceivedCopy(Secret $source): void {
		$this->rowJudge->assertNotReceivedCopy(
			secret: $source,
			pending: 'This secret waits for the share quarantine scan, so it cannot be shared yet',
			refusal: 'not_shareable'
		);
	}//end assertNotReceivedCopy()
}//end class
