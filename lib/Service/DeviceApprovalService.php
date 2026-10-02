<?php

/**
 * Keepiq DeviceApprovalService
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

use DateInterval;
use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Db\DeviceApprovalMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Ramsey\Uuid\Uuid;

/**
 * The server side of new device approval (crypto-new-device-approval):
 * it stores requests, relays a sealed unlock key it cannot open, and gives
 * it to the requesting device once. Every lookup is scoped to the request's
 * own user, and a request of another user answers exactly like an unknown id.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The request lifecycle, its
 *   notification and its audit trail in one place.
 */
class DeviceApprovalService {

	/**
	 * App config switch; default on (D5).
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'device_approval_enabled';

	/**
	 * How long a request stays open, in seconds (D1).
	 *
	 * @var int
	 */
	public const TTL_SECONDS = 900;

	/**
	 * The client kinds that may ask.
	 *
	 * @var string[]
	 */
	public const CLIENT_KINDS = ['web', 'extension'];

	/**
	 * Constructor for DeviceApprovalService.
	 *
	 * @param DeviceApprovalMapper $mapper          The request mapper
	 * @param NotificationService  $notifications   The notification dispatcher
	 * @param IAppConfig           $appConfig       The app config (switch)
	 * @param ISecureRandom        $random          The secure random generator
	 * @param IEventDispatcher     $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory    $auditEvents     The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private DeviceApprovalMapper $mapper,
		private NotificationService $notifications,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		private IEventDispatcher $eventDispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Whether an administrator left device approval on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::ENABLED_KEY, true);
	}//end isEnabled()

	/**
	 * Store a request from a locked device and notify the user.
	 *
	 * @param string $userId     The signed-in user
	 * @param string $publicKey  The one-time X25519 public key (base64, 32 bytes)
	 * @param string $clientKind `web` or `extension`
	 * @param string $label      The device label
	 * @param string $address    The caller's IP address
	 * @param string $agent      The caller's user agent
	 *
	 * @return array{id:string,requestSecret:string,expiresAt:string}
	 *
	 * @throws ForbiddenException       When the feature is off
	 * @throws InvalidArgumentException When the input is malformed
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Each argument is one recorded fact of the request.
	 */
	public function create(
		string $userId,
		string $publicKey,
		string $clientKind,
		string $label,
		string $address,
		string $agent,
	): array {
		if ($this->isEnabled() === false) {
			throw new ForbiddenException(message: 'Device approval is turned off');
		}

		$raw = base64_decode($publicKey, true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new InvalidArgumentException(message: 'publicKey must be a raw X25519 public key');
		}

		if (in_array($clientKind, self::CLIENT_KINDS, true) === false) {
			throw new InvalidArgumentException(message: 'clientKind must be web or extension');
		}

		$secret = $this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
		$now    = new DateTime();

		$request = new DeviceApproval();
		$request->setId(Uuid::uuid4()->toString());
		$request->setUserId($userId);
		$request->setClientKind($clientKind);
		$request->setDeviceLabel(mb_substr(trim($label), 0, 255));
		$request->setRequesterIp(mb_substr($address, 0, 64));
		$request->setRequesterAgent(mb_substr($agent, 0, 512));
		$request->setRequestPublicKey($publicKey);
		$request->setRequestSecretHash(hash('sha256', $secret));
		$request->setStatus(DeviceApproval::STATUS_PENDING);
		$request->setCreatedAt($now);
		$request->setExpiresAt((clone $now)->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')));
		$request = $this->mapper->insert($request);

		$this->notifications->notify(
			subject: 'device_approval_requested',
			recipientId: $userId,
			params: [
				'request_id' => $request->getId(),
				'device_label' => $request->getDeviceLabel(),
			],
			objectType: 'device_approval',
			objectId: $request->getId(),
		);
		$this->audit(request: $request, eventType: AuditEventTypes::DEVICE_APPROVAL_REQUESTED, metadata: ['clientKind' => $clientKind]);

		return [
			'id' => $request->getId(),
			'requestSecret' => $secret,
			'expiresAt' => (string)$request->getExpiresAt()?->format('c'),
		];
	}//end create()

	/**
	 * The user's open requests, for the approving device.
	 *
	 * @param string $userId The user
	 *
	 * @return DeviceApproval[]
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
	 */
	public function pending(string $userId): array {
		return $this->mapper->findPendingForUser($userId, new DateTime());
	}//end pending()

	/**
	 * Deny one of the user's open requests.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the request is not an open request of this user
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function deny(string $id, string $userId): void {
		$request = $this->loadOpen(id: $id, userId: $userId);
		$request->setStatus(DeviceApproval::STATUS_DENIED);
		$request->setDecidedAt(new DateTime());
		$this->mapper->update($request);
		$this->audit(request: $request, eventType: AuditEventTypes::DEVICE_APPROVAL_DENIED);
	}//end deny()

	/**
	 * Approve one of the user's open requests with the unlock key sealed to
	 * its one-time key. The vault-key proof is checked before this runs
	 * (VaultKeyProofRequired on the route).
	 *
	 * @param string $id              The request
	 * @param string $userId          The user
	 * @param string $sealedUnlockKey The HPKE-sealed unlock key
	 *
	 * @return void
	 *
	 * @throws NotFoundException        When the request is not an open request of this user
	 * @throws InvalidArgumentException When no sealed key is given
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
	 */
	public function approve(string $id, string $userId, string $sealedUnlockKey): void {
		if (trim($sealedUnlockKey) === '') {
			throw new InvalidArgumentException(message: 'sealedUnlockKey is required');
		}

		$request = $this->loadOpen(id: $id, userId: $userId);
		$request->setStatus(DeviceApproval::STATUS_APPROVED);
		$request->setSealedUnlockKey($sealedUnlockKey);
		$request->setDecidedAt(new DateTime());
		$this->mapper->update($request);
		$this->audit(request: $request, eventType: AuditEventTypes::DEVICE_APPROVAL_APPROVED);
	}//end approve()

	/**
	 * The requesting device's poll. Needs the request secret. Returns the
	 * sealed key once, then clears it and marks the request consumed.
	 *
	 * @param string $id            The request
	 * @param string $userId        The user
	 * @param string $requestSecret The secret returned at creation
	 *
	 * @return array{status:string,sealedUnlockKey?:string}
	 *
	 * @throws NotFoundException When the request is unknown, foreign, or the secret is wrong
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
	 */
	public function pickup(string $id, string $userId, string $requestSecret): array {
		$request = $this->loadOwn(id: $id, userId: $userId);
		if ($requestSecret === '' || hash_equals($request->getRequestSecretHash(), hash('sha256', $requestSecret)) === false) {
			throw new NotFoundException(message: 'Request not found');
		}

		$status = $request->getStatus();
		$open = [DeviceApproval::STATUS_PENDING, DeviceApproval::STATUS_APPROVED];
		if ($this->isLapsed(request: $request) === true && in_array($status, $open, true) === true) {
			return ['status' => DeviceApproval::STATUS_EXPIRED];
		}

		$sealed = $request->getSealedUnlockKey();
		if ($status !== DeviceApproval::STATUS_APPROVED || $sealed === null || $sealed === '') {
			return ['status' => $status];
		}

		$request->setSealedUnlockKey(null);
		$request->setStatus(DeviceApproval::STATUS_CONSUMED);
		$this->mapper->update($request);
		$this->audit(request: $request, eventType: AuditEventTypes::DEVICE_APPROVAL_PICKED_UP);

		return ['status' => DeviceApproval::STATUS_APPROVED, 'sealedUnlockKey' => $sealed];
	}//end pickup()

	/**
	 * Mark lapsed requests expired and drop any sealed key never picked up.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number expired
	 *
	 * @spec openspec/changes/crypto-new-device-approval/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function expireLapsed(DateTime $now): int {
		$count = 0;
		foreach ($this->mapper->findLapsed($now) as $request) {
			$request->setStatus(DeviceApproval::STATUS_EXPIRED);
			$request->setSealedUnlockKey(null);
			$this->mapper->update($request);
			$this->audit(request: $request, eventType: AuditEventTypes::DEVICE_APPROVAL_EXPIRED);
			++$count;
		}

		return $count;
	}//end expireLapsed()

	/**
	 * A request of this user, or the unknown-id answer.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return DeviceApproval
	 *
	 * @throws NotFoundException
	 */
	private function loadOwn(string $id, string $userId): DeviceApproval {
		try {
			$request = $this->mapper->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Request not found');
		}

		if ($request->getUserId() !== $userId) {
			throw new NotFoundException(message: 'Request not found');
		}

		return $request;
	}//end loadOwn()

	/**
	 * An open (pending, not expired) request of this user, or the unknown-id answer.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return DeviceApproval
	 *
	 * @throws NotFoundException
	 */
	private function loadOpen(string $id, string $userId): DeviceApproval {
		$request = $this->loadOwn(id: $id, userId: $userId);
		if ($request->getStatus() !== DeviceApproval::STATUS_PENDING || $this->isLapsed(request: $request) === true) {
			throw new NotFoundException(message: 'Request not found');
		}

		return $request;
	}//end loadOpen()

	/**
	 * Whether a request is past its expiry.
	 *
	 * @param DeviceApproval $request The request
	 *
	 * @return bool
	 */
	private function isLapsed(DeviceApproval $request): bool {
		$expires = $request->getExpiresAt();
		return $expires === null || $expires <= new DateTime();
	}//end isLapsed()

	/**
	 * Record a transition with identifiers only.
	 *
	 * @param DeviceApproval      $request   The request
	 * @param string              $eventType The event type
	 * @param array<string,mixed> $metadata  Whitelisted metadata
	 *
	 * @return void
	 */
	private function audit(DeviceApproval $request, string $eventType, array $metadata = []): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $request->getUserId(),
				eventType: $eventType,
				objectType: 'device_approval',
				objectId: $request->getId(),
				metadata: $metadata,
			)
		);
	}//end audit()
}//end class
