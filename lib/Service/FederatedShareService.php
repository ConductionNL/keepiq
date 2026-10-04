<?php

/**
 * Keepiq Federated Share Service
 *
 * The sending side of federated sharing (sharing-federated-recipients D4):
 *
 * - `create()` stores the ciphertext the owner's browser made for a user of
 *   an outbound partner and has FederatedShareMessenger announce the share
 *   over OCM. The announcement carries the owner's cloud id, the share id
 *   and a fresh shared secret, never the ciphertext; only the secret's hash
 *   is kept here.
 * - `answerPull()` serves `/ocm/keepiq/shares/{id}`: the ciphertext, only to
 *   the signer that is the recipient's partner and presents the shared
 *   secret. Every refusal is null, which the listener turns into the one
 *   unknown answer.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\ICloudIdManager;
use OCP\Security\ISecureRandom;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Outbound federated shares.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The owner's side of a
 *   federated share joins the secret, the partner allowlist, the share row
 *   and the OCM messenger in one transaction-like step, and answers the
 *   partner's pull from the same row; each refusal is its own exception.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShareService {
	/**
	 * The OCM resource type Keepiq registers and sends.
	 *
	 * @var string
	 */
	public const RESOURCE_TYPE = 'keepiq-secret';

	/**
	 * Length of a share's shared secret.
	 *
	 * @var int
	 */
	private const SHARED_SECRET_LENGTH = 64;

	/**
	 * Constructor for FederatedShareService.
	 *
	 * @param FederatedShareMapper $shareMapper Outbound share rows
	 * @param SecretMapper $secretMapper The owner's secrets
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param FederationRootService $root Whether this Nextcloud can federate
	 * @param ICloudIdManager $cloudIdManager Cloud id parsing
	 * @param FederatedShareMessenger $messenger OCM messages to the recipient's instance
	 * @param ISecureRandom $random The shared secret
	 * @param FederatedNotificationDelivery $delivery Notifications with retries
	 * @param FederatedShareAuditTrail $audit Identifier-only audit
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedShareMapper $shareMapper,
		private SecretMapper $secretMapper,
		private FederationPartnerService $partners,
		private FederationRootService $root,
		private ICloudIdManager $cloudIdManager,
		private FederatedShareMessenger $messenger,
		private ISecureRandom $random,
		private FederatedNotificationDelivery $delivery,
		private FederatedShareAuditTrail $audit,
	) {
	}//end __construct()

	/**
	 * Store the browser-made ciphertext for a federated recipient and announce
	 * the share over OCM.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $userId The owner
	 * @param string $recipientCloudId The recipient, `bob@cloud.partner.example`
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified and encrypted for
	 * @param array{key:string,login:?string,additionalFields:?string} $ciphertext Encrypted for the recipient
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When the secret is not the user's own
	 * @throws InvalidArgumentException `invalid`, `not_a_partner`, `unknown_recipient` or `already_shared`
	 * @throws RuntimeException `federation_unavailable`, or `delivery_failed` when the partner refused it
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function create(
		string $secretId,
		string $userId,
		string $recipientCloudId,
		string $certFingerprint,
		array $ciphertext,
	): FederatedShare {
		if ($this->root->isSupported() === false) {
			throw new RuntimeException('federation_unavailable');
		}

		$source = $this->ownedSecret(secretId: $secretId, userId: $userId);
		// A read-only or restricted copy never leaves (task 3.4).
		$source->assertOnwardShareable();

		$certFingerprint = strtolower(trim($certFingerprint));
		if (preg_match('/^[0-9a-f]{64}$/', $certFingerprint) !== 1 || ($ciphertext['key'] ?? '') === '') {
			throw new InvalidArgumentException('invalid');
		}

		try {
			$recipient = $this->cloudIdManager->resolveCloudId($recipientCloudId);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException('unknown_recipient');
		}

		$partner = $this->partners->outboundPartnerForRemote(remote: $recipient->getRemote());
		if ($partner === null) {
			throw new InvalidArgumentException('not_a_partner');
		}

		$this->assertNotSharedWith(secretId: $secretId, recipientCloudId: $recipient->getId());

		$sharedSecret = $this->random->generate(self::SHARED_SECRET_LENGTH, ISecureRandom::CHAR_ALPHANUMERIC);
		$now = new DateTime();
		$row = new FederatedShare();
		$row->setId(Uuid::uuid4()->toString());
		$row->setSourceSecretId($secretId);
		$row->setOwnerId($userId);
		$row->setRecipientCloudId($recipient->getId());
		$row->setPartnerId($partner->getId());
		$row->setRecipientCertFingerprint($certFingerprint);
		$row->setKey($ciphertext['key']);
		$row->setLogin($ciphertext['login'] ?? null);
		$row->setAdditionalFields($ciphertext['additionalFields'] ?? null);
		$row->setSharedSecretHash(hash('sha256', $sharedSecret));
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$row->setNotifyAttempts(0);
		$row->setCreatedAt($now);
		$row->setUpdatedAt($now);
		$row = $this->shareMapper->insert(entity: $row);

		if ($this->messenger->announce(row: $row, name: $source->getName(), sharedSecret: $sharedSecret) === false) {
			$this->shareMapper->delete(entity: $row);
			throw new RuntimeException('delivery_failed');
		}

		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_SENT, row: $row, actorId: $userId);

		return $row;
	}//end create()

	/**
	 * Replace a share's ciphertext after the owner changed the secret, and
	 * tell the recipient's instance to pull again (task 4.1). The owner's
	 * browser made the new ciphertext for a freshly verified certificate.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified now
	 * @param array{key:string,login:?string,additionalFields:?string} $ciphertext Encrypted for the recipient
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When it is not the user's live share
	 * @throws InvalidArgumentException `invalid`
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
	 */
	public function update(string $shareId, string $userId, string $certFingerprint, array $ciphertext): FederatedShare {
		$row = $this->ownedShare(shareId: $shareId, userId: $userId);
		if ($row->getStatus() !== FederatedShare::STATUS_ACTIVE) {
			throw new NotFoundException(message: 'Share not found');
		}

		$certFingerprint = strtolower(trim($certFingerprint));
		if (preg_match('/^[0-9a-f]{64}$/', $certFingerprint) !== 1 || ($ciphertext['key'] ?? '') === '') {
			throw new InvalidArgumentException('invalid');
		}

		$row->setRecipientCertFingerprint($certFingerprint);
		$row->setKey($ciphertext['key']);
		$row->setLogin($ciphertext['login'] ?? null);
		$row->setAdditionalFields($ciphertext['additionalFields'] ?? null);
		$row->setUpdatedAt(new DateTime());
		$this->shareMapper->update(entity: $row);

		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_UPDATED, row: $row, actorId: $userId);
		$this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UPDATED);

		return $row;
	}//end update()

	/**
	 * Revoke a share: nothing is served any more, and the recipient's
	 * instance is told to delete its copy (task 4.2). The row goes once that
	 * notification arrives; until then the retry job keeps trying.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 *
	 * @return void
	 *
	 * @throws NotFoundException When it is not the user's share
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
	 */
	public function revoke(string $shareId, string $userId): void {
		$row = $this->ownedShare(shareId: $shareId, userId: $userId);
		$row->setStatus(FederatedShare::STATUS_REVOKED);
		$row->setUpdatedAt(new DateTime());
		$this->shareMapper->update(entity: $row);

		$this->audit->recordOutbound(eventType: AuditEventTypes::FEDERATED_SHARE_REVOKED, row: $row, actorId: $userId);
		$this->delivery->deliver(row: $row, type: FederatedNotificationDelivery::SHARE_UNSHARED);
	}//end revoke()

	/**
	 * Suspend a share whose recipient certificate no longer verifies in the
	 * owner's browser (task 4.2): nothing is served until the owner revokes
	 * it or shares again.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param string $reason Why, as the browser reports it
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When it is not the user's share
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function suspend(string $shareId, string $userId, string $reason): FederatedShare {
		$row = $this->ownedShare(shareId: $shareId, userId: $userId);

		return $this->suspendRow(row: $row, actorId: $userId, reason: $reason);
	}//end suspend()

	/**
	 * Suspend every share to users of a partner that was removed (task 4.2).
	 *
	 * @param string $partnerId The removed partner
	 *
	 * @return int How many shares were suspended
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function suspendForPartner(string $partnerId): int {
		$count = 0;
		foreach ($this->shareMapper->findByPartner(partnerId: $partnerId) as $row) {
			if ($row->getStatus() === FederatedShare::STATUS_ACTIVE) {
				$this->suspendRow(row: $row, actorId: null, reason: 'partner_removed');
				$count++;
			}
		}

		return $count;
	}//end suspendForPartner()

	/**
	 * Suspend one row and record it.
	 *
	 * @param FederatedShare $row The share
	 * @param string|null $actorId The owner, or null for the system
	 * @param string $reason Why
	 *
	 * @return FederatedShare
	 */
	private function suspendRow(FederatedShare $row, ?string $actorId, string $reason): FederatedShare {
		$reason = (string)preg_replace('/[^a-z_]/', '', strtolower($reason));
		if ($reason === '') {
			$reason = 'unverified';
		}
		$row->setStatus(FederatedShare::STATUS_SUSPENDED);
		$row->setUpdatedAt(new DateTime());
		$this->shareMapper->update(entity: $row);
		$this->audit->recordOutbound(
			eventType: AuditEventTypes::FEDERATED_SHARE_SUSPENDED,
			row: $row,
			actorId: $actorId,
			extra: ['reason' => mb_substr($reason, 0, 32)],
		);

		return $row;
	}//end suspendRow()

	/**
	 * The user's own outbound share, or not found.
	 *
	 * @param string $shareId The share
	 * @param string $userId The owner
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException
	 */
	private function ownedShare(string $shareId, string $userId): FederatedShare {
		try {
			$row = $this->shareMapper->findById(id: $shareId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Share not found');
		}

		if ($row->getOwnerId() !== $userId) {
			throw new NotFoundException(message: 'Share not found');
		}

		return $row;
	}//end ownedShare()

	/**
	 * Refuse a second live share to the same recipient. After a suspended,
	 * failed or revoked one the owner shares again, as the share list tells
	 * them to.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $recipientCloudId The recipient
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException `already_shared`
	 */
	private function assertNotSharedWith(string $secretId, string $recipientCloudId): void {
		foreach ($this->shareMapper->findBySourceSecret(sourceSecretId: $secretId) as $existing) {
			if ($existing->getRecipientCloudId() === $recipientCloudId
				&& $existing->getStatus() === FederatedShare::STATUS_ACTIVE
			) {
				throw new InvalidArgumentException('already_shared');
			}
		}
	}//end assertNotSharedWith()

	/**
	 * The federated shares of one of the user's own secrets.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $userId The owner
	 *
	 * @return FederatedShare[]
	 *
	 * @throws NotFoundException When the secret is not the user's own
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function listForSecret(string $secretId, string $userId): array {
		$this->ownedSecret(secretId: $secretId, userId: $userId);

		return $this->shareMapper->findBySourceSecret(sourceSecretId: $secretId);
	}//end listForSecret()

	/**
	 * Answer the recipient server's pull of `/ocm/keepiq/shares/{id}`, or null
	 * for the unknown answer.
	 *
	 * @param string|null $signer The verified signer, null when unsigned
	 * @param string $shareId The share id from the path
	 * @param array<array-key,mixed> $payload The request payload, `{sharedSecret}`
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function answerPull(?string $signer, string $shareId, array $payload): ?array {
		$sharedSecret = $payload['sharedSecret'] ?? null;
		if ($signer === null || is_string($sharedSecret) === false || $sharedSecret === '') {
			return null;
		}

		try {
			$row = $this->shareMapper->findById(id: $shareId);
			$source = $this->secretMapper->findById($row->getSourceSecretId());
		} catch (DoesNotExistException) {
			return null;
		}

		// The signer must be the recipient's own partner, still allowed to
		// receive from here, and the share must be live.
		$partner = $this->partners->outboundPartnerForRemote(remote: $signer);
		if ($partner === null || $partner->getId() !== $row->getPartnerId()
			|| $row->getStatus() !== FederatedShare::STATUS_ACTIVE
			|| hash_equals($row->getSharedSecretHash(), hash('sha256', $sharedSecret)) === false
		) {
			return null;
		}

		return [
			'shareId' => $row->getId(),
			'ownerCloudId' => $this->cloudIdManager->getCloudId($row->getOwnerId(), null)->getId(),
			'recipientCloudId' => $row->getRecipientCloudId(),
			'recipientCertFingerprint' => $row->getRecipientCertFingerprint(),
			'name' => $source->getName(),
			'url' => $source->getUrl(),
			'typeId' => $source->getTypeId(),
			'key' => $row->getKey(),
			'login' => $row->getLogin(),
			'additionalFields' => $row->getAdditionalFields(),
			'updatedAt' => $row->getUpdatedAt()?->format(DATE_ATOM),
		];
	}//end answerPull()

	/**
	 * The user's own secret, or not found.
	 *
	 * @param string $secretId The secret
	 * @param string $userId The user
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When it does not exist or is someone else's
	 */
	private function ownedSecret(string $secretId, string $userId): Secret {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return $secret;
	}//end ownedSecret()
}//end class
