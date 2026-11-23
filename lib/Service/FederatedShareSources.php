<?php

/**
 * Keepiq Federated Share Sources
 *
 * The secrets a partner share is made from, as FederatedShareService reads them:
 * the caller's own secret, the source a partner pulls, and whether a secret to share is a received copy.
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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\AppFramework\Db\DoesNotExistException;

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
	 * @param ShareRowJudge $rowJudge Judged share rows: whether a secret to share is a received copy
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
	 * A secret by id, as a partner's pull reads its share's source.
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
	 * The user's own secret.
	 *
	 * @param string $secretId The secret
	 * @param string $userId The user
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When it does not exist or is not the user's own
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function owned(string $secretId, string $userId): Secret {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return $secret;
	}//end owned()

	/**
	 * Refuse to share a received copy with a partner:
	 * partner shares hang off the original, which only its owner's save writes.
	 *
	 * @param Secret $source The secret to share
	 *
	 * @return void
	 *
	 * @throws ShareScanPendingException When the pending quarantine scan cannot judge the row that may name it a copy
	 * @throws InvalidArgumentException `not_shareable` when it is a received copy
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
