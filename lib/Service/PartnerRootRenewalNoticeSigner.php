<?php

/**
 * Keepiq Partner Root Renewal Notice Signer
 *
 * Who a root renewal notice comes from (partner-root-renewal-announcement D3): the listed partner, in any direction,
 * whose host the notice's `sender` names, and only when the incoming request is signed by that partner. Nothing else
 * in the notice is read.
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
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Kernel\AppIdentity;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\ICloudIdManager;
use OCP\OCM\IOCMDiscoveryService;
use Throwable;

/**
 * Names and authenticates the partner a renewal notice comes from.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class PartnerRootRenewalNoticeSigner {
	/**
	 * Constructor for PartnerRootRenewalNoticeSigner.
	 *
	 * @param FederationPartnerService $partners Names hosts as OCM signers do
	 * @param FederationPartnerMapper $partnerMapper The partner table
	 * @param IOCMDiscoveryService $ocmDiscovery Verifies a notice's signature
	 * @param ICloudIdManager $cloudIdManager Reads the sender a notice names
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerService $partners,
		private FederationPartnerMapper $partnerMapper,
		private IOCMDiscoveryService $ocmDiscovery,
		private ICloudIdManager $cloudIdManager,
	) {
	}//end __construct()

	/**
	 * Whose signature a renewal notice must carry: the listed partner its sender names.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return string The partner's OCM address, or '' when the sender is no listed partner
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function identity(array $payload): string {
		$partner = $this->partnerNamedBy(payload: $payload);
		if ($partner === null) {
			return '';
		}

		return $this->identityOf(partner: $partner);
	}//end identity()

	/**
	 * The listed partner a renewal notice names, when the incoming request is signed by that partner.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return FederationPartner|null Null when no listed partner signed it
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function signedPartner(array $payload): ?FederationPartner {
		$partner = $this->partnerNamedBy(payload: $payload);
		if ($partner === null) {
			return null;
		}

		try {
			$signer = $this->ocmDiscovery->getIncomingSignedRequest($this->identityOf(partner: $partner))?->getOrigin();
		} catch (Throwable) {
			$signer = null;
		}

		if ($signer === null || $this->partners->hostOf(url: $signer) !== $partner->getHost()) {
			return null;
		}

		return $partner;
	}//end signedPartner()

	/**
	 * The listed partner, in any direction, whose host the notice's `sender` names.
	 *
	 * @param array<array-key,mixed> $payload The notice
	 *
	 * @return FederationPartner|null
	 */
	private function partnerNamedBy(array $payload): ?FederationPartner {
		$sender = ($payload['sender'] ?? null);
		if (is_string($sender) === false || $this->cloudIdManager->isValidCloudId($sender) === false) {
			return null;
		}

		try {
			$host = $this->partners->hostOf(url: $this->cloudIdManager->resolveCloudId($sender)->getRemote());
			return $this->partnerMapper->findByHost(host: (string)$host);
		} catch (InvalidArgumentException | DoesNotExistException) {
			return null;
		}
	}//end partnerNamedBy()

	/**
	 * The OCM address a partner's notice is signed as.
	 *
	 * @param FederationPartner $partner The partner
	 *
	 * @return string
	 */
	private function identityOf(FederationPartner $partner): string {
		return AppIdentity::APP_ID . '@' . $partner->getHost();
	}//end identityOf()
}//end class
