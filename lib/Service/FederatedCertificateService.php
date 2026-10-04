<?php

/**
 * Keepiq Federated Certificate Service
 *
 * Certificate lookup between partner instances (sharing-federated-recipients
 * D3), both ways:
 *
 * - `answer()` is the partner-facing side, behind `/ocm/keepiq/recipient-certificate`.
 *   It answers only a verified inbound partner, and only about a user of this
 *   instance who allows receiving from other organisations and holds an
 *   active suite. Every other case returns null, which the caller turns into
 *   the one unknown-recipient answer, so a probe cannot tell a stranger, an
 *   opted-out user and a missing user apart.
 * - `lookup()` is the owner-facing side: it asks an outbound partner through
 *   Nextcloud's signed OCM request and returns the certificate, the chain and
 *   the pinned root fingerprint for the owner's browser to verify.
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
use OCA\Keepiq\AppInfo\Application;
use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\OCM\IOCMDiscoveryService;
use RuntimeException;
use Throwable;

/**
 * Federated certificate lookup, answering and asking.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */
class FederatedCertificateService {
	/**
	 * The OCM capability Keepiq advertises and answers under.
	 *
	 * @var string
	 */
	public const OCM_CAPABILITY = 'keepiq';

	/**
	 * The user preference that opts a user in to receiving from partners.
	 *
	 * @var string
	 */
	public const RECEIVE_PREFERENCE = 'federation_receive';

	/**
	 * Constructor for FederatedCertificateService.
	 *
	 * @param FederationPartnerService $partners The partner allowlist
	 * @param FederationRootService $root The local root and chain
	 * @param ShareService $shareService Active suite certificates
	 * @param ICloudIdManager $cloudIdManager Cloud id parsing
	 * @param IUserManager $userManager Local users
	 * @param IConfig $config User preferences
	 * @param IOCMDiscoveryService $ocmDiscovery Signed OCM requests to partners
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerService $partners,
		private FederationRootService $root,
		private ShareService $shareService,
		private ICloudIdManager $cloudIdManager,
		private IUserManager $userManager,
		private IConfig $config,
		private IOCMDiscoveryService $ocmDiscovery,
	) {
	}//end __construct()

	/**
	 * Answer a partner's certificate lookup, or null for the unknown answer.
	 *
	 * @param string|null $signer The verified signer (OCMEndpointRequestEvent::getRemote()), null when unsigned
	 * @param array<array-key,mixed> $payload The request payload, `{cloudId}`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>}|null
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function answer(?string $signer, array $payload): ?array {
		if ($this->partners->inboundPartnerForSigner(signer: $signer) === null) {
			return null;
		}

		$userId = $this->localUserOf(cloudId: $payload['cloudId'] ?? null);
		if ($userId === null) {
			return null;
		}

		$optedIn = $this->config->getUserValue($userId, Application::APP_ID, self::RECEIVE_PREFERENCE, '0');
		if ($optedIn !== '1') {
			return null;
		}

		$certificate = ($this->shareService->recipientCertificates(targetUserIds: [$userId])[$userId] ?? null);
		$chain = $this->root->localChain();
		if ($certificate === null || $chain === []) {
			return null;
		}

		return [
			'cloudId' => (string)$payload['cloudId'],
			'certificate' => $certificate,
			'chain' => $chain,
		];
	}//end answer()

	/**
	 * Whether users here can share with another organisation at all: this
	 * Nextcloud supports federation and at least one partner allows
	 * outbound shares. With none, the share dialog offers nothing.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
	 */
	public function outboundAvailable(): bool {
		if ($this->root->isSupported() === false) {
			return false;
		}

		foreach ($this->partners->all() as $partner) {
			if ($partner->getAllowOutbound() === true) {
				return true;
			}
		}

		return false;
	}//end outboundAvailable()

