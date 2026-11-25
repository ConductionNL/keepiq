<?php

/**
 * Keepiq Federated Share Resume
 *
 * The owner resumes a partner share that was suspended, for example after
 * saving without a partner that could not be reached (sharing-atomic-share-save
 * task 3.4, keepiq#1262). The suspended share kept the value from before the
 * suspension, so a resume always carries new ciphertext: the owner's browser
 * looks up and verifies the recipient's certificate again and encrypts the
 * current value for it. The server takes it only for the version the browser
 * encrypted from, under the source's row lock, so a share-save that landed
 * meanwhile is never undone; the answer is then `stale_source` with the
 * current version. Status and ciphertext change in one transaction, and the
 * recipient is told after the commit, as a share-save tells it.
 *
 * Only a suspended share resumes. A share the recipient declined while it was
 * suspended is declined, not suspended (decision of 25 Nov 2026), so it is
 * refused: the owner shares again, which the recipient can accept or decline.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\SecretVersionLock;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use Throwable;

/**
 * Makes a suspended partner share live again with a value encrypted now.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */
class FederatedShareResume {
	/**
	 * Constructor for FederatedShareResume.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param FederatedShareService $shares Stores the new ciphertext as a share-save does
	 * @param SecretVersionLock $locks The source's row lock and version
	 * @param FederatedNotificationDelivery $delivery Tells the recipient after the commit
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 * @param IDBConnection $db The transaction
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private FederatedShareService $shares,
		private SecretVersionLock $locks,
		private FederatedNotificationDelivery $delivery,
		private FederatedShareAuditTrail $audit,
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * Resume one of the owner's suspended partner shares.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified now
	 * @param array{key:string,login:?string,additionalFields:?string,totp?:?string,passkeys?:?string} $ciphertext Encrypted for the recipient
	 * @param string $sourceUpdatedAt The version of the secret the browser encrypted from
	 *
	 * @return FederatedShare The live share
	 *
	 * @throws NotFoundException When it is not the user's suspended share, or its partner is gone
	 * @throws InvalidArgumentException `invalid`
	 * @throws ShareSaveRefusal `stale_source` (409) with the current version, or `copy_fields_mismatch`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 */
	public function resume(
		string $shareId,
		string $userId,
		string $certFingerprint,
		array $ciphertext,
		string $sourceUpdatedAt,
	): FederatedShare {
		if ($sourceUpdatedAt === '' || strtotime($sourceUpdatedAt) === false) {
			throw new InvalidArgumentException('invalid');
		}

		$row = $this->suspendedShare(shareId: $shareId, userId: $userId);

		$this->db->beginTransaction();
		try {
			$row = $this->resumeLocked(
				row: $row,
				certFingerprint: $certFingerprint,
				ciphertext: $ciphertext,
				sourceUpdatedAt: $sourceUpdatedAt
			);
			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}

		$this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED);

		return $row;
	}//end resume()

	/**
	 * Inside the transaction: check the version under the source's lock,
	 * make the share live while it is still suspended, and store the value.
	 *
	 * @param FederatedShare $row The suspended share
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified now
	 * @param array{key:string,login:?string,additionalFields:?string,totp?:?string,passkeys?:?string} $ciphertext Encrypted for the recipient
	 * @param string $sourceUpdatedAt The version the browser encrypted from
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When it stopped being suspended meanwhile
	 * @throws ShareSaveRefusal `stale_source`
	 */
	private function resumeLocked(
		FederatedShare $row,
		string $certFingerprint,
		array $ciphertext,
		string $sourceUpdatedAt,
	): FederatedShare {
		$current = $this->locks->lock(id: $row->getSourceSecretId());
		if ($current === null) {
			throw new NotFoundException(message: 'Share not found');
		}

		if ($current->getTimestamp() !== (new DateTime($sourceUpdatedAt))->getTimestamp()) {
			throw new ShareSaveRefusal(
				error: 'stale_source',
				message: 'The value was encrypted from an older version',
				status: Http::STATUS_CONFLICT,
				details: ['sourceUpdatedAt' => $current->format(DATE_ATOM)]
			);
		}

		// A revoke or a decline that landed meanwhile wins.
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$row->stamp(now: new DateTime());
		if ($this->shareMapper->updateIfStatus(
			share: $row,
			status: FederatedShare::STATUS_SUSPENDED,
			ending: FederatedNotificationDelivery::SHARE_UNSHARED
		) === 0
		) {
			throw new NotFoundException(message: 'Share not found');
		}

		$row = $this->shares->writeCiphertext(
			row: $row,
			certFingerprint: $certFingerprint,
			ciphertext: $ciphertext,
			savedAt: $current
		);
		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_RESUMED, row: $row, actorId: $row->getOwnerId());

		return $row;
	}//end resumeLocked()

	/**
	 * The user's own suspended share, or not found.
	 *
	 * @param string $shareId The share
	 * @param string $userId The owner
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException
	 */
	private function suspendedShare(string $shareId, string $userId): FederatedShare {
		try {
			$row = $this->shareMapper->findById(id: $shareId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Share not found');
		}

		if ($row->getOwnerId() !== $userId || $row->getStatus() !== FederatedShare::STATUS_SUSPENDED) {
			throw new NotFoundException(message: 'Share not found');
		}

		return $row;
	}//end suspendedShare()
}//end class
