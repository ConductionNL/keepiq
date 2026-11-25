<?php

/**
 * Keepiq Federated Share Resume
 *
 * The owner resumes a partner share that was suspended, for example after a
 * save without a partner that could not be reached (sharing-atomic-share-save
 * task 3.4, keepiq#1262). A suspended share kept the value from before the
 * suspension, so a resume always brings new ciphertext: the owner's browser
 * verifies the recipient's certificate again and encrypts the current value
 * for it. The server takes that value only for the version the browser named,
 * checked under the source's row lock, so a share-save that landed meanwhile
 * is never undone; such a resume is answered `stale_source` with the current
 * version. Status and value change in one transaction, and the recipient is
 * told after the commit, as after a share-save.
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
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;

/**
 * Makes a suspended partner share live again with a value encrypted now.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
 */
class FederatedShareResume {
	/**
	 * The ciphertext fields a resume carries besides `key`.
	 *
	 * @var list<string>
	 */
	private const VALUE_FIELDS = ['login', 'additionalFields', 'totp', 'passkeys'];

	/**
	 * Constructor for FederatedShareResume.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param FederatedShareService $shares Makes the share live and stores the value
	 * @param SecretVersionLock $locks The transaction holding the source's row lock
	 * @param FederatedNotificationDelivery $delivery Tells the recipient after the commit
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
	) {
	}//end __construct()

	/**
	 * Resume one of the owner's suspended partner shares.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param array<array-key,mixed> $body `certFingerprint`, `sourceUpdatedAt` (the version the value was encrypted
	 *   from), and the ciphertext `key`, `login`, `additionalFields`, `totp` and `passkeys`
	 *
	 * @return FederatedShare The live share
	 *
	 * @throws NotFoundException When it is not the user's suspended share, or its partner is gone
	 * @throws InvalidArgumentException `invalid`
	 * @throws ShareSaveRefusal `stale_source` (409) with the current version
	 * @throws PartnerUpgradeRequiredException When it carries a value field the partner has not advertised
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-the-owner-resumes-a-suspended-partner-share
	 */
	public function resume(string $shareId, string $userId, array $body): FederatedShare {
		$sent = ($body['sourceUpdatedAt'] ?? null);
		if (is_string($sent) === false || $sent === '' || strtotime($sent) === false) {
			throw new InvalidArgumentException('invalid');
		}

		$row = $this->suspendedShare(shareId: $shareId, userId: $userId);
		$certFingerprint = (string)($body['certFingerprint'] ?? '');
		$ciphertext = $this->ciphertextOf(body: $body);

		$row = $this->locks->whileLocked(
			id: $row->getSourceSecretId(),
			work: function (?DateTime $current) use ($row, $sent, $certFingerprint, $ciphertext): FederatedShare {
				$current = $this->currentVersion(sent: $sent, current: $current);

				return $this->shares->resumeSuspended(
					row: $row,
					certFingerprint: $certFingerprint,
					ciphertext: $ciphertext,
					savedAt: $current
				);
			}
		);

		$this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED);

		return $row;
	}//end resume()

	/**
	 * The source's version, when the browser encrypted from it.
	 *
	 * @param string $sent The version the browser encrypted from
	 * @param DateTime|null $current The source's version, null when it is gone
	 *
	 * @return DateTime
	 *
	 * @throws NotFoundException When the source is gone
	 * @throws ShareSaveRefusal `stale_source`
	 */
	private function currentVersion(string $sent, ?DateTime $current): DateTime {
		if ($current === null) {
			throw new NotFoundException(message: 'Share not found');
		}

		if ($current->getTimestamp() !== (new DateTime($sent))->getTimestamp()) {
			throw new ShareSaveRefusal(
				error: 'stale_source',
				message: 'The value was encrypted from an older version',
				status: Http::STATUS_CONFLICT,
				details: ['sourceUpdatedAt' => $current->format(DATE_ATOM)]
			);
		}

		return $current;
	}//end currentVersion()

	/**
	 * The ciphertext fields of the request; a field that is no string is null.
	 *
	 * @param array<array-key,mixed> $body The request
	 *
	 * @return array{key:string,login:?string,additionalFields:?string,totp:?string,passkeys:?string}
	 */
	private function ciphertextOf(array $body): array {
		$ciphertext = ['key' => (string)($body['key'] ?? '')];
		foreach (self::VALUE_FIELDS as $field) {
			$value = ($body[$field] ?? null);
			$ciphertext[$field] = null;
			if (is_string($value) === true) {
				$ciphertext[$field] = $value;
			}
		}

		return $ciphertext;
	}//end ciphertextOf()

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
