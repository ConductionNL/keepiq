<?php

/**
 * Keepiq Federation Partner Service
 *
 * The explicit, pinned partner allowlist (sharing-federated-recipients D1):
 * an administrator adds a partner by URL after comparing the fingerprint of
 * its Keepiq root with the partner's administrator, and sets whether secrets
 * may go out to it and come in from it. Every federation path asks this
 * service whether a host is a partner in the needed direction.
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
use OCA\Keepiq\Controller\DiscoveryController;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Partner allowlist: add, pin, permissions, and lookups by host.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartnerService {
	/**
	 * Seconds to wait for a partner's discovery document.
	 *
	 * @var int
	 */
	private const DISCOVERY_TIMEOUT = 10;

	/**
	 * Constructor for FederationPartnerService.
	 *
	 * @param FederationPartnerMapper $partnerMapper The partner mapper
	 * @param IClientService $clientService HTTP client for partner discovery
	 * @param IConfig $config System config (allow_local_remote_servers)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private FederationPartnerMapper $partnerMapper,
		private IClientService $clientService,
		private IConfig $config,
	) {
	}//end __construct()

	/**
	 * A URL's identity as Nextcloud's signature code names a signer:
	 * the lowercase host, plus `:port` when the URL names a port.
	 *
	 * @param string $url A URL or a bare host
	 *
	 * @return string|null Null when there is no host
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function hostOf(string $url): ?string {
		$url = trim($url);
		if ($url === '') {
			return null;
		}

		if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url) !== 1) {
			$url = 'https://' . $url;
		}

		$host = parse_url($url, PHP_URL_HOST);
		if (is_string($host) === false || $host === '') {
			return null;
		}

		$port = parse_url($url, PHP_URL_PORT);
		$host = strtolower($host);
		if (is_int($port) === true) {
			$host .= ':' . $port;
		}

		return $host;
	}//end hostOf()

	/**
	 * Normalise a partner base URL: https (http only where Nextcloud allows
	 * local remote servers), no query or fragment, no trailing slash.
	 *
	 * @param string $url The URL the administrator typed
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the URL is not usable
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function normaliseBaseUrl(string $url): string {
		$parts = parse_url(trim($url));
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$allowHttp = $this->config->getSystemValueBool('allow_local_remote_servers', false);
		if (isset($parts['host']) === false || ($scheme !== 'https' && ($scheme !== 'http' || $allowHttp === false))) {
			throw new InvalidArgumentException('The partner address must be an https URL');
		}

		if (isset($parts['user']) === true || isset($parts['query']) === true || isset($parts['fragment']) === true) {
			throw new InvalidArgumentException('The partner address must not carry credentials, a query or a fragment');
		}

		$base = $scheme . '://' . strtolower($parts['host']);
		if (isset($parts['port']) === true) {
			$base .= ':' . $parts['port'];
		}

		return $base . rtrim((string)($parts['path'] ?? ''), '/');
	}//end normaliseBaseUrl()

	/**
	 * Read a partner's discovery document and its federation block.
	 *
	 * @param string $url The partner address
	 *
	 * @return array{baseUrl:string,host:string,rootFingerprint:string}
	 *
	 * @throws InvalidArgumentException When the partner cannot federate
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function preview(string $url): array {
		$baseUrl = $this->normaliseBaseUrl(url: $url);
		try {
			$response = $this->clientService->newClient()->get(
				$baseUrl . '/index.php/apps/keepiq' . DiscoveryController::CANONICAL_DISCOVERY_PATH,
				['timeout' => self::DISCOVERY_TIMEOUT, 'headers' => ['Accept' => 'application/json']]
			);
			$document = json_decode((string)$response->getBody(), true);
		} catch (Throwable) {
			throw new InvalidArgumentException('The partner could not be reached');
		}

		$federation = null;
		if (is_array($document) === true) {
			$federation = ($document['federation'] ?? null);
		}

		$fingerprint = null;
		if (is_array($federation) === true) {
			$fingerprint = ($federation['rootFingerprint'] ?? null);
		}

		if (is_array($federation) === false || ($federation['enabled'] ?? false) !== true
			|| is_string($fingerprint) === false || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1
		) {
			throw new InvalidArgumentException('The partner does not offer Keepiq federation');
		}

		return [
			'baseUrl' => $baseUrl,
			'host' => (string)$this->hostOf(url: $baseUrl),
			'rootFingerprint' => $fingerprint,
		];
	}//end preview()

	/**
	 * Add a partner. The fingerprint the administrator confirmed must still be
	 * the one the partner publishes, so a change between preview and save is
	 * caught.
	 *
	 * @param string $url The partner address
	 * @param string $confirmedFingerprint The fingerprint the administrator compared
	 * @param bool $allowOutbound Whether users here may share to the partner
	 * @param bool $allowInbound Whether the partner may look up and deliver here
	 * @param string $adminId The administrator
	 *
	 * @return FederationPartner
	 *
	 * @throws InvalidArgumentException When the partner is invalid, known, or its fingerprint changed
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function add(
		string $url,
		string $confirmedFingerprint,
		bool $allowOutbound,
		bool $allowInbound,
		string $adminId,
	): FederationPartner {
		$preview = $this->preview(url: $url);
		if (hash_equals($preview['rootFingerprint'], strtolower(trim($confirmedFingerprint))) === false) {
			throw new InvalidArgumentException('The partner root fingerprint is not the one you confirmed');
		}

		if ($this->findByHost(host: $preview['host']) !== null) {
			throw new InvalidArgumentException('This partner is already added');
		}

		$partner = new FederationPartner();
		$partner->setId(Uuid::uuid4()->toString());
		$partner->setBaseUrl($preview['baseUrl']);
		$partner->setHost($preview['host']);
		$partner->setRootFingerprint($preview['rootFingerprint']);
		$partner->setAllowOutbound($allowOutbound);
		$partner->setAllowInbound($allowInbound);
		$partner->setAddedBy($adminId);
		$partner->setAddedAt(new DateTime());

		return $this->partnerMapper->insert(entity: $partner);
	}//end add()

	/**
	 * Change a partner's directions.
	 *
	 * @param string $id The partner UUID
	 * @param bool $allowOutbound Whether users here may share to the partner
	 * @param bool $allowInbound Whether the partner may look up and deliver here
	 *
	 * @return FederationPartner
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function update(string $id, bool $allowOutbound, bool $allowInbound): FederationPartner {
		$partner = $this->partnerMapper->findById(id: $id);
		$partner->setAllowOutbound($allowOutbound);
		$partner->setAllowInbound($allowInbound);

		return $this->partnerMapper->update(entity: $partner);
	}//end update()

	/**
	 * Remove a partner.
	 *
	 * @param string $id The partner UUID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function remove(string $id): void {
		$this->partnerMapper->delete(entity: $this->partnerMapper->findById(id: $id));
	}//end remove()

	/**
	 * Every partner.
	 *
	 * @return FederationPartner[]
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function all(): array {
		return $this->partnerMapper->findAllPartners();
	}//end all()

	/**
	 * Whether any partner exists; with none, federation is off.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function hasAny(): bool {
		return $this->partnerMapper->findAllPartners() !== [];
	}//end hasAny()

	/**
	 * The partner a verified OCM signer belongs to, when it may call in.
	 *
	 * @param string|null $signer The signer origin from OCMEndpointRequestEvent::getRemote(), null when unsigned
	 *
	 * @return FederationPartner|null Null for an unsigned call, a stranger, or a partner without inbound
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function inboundPartnerForSigner(?string $signer): ?FederationPartner {
		if ($signer === null) {
			return null;
		}

		$partner = $this->findByHost(host: (string)$this->hostOf(url: $signer));
		if ($partner === null || $partner->getAllowInbound() !== true) {
			return null;
		}

		return $partner;
	}//end inboundPartnerForSigner()

	/**
	 * The partner users here may share to, by the host of a cloud id's remote.
	 *
	 * @param string $remote The cloud id's remote (URL or host)
	 *
	 * @return FederationPartner|null Null when that host is no outbound partner
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
	 */
	public function outboundPartnerForRemote(string $remote): ?FederationPartner {
		$partner = $this->findByHost(host: (string)$this->hostOf(url: $remote));
		if ($partner === null || $partner->getAllowOutbound() !== true) {
			return null;
		}

		return $partner;
	}//end outboundPartnerForRemote()

	/**
	 * A partner by host, or null.
	 *
	 * @param string $host The host identity
	 *
	 * @return FederationPartner|null
	 */
	private function findByHost(string $host): ?FederationPartner {
		if ($host === '') {
			return null;
		}

		try {
			return $this->partnerMapper->findByHost(host: $host);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findByHost()
}//end class
