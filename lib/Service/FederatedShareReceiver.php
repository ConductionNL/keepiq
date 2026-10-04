<?php

/**
 * Keepiq Federated Share Receiver
 *
 * Takes the OCM shares of type `keepiq-secret` that Nextcloud's
 * cloud_federation_api hands to KeepiqSecretFederationProvider
 * (sharing-federated-recipients D4). A share is stored as pending only when
 * the verified signer of the request is an inbound partner, the owner's
 * cloud id names that same instance, and the recipient is a local user who
 * opted in to receiving. The user is notified. Anything else is refused with
 * one answer and nothing is stored.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Db\FederationPartner;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationShare;
use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Incoming OCM shares of Keepiq secrets.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Taking a share in is one
 *   decision over Nextcloud's signer, cloud ids, users, preferences and
 *   crypto, plus the row it stores and the refusal it throws; splitting the
 *   checks apart would spread one security decision over several classes.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-non-partner-cannot-deliver
 */
class FederatedShareReceiver {
	/**
	 * The one refusal every rejected share gets, so a sender learns nothing
	 * about which check failed.
	 *
	 * @var string
	 */
	public const REFUSAL = 'Share refused';

	/**
	 * Constructor for FederatedShareReceiver.
	 *
	 * @param FederatedInboundMapper $inboundMapper Inbound share rows
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param IOCMDiscoveryService $ocmDiscovery The verified signer of the request
	 * @param ICloudIdManager $cloudIdManager Cloud id parsing
	 * @param IUserManager $userManager Local users
	 * @param IConfig $config The receive preference
	 * @param ICrypto $crypto Keeps the shared secret
	 * @param NotificationService $notifications Tells the recipient
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederatedInboundMapper $inboundMapper,
		private FederationPartnerService $partners,
		private IOCMDiscoveryService $ocmDiscovery,
		private ICloudIdManager $cloudIdManager,
		private IUserManager $userManager,
		private IConfig $config,
		private ICrypto $crypto,
		private NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * Store an announced share as pending and notify the recipient.
	 *
	 * @param ICloudFederationShare $share The OCM share; shareWith is the local uid
	 *
	 * @return string The inbound share id
	 *
	 * @throws ProviderCouldNotAddShareException When the share is refused
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-non-partner-cannot-deliver
	 */
	public function receive(ICloudFederationShare $share): string {
		$partner = $this->senderPartner(owner: (string)$share->getOwner());
		$userId = (string)$share->getShareWith();
		$providerId = (string)$share->getProviderId();
		$sharedSecret = (string)$share->getShareSecret();

		if ($partner === null
			|| $share->getResourceType() !== FederatedShareService::RESOURCE_TYPE
			|| $share->getShareType() !== 'user'
			|| $providerId === '' || $sharedSecret === ''
			|| $this->mayReceive(userId: $userId) === false
			|| $this->known(partner: $partner, remoteShareId: $providerId) === true
		) {
			throw new ProviderCouldNotAddShareException(self::REFUSAL, '', 403);
		}

		$now = new DateTime();
		$row = new FederatedInbound();
		$row->setId(Uuid::uuid4()->toString());
		$row->setRecipientUid($userId);
		$row->setSenderCloudId((string)$share->getOwner());
		$row->setPartnerId($partner->getId());
		$row->setRemoteShareId($providerId);
		$row->setName(mb_substr((string)$share->getResourceName(), 0, 255));
		$row->setSharedSecretEnc($this->crypto->encrypt($sharedSecret));
		$row->setStatus(FederatedInbound::STATUS_PENDING);
		$row->setReceivedAt($now);
		$row->setUpdatedAt($now);
		$this->inboundMapper->insert(entity: $row);

		$this->notifications->notify(
			subject: 'federated_share_received',
			recipientId: $userId,
			params: ['shared_by' => $row->getSenderCloudId(), 'secret_name' => $row->getName()],
			objectType: 'federated_inbound',
			objectId: $row->getId(),
		);

		return $row->getId();
	}//end receive()

	/**
	 * The partner a share comes from: the verified signer of this request
	 * must be an inbound partner, and the owner's cloud id must name that
	 * same instance. Null for an unsigned or badly signed request.
	 *
	 * @param string $owner The owner's cloud id from the share
	 *
	 * @return FederationPartner|null
	 */
	private function senderPartner(string $owner): ?FederationPartner {
		try {
			// The owner's address names whose signature this is; Nextcloud 35
			// cannot verify an RFC 9421 signature without it.
			$signed = $this->ocmDiscovery->getIncomingSignedRequest($owner);
			$ownerId = $this->cloudIdManager->resolveCloudId($owner);
		} catch (Throwable) {
			return null;
		}

		if ($signed === null) {
			return null;
		}

		$partner = $this->partners->inboundPartnerForSigner(signer: $signed->getOrigin());
		if ($partner === null || $this->partners->hostOf(url: $ownerId->getRemote()) !== $partner->getHost()) {
			return null;
		}

		return $partner;
	}//end senderPartner()

	/**
	 * Whether a local user exists and opted in to receiving.
	 *
	 * @param string $userId The local uid
	 *
	 * @return bool
	 */
	private function mayReceive(string $userId): bool {
		return $userId !== ''
			&& $this->userManager->userExists($userId) === true
			&& $this->config->getUserValue(
				$userId,
				Application::APP_ID,
				FederatedCertificateService::RECEIVE_PREFERENCE,
				'0'
			) === '1';
	}//end mayReceive()

	/**
	 * Whether this partner already announced a share under that id.
	 *
	 * @param FederationPartner $partner The sending partner
	 * @param string $remoteShareId The share id on the sender
	 *
	 * @return bool
	 */
	private function known(FederationPartner $partner, string $remoteShareId): bool {
		try {
			$this->inboundMapper->findByRemote(partnerId: $partner->getId(), remoteShareId: $remoteShareId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end known()
}//end class