	/**
	 * Ask an outbound partner for a recipient's certificate.
	 *
	 * @param string $cloudId The recipient's cloud id, `bob@cloud.partner.example`
	 * @param string $userId The owner asking; their cloud id goes along as `sender`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>,partnerRootFingerprint:string}
	 *
	 * @throws InvalidArgumentException `not_a_partner` or `unknown_recipient`
	 * @throws RuntimeException `federation_unavailable` or `partner_unreachable`
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function lookup(string $cloudId, string $userId): array {
		// The OCM call requestRemoteOcmEndpoint() arrived in Nextcloud 33 with the
		// OCM endpoint event that isSupported() checks for.
		if ($this->root->isSupported() === false) {
			throw new RuntimeException('federation_unavailable');
		}

		try {
			$resolved = $this->cloudIdManager->resolveCloudId($cloudId);
		} catch (InvalidArgumentException) {
			throw new InvalidArgumentException('unknown_recipient');
		}

		$partner = $this->partners->outboundPartnerForRemote(remote: $resolved->getRemote());
		if ($partner === null) {
			throw new InvalidArgumentException('not_a_partner');
		}

		try {
			$response = $this->ocmDiscovery->requestRemoteOcmEndpoint(
				self::OCM_CAPABILITY,
				$partner->getBaseUrl(),
				self::OCM_CAPABILITY . '/recipient-certificate',
				// Nextcloud 35 verifies an RFC 9421 signature on /ocm/<capability>
				// only when the body names an OCM address (`owner`, `sender` or
				// `sharedBy`) to take the signer's origin from.
				['cloudId' => $resolved->getId(), 'sender' => $this->cloudIdManager->getCloudId($userId, null)->getId()],
				'post',
			);
			$status = $response->getStatusCode();
			$body = json_decode((string)$response->getBody(), true);
		} catch (Throwable $exception) {
			$status = $this->statusOf(exception: $exception);
			$body = null;
		}

		$answer = $this->parseAnswer(status: $status, body: $body);

		return [
			'cloudId' => $resolved->getId(),
			'certificate' => $answer['certificate'],
			'chain' => $answer['chain'],
			'partnerRootFingerprint' => $partner->getRootFingerprint(),
		];
	}//end lookup()

	/**
	 * Check a partner's lookup answer.
	 *
	 * @param int $status The HTTP status
	 * @param mixed $body The decoded body
	 *
	 * @return array{certificate:string,chain:array<int,string>}
	 *
	 * @throws InvalidArgumentException `unknown_recipient` on the partner's 404
	 * @throws RuntimeException `partner_unreachable` on anything else that is not a valid answer
	 */
	private function parseAnswer(int $status, mixed $body): array {
		if ($status === 404) {
			throw new InvalidArgumentException('unknown_recipient');
		}

		if ($status !== 200 || is_array($body) === false
			|| is_string($body['certificate'] ?? null) === false
			|| is_array($body['chain'] ?? null) === false
		) {
			throw new RuntimeException('partner_unreachable');
		}

		return [
			'certificate' => $body['certificate'],
			'chain' => array_values(array_filter($body['chain'], 'is_string')),
		];
	}//end parseAnswer()

	/**
	 * The local user a cloud id names, or null when it names no user of this
	 * instance.
	 *
	 * @param mixed $cloudId The cloud id from the payload
	 *
	 * @return string|null
	 */
	private function localUserOf(mixed $cloudId): ?string {
		if (is_string($cloudId) === false || $this->cloudIdManager->isValidCloudId($cloudId) === false) {
			return null;
		}

		try {
			$resolved = $this->cloudIdManager->resolveCloudId($cloudId);
		} catch (InvalidArgumentException) {
			return null;
		}

		$userId = $resolved->getUser();
		if ($this->userManager->userExists($userId) === false) {
			return null;
		}

		// Ours only when it names this user on this instance. Compared by host,
		// because an http instance writes its own cloud id with the scheme.
		$own = $this->cloudIdManager->getCloudId($userId, null);
		$ownHost = $this->partners->hostOf(url: $own->getRemote());
		if ($own->getUser() !== $userId || $ownHost === null || $ownHost !== $this->partners->hostOf(url: $resolved->getRemote())) {
			return null;
		}

		return $userId;
	}//end localUserOf()

	/**
	 * The HTTP status a failed partner request carried, or 0.
	 *
	 * @param Throwable $exception The failure
	 *
	 * @return int
	 */
	private function statusOf(Throwable $exception): int {
		if (method_exists($exception, 'getResponse') === true) {
			$response = $exception->getResponse();
			if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
				return (int)$response->getStatusCode();
			}
		}

		return 0;
	}//end statusOf()
}//end class
