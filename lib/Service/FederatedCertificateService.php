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
 *   It records the value fields the partner advertised, so a share it cannot hold is refused.
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
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudIdManager;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\OCM\IOCMDiscoveryService;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Federated certificate lookup, answering and asking.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Both sides of the lookup in
 *   one place: partners, the local CA, users, suites, OCM and the log.
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
	 * A partner's chain: its intermediate, then its root, as
	 * FederationRootService::localChain() serves it.
	 *
	 * @var int
	 */
	private const CHAIN_LENGTH = 2;

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
	 * @param LoggerInterface $logger Warnings about a partner's answer
	 * @param FederationPartnerMapper $partnerMapper Records the value fields a partner advertises
	 * @param IEventDispatcher|null $eventDispatcher Audits a change of those value fields
	 * @param AuditEventFactory $auditEvents Builds the audit events
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list, one collaborator per concern.
	 */
	public function __construct(
		private FederationPartnerService $partners,
		private FederationRootService $root,
		private ShareService $shareService,
		private ICloudIdManager $cloudIdManager,
		private IUserManager $userManager,
		private IConfig $config,
		private IOCMDiscoveryService $ocmDiscovery,
		private LoggerInterface $logger,
		private FederationPartnerMapper $partnerMapper,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Answer a partner's certificate lookup, or null for the unknown answer.
	 *
	 * @param string|null $signer The verified signer (OCMEndpointRequestEvent::getRemote()), null when unsigned
	 * @param array<array-key,mixed> $payload The request payload, `{cloudId}`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>,valueFields:string[]}|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
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
			'valueFields' => FederatedValueFields::VALUE_FIELDS,
		];
	}//end answer()

	/**
	 * Whether users here can share with another organisation at all: this
	 * Nextcloud supports federation and at least one partner allows
	 * outbound shares. With none, the share dialog offers nothing.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-no-partner-no-federation
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
	 * The answer names the value fields beyond `key`, `login` and `additionalFields` the partner stores,
	 * so the browser leaves out a `totp` or `passkeys` value it cannot hold.
	 *
	 * @param string $cloudId The recipient's cloud id, `bob@cloud.partner.example`
	 * @param string $userId The owner asking; their cloud id goes along as `sender`
	 *
	 * @return array{cloudId:string,certificate:string,chain:array<int,string>,partnerRootFingerprint:string,valueFields:list<string>}
	 *
	 * @throws InvalidArgumentException `not_a_partner` or `unknown_recipient`
	 * @throws RuntimeException `federation_unavailable`, `partner_unreachable` or `bad_chain`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
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

		$answer = $this->parseAnswer(status: $status, body: $body, partner: $partner->getBaseUrl());

		return [
			'cloudId' => $resolved->getId(),
			'certificate' => $answer['certificate'],
			'chain' => $answer['chain'],
			'partnerRootFingerprint' => $partner->getRootFingerprint(),
			'valueFields' => $this->recordValueFields(partner: $partner, advertised: $body['valueFields'] ?? null),
		];
	}//end lookup()

	/**
	 * Store the value fields a partner advertised in its lookup answer, so a share to it can be refused when it cannot hold them.
	 * The answer's `valueFields` is not covered by the browser's certificate check, so a change is audited by field
	 * name, and one that drops a field the partner stored is logged as a warning.
	 *
	 * @param FederationPartner $partner The partner that answered
	 * @param mixed $advertised Its `valueFields`, absent on an older Keepiq
	 *
	 * @return list<string> The recorded fields, empty when it advertised none
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	private function recordValueFields(FederationPartner $partner, mixed $advertised): array {
		$valueFields = new FederatedValueFields();
		$recorded = $valueFields->toRecord(advertised: $advertised);
		$fields = ($valueFields->advertised(advertised: $advertised) ?? []);
		$previous = $partner->getValueFields();
		if ($recorded === $previous) {
			return $fields;
		}

		$partner->setValueFields($recorded);
		$this->partnerMapper->update($partner);

		$was = ($valueFields->advertised(advertised: json_decode((string)$previous, true)) ?? []);
		$dropped = array_values(array_diff($was, $fields));
		if ($dropped !== []) {
			$this->logger->warning(
				'Keepiq: partner {partner} no longer advertises it stores {fields}; shares carrying them are refused until it does',
				['partner' => $partner->getBaseUrl(), 'fields' => implode(', ', $dropped)]
			);
		}

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forSystem(
				eventType: AuditEventTypes::FEDERATION_PARTNER_VALUE_FIELDS_CHANGED,
				objectType: 'federation_partner',
				objectId: $partner->getId(),
				metadata: ['partnerId' => $partner->getId(), 'from' => $was, 'to' => $fields],
			)
		);

		return $fields;
	}//end recordValueFields()

	/**
	 * Check a partner's lookup answer.
	 *
	 * @param int $status The HTTP status
	 * @param mixed $body The decoded body
	 * @param string $partner The partner's base URL, for the log
	 *
	 * @return array{certificate:string,chain:array<int,string>}
	 *
	 * @throws InvalidArgumentException `unknown_recipient` on the partner's 404
	 * @throws RuntimeException `bad_chain` on a chain other than an intermediate and a root,
	 *   `partner_unreachable` on anything else that is not a valid answer
	 */
	private function parseAnswer(int $status, mixed $body, string $partner): array {
		if ($status === 404) {
			throw new InvalidArgumentException('unknown_recipient');
		}

		if ($status !== 200 || is_array($body) === false || is_string($body['certificate'] ?? null) === false) {
			throw new RuntimeException('partner_unreachable');
		}

		$chain = $this->chainOf(chain: $body['chain'] ?? null);
		if ($chain === null) {
			$this->logger->warning(
				'Keepiq: partner ' . $partner . ' answered a certificate lookup with a chain other than its intermediate and root',
				['app' => Application::APP_ID]
			);
			throw new RuntimeException('bad_chain');
		}

		return [
			'certificate' => $body['certificate'],
			'chain' => $chain,
		];
	}//end parseAnswer()

	/**
	 * A partner's chain when it is exactly an intermediate and a root, as PEM
	 * strings, or null. Keepiq serves no other.
	 *
	 * @param mixed $chain The `chain` of the answer
	 *
	 * @return list<string>|null
	 */
	private function chainOf(mixed $chain): ?array {
		if (is_array($chain) === false || array_is_list($chain) === false || count($chain) !== self::CHAIN_LENGTH) {
			return null;
		}

		foreach ($chain as $pem) {
			if (is_string($pem) === false) {
				return null;
			}
		}

		return $chain;
	}//end chainOf()

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
