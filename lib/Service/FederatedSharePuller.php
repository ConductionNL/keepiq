<?php

/**
 * Keepiq Federated Share Puller
 *
 * Fetches a federated share's ciphertext from the sending partner
 * (sharing-federated-recipients D4): a signed OCM request to
 * `<sender OCM endpoint>/keepiq/shares/{id}` with the share's shared secret
 * in the payload, through `requestRemoteOcmEndpoint()`. The answer counts
 * only when it is a ciphertext for this share's own recipient.
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

use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCP\Federation\ICloudIdManager;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use RuntimeException;
use Throwable;

/**
 * Pulls ciphertext from the sending partner.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedSharePuller {
	/**
	 * Constructor for FederatedSharePuller.
	 *
	 * @param FederationPartnerMapper $partnerMapper The partner a share came from
	 * @param IOCMDiscoveryService $ocmDiscovery Signed OCM requests
	 * @param ICrypto $crypto Opens the stored shared secret
	 * @param ICloudIdManager $cloudIdManager The recipient's cloud id
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerMapper $partnerMapper,
		private IOCMDiscoveryService $ocmDiscovery,
		private ICrypto $crypto,
		private ICloudIdManager $cloudIdManager,
	) {
	}//end __construct()

	/**
	 * Ask the sender for the share's current ciphertext.
	 *
	 * @param FederatedInbound $row The inbound share
	 *
	 * @return array{name:string,url:?string,typeId:?string,key:string,login:?string,additionalFields:?string}
	 *
	 * @throws RuntimeException `pull_failed` for every failure
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function pull(FederatedInbound $row): array {
		try {
			$partner = $this->partnerMapper->findById(id: $row->getPartnerId());
			$response = $this->ocmDiscovery->requestRemoteOcmEndpoint(
				FederatedCertificateService::OCM_CAPABILITY,
				$partner->getBaseUrl(),
				FederatedCertificateService::OCM_CAPABILITY . '/shares/' . rawurlencode($row->getRemoteShareId()),
				['sharedSecret' => $this->crypto->decrypt($row->getSharedSecretEnc())],
				'post',
			);
			$status = $response->getStatusCode();
			$body = json_decode((string)$response->getBody(), true);
		} catch (Throwable) {
			throw new RuntimeException('pull_failed');
		}

		$recipient = $this->cloudIdManager->getCloudId($row->getRecipientUid(), null)->getId();
		if ($status !== 200 || is_array($body) === false
			|| is_string($body['key'] ?? null) === false || $body['key'] === ''
			|| ($body['recipientCloudId'] ?? null) !== $recipient
		) {
			throw new RuntimeException('pull_failed');
		}

		return [
			'name' => mb_substr((string)($body['name'] ?? $row->getName()), 0, 255),
			'url' => $this->optional(value: $body['url'] ?? null),
			'typeId' => $this->optional(value: $body['typeId'] ?? null),
			'key' => $body['key'],
			'login' => $this->optional(value: $body['login'] ?? null),
			'additionalFields' => $this->optional(value: $body['additionalFields'] ?? null),
		];
	}//end pull()

	/**
	 * A string value or null.
	 *
	 * @param mixed $value The value
	 *
	 * @return string|null
	 */
	private function optional(mixed $value): ?string {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		return $value;
	}//end optional()
}//end class
