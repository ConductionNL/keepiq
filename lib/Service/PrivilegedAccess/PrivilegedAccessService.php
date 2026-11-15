<?php

/**
 * Keepiq PrivilegedAccessService
 *
 * @category Service
 * @package  OCA\Keepiq\Service\PrivilegedAccess
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

namespace OCA\Keepiq\Service\PrivilegedAccess;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Keepiq\Db\AccessRequest;
use OCA\Keepiq\Db\AccessRequestMapper;
use OCA\Keepiq\Db\PrivilegedLogin;
use OCA\Keepiq\Db\PrivilegedLoginMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * The owner marks a login as privileged; a recipient asks for a time window;
 * the owner approves it, optionally narrowed to a shorter window and to
 * address ranges, or denies it. Each step is audited and notified.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One flow over flags, requests, shares, audit and notifications.
 */
class PrivilegedAccessService {

	/**
	 * The longest reason a request may give.
	 *
	 * @var int
	 */
	public const MAX_REASON_LENGTH = 500;

	/**
	 * Constructor.
	 *
	 * @param SecretMapper          $secrets       The secrets
	 * @param PrivilegedLoginMapper $flags         The privileged marks
	 * @param AccessRequestMapper   $requests      The access requests
	 * @param ShareRowJudge         $rows          The judged share rows
	 * @param NotificationService   $notifications The notification dispatcher
	 * @param AuditService          $audit         The audit trail
	 * @param ITimeFactory          $time          The clock
	 * @param LoggerInterface       $logger        The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secrets,
		private PrivilegedLoginMapper $flags,
		private AccessRequestMapper $requests,
		private ShareRowJudge $rows,
		private NotificationService $notifications,
		private AuditService $audit,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * What a user may see of a secret's privileged state. The owner gets the
	 * mark and every request; a recipient gets the mark, their own requests
	 * and whether a window allows use now.
	 *
	 * @param string $secretId The owner's secret or the recipient's copy
	 * @param string $userId   The caller
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotFoundException When the caller holds neither the secret nor a copy of it
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function status(string $secretId, string $userId): array {
		$secret = $this->loadHeld(secretId: $secretId, userId: $userId);
		$copyOf = $this->sourceOfCopy(copy: $secret);
		if ($copyOf === null) {
			return [
				'role' => 'owner',
				'privileged' => $this->isPrivileged(secretId: $secret->getId()),
				'requests' => $this->serialise(requests: $this->requests->findBySecret(secretId: $secret->getId())),
			];
		}

		return [
			'role' => 'recipient',
			'privileged' => $this->isPrivileged(secretId: $copyOf),
			'requests' => $this->serialise(requests: $this->requests->findBySecret(secretId: $copyOf, requesterId: $userId)),
		];
	}//end status()

	/**
	 * Mark or unmark the owner's login as privileged.
	 *
	 * @param string $secretId   The owner's secret
	 * @param string $ownerId    The caller, who must own it
	 * @param bool   $privileged Mark when true, unmark when false
	 *
	 * @return bool The state after the call
	 *
	 * @throws NotFoundException When the caller does not hold the secret
	 * @throws ForbiddenException When the secret is a received copy
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag is the state the owner asks for.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function setPrivileged(string $secretId, string $ownerId, bool $privileged): bool {
		$secret = $this->loadOwnSource(secretId: $secretId, ownerId: $ownerId);
		if ($privileged === $this->isPrivileged(secretId: $secret->getId())) {
			return $privileged;
		}

		if ($privileged === true) {
			$flag = new PrivilegedLogin();
			$flag->setId(Uuid::uuid4()->toString());
			$flag->setSecretId($secret->getId());
			$flag->setOwnerId($ownerId);
			$flag->setCreatedAt($this->now());
			$this->flags->insert($flag);
		} else {
			$this->flags->deleteBySecret(secretId: $secret->getId());
		}

		$eventType = AuditEventTypes::PRIVILEGED_ACCESS_UNMARKED;
		if ($privileged === true) {
			$eventType = AuditEventTypes::PRIVILEGED_ACCESS_MARKED;
		}

		$this->record(actorId: $ownerId, eventType: $eventType, secret: $secret, metadata: []);

		return $privileged;
	}//end setPrivileged()

	/**
	 * A recipient asks for a time window on their copy of a privileged login.
	 *
	 * @param string            $copyId      The recipient's copy
	 * @param string            $requesterId The caller, who must hold the copy
	 * @param DateTimeImmutable $startsAt    The start asked for
	 * @param DateTimeImmutable $endsAt      The end asked for
	 * @param string            $reason      Why the login is needed
	 *
	 * @return AccessRequest
	 *
	 * @throws NotFoundException When the caller holds no such copy
	 * @throws InvalidArgumentException When the login needs no approval, or the window or reason is not valid
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function request(
		string $copyId,
		string $requesterId,
		DateTimeImmutable $startsAt,
		DateTimeImmutable $endsAt,
		string $reason,
	): AccessRequest {
		$copy     = $this->loadHeld(secretId: $copyId, userId: $requesterId);
		$sourceId = $this->sourceOfCopy(copy: $copy);
		if ($sourceId === null) {
			throw new InvalidArgumentException(message: 'The owner of a login does not need to ask for access');
		}

		if ($this->isPrivileged(secretId: $sourceId) === false) {
			throw new InvalidArgumentException(message: 'This login does not need approval');
		}

		$window = new AccessWindow(startsAt: $startsAt, endsAt: $endsAt);
		if ($window->endsAt <= $this->nowImmutable()) {
			throw new InvalidArgumentException(message: 'The window must end in the future');
		}

		$reason = trim($reason);
		if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
			throw new InvalidArgumentException(message: 'A reason of at most 500 characters is required');
		}

		$source = $this->loadSecret(secretId: $sourceId);

		$request = new AccessRequest();
		$request->setId(Uuid::uuid4()->toString());
		$request->setSecretId($sourceId);
		$request->setOwnerId($source->getOwnerId());
		$request->setRequesterId($requesterId);
		$request->setReason($reason);
		$request->setStatus(AccessRequest::STATUS_PENDING);
		$request->setRequestedStart(self::mutable(moment: $window->startsAt));
		$request->setRequestedEnd(self::mutable(moment: $window->endsAt));
		$request->setCreatedAt($this->now());
		$request = $this->requests->insert($request);

		$this->record(
			actorId: $requesterId,
			eventType: AuditEventTypes::PRIVILEGED_ACCESS_REQUESTED,
			secret: $source,
			metadata: [
				'requestId' => $request->getId(),
				'startsAt' => $window->startsAt->format(DATE_ATOM),
				'endsAt' => $window->endsAt->format(DATE_ATOM),
			]
		);
		$this->notifications->notify(
			subject: 'privileged_access_requested',
			recipientId: $source->getOwnerId(),
			params: [
				'secret_id' => $sourceId,
				'secret_name' => $source->getName(),
				'requester' => $requesterId,
				'starts_at' => $window->startsAt->format(DATE_ATOM),
				'ends_at' => $window->endsAt->format(DATE_ATOM),
				'reason' => $reason,
			],
			objectType: 'secret',
			objectId: $sourceId,
		);

		return $request;
	}//end request()

	/**
	 * The owner approves a pending request, optionally narrowed.
	 *
	 * @param string                 $requestId The request
	 * @param string                 $ownerId   The caller, who must own the login
	 * @param DateTimeImmutable|null $startsAt  A later start, or null to keep the requested one
	 * @param DateTimeImmutable|null $endsAt    An earlier end, or null to keep the requested one
	 * @param list<string>           $ranges    CIDR ranges to limit use to; empty for any address
	 *
	 * @return AccessRequest
	 *
	 * @throws NotFoundException When the caller has no such request
	 * @throws InvalidArgumentException When it was already answered, or the approval widens it or a range does not parse
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function approve(
		string $requestId,
		string $ownerId,
		?DateTimeImmutable $startsAt = null,
		?DateTimeImmutable $endsAt = null,
		array $ranges = [],
	): AccessRequest {
		[$request, $source] = $this->loadPending(requestId: $requestId, ownerId: $ownerId);

		$window = AccessWindow::approve(
			requested: new AccessWindow(
				startsAt: DateTimeImmutable::createFromMutable($request->getRequestedStart()),
				endsAt: DateTimeImmutable::createFromMutable($request->getRequestedEnd())
			),
			startsAt: $startsAt,
			endsAt: $endsAt,
			ranges: array_values(array_map('strval', $ranges))
		);
		$cidrs = array_map(static fn (AddressRange $range): string => $range->toString(), $window->ranges);

		$request->setStatus(AccessRequest::STATUS_APPROVED);
		$request->setApprovedStart(self::mutable(moment: $window->startsAt));
		$request->setApprovedEnd(self::mutable(moment: $window->endsAt));
		$request->setRanges((string)json_encode($cidrs));
		$request->setDecidedAt($this->now());
		$request = $this->requests->update($request);

		$this->record(
			actorId: $ownerId,
			eventType: AuditEventTypes::PRIVILEGED_ACCESS_APPROVED,
			secret: $source,
			metadata: [
				'requestId' => $request->getId(),
				'requesterId' => $request->getRequesterId(),
				'startsAt' => $window->startsAt->format(DATE_ATOM),
				'endsAt' => $window->endsAt->format(DATE_ATOM),
				'ranges' => $cidrs,
			]
		);
		$this->notifyRequester(request: $request, source: $source, result: 'approved');

		return $request;
	}//end approve()

	/**
	 * The owner denies a pending request.
	 *
	 * @param string $requestId The request
	 * @param string $ownerId   The caller, who must own the login
	 *
	 * @return AccessRequest
	 *
	 * @throws NotFoundException When the caller has no such request
	 * @throws InvalidArgumentException When it was already answered
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function deny(string $requestId, string $ownerId): AccessRequest {
		[$request, $source] = $this->loadPending(requestId: $requestId, ownerId: $ownerId);

		$request->setStatus(AccessRequest::STATUS_DENIED);
		$request->setDecidedAt($this->now());
		$request = $this->requests->update($request);

		$this->record(
			actorId: $ownerId,
			eventType: AuditEventTypes::PRIVILEGED_ACCESS_DENIED,
			secret: $source,
			metadata: ['requestId' => $request->getId(), 'requesterId' => $request->getRequesterId()]
		);
		$this->notifyRequester(request: $request, source: $source, result: 'denied');

		return $request;
	}//end deny()

	/**
	 * The requests waiting for the owner's answer.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function pendingForOwner(string $ownerId): array {
		return $this->serialise(requests: $this->requests->findPendingForOwner(ownerId: $ownerId));
	}//end pendingForOwner()

	/**
	 * Drop the mark and every request of a secret that is deleted.
	 *
	 * @param string $secretId The deleted secret
	 *
	 * @return void
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function forget(string $secretId): void {
		$this->flags->deleteBySecret(secretId: $secretId);
		$this->requests->deleteBySecret(secretId: $secretId);
	}//end forget()

	/**
	 * Whether a source secret is marked privileged.
	 *
	 * @param string $secretId The source secret
	 *
	 * @return bool
	 */
	private function isPrivileged(string $secretId): bool {
		return $this->flags->privilegedAmong(secretIds: [$secretId]) !== [];
	}//end isPrivileged()

	/**
	 * A pending request of the caller's login, with that login.
	 *
	 * @param string $requestId The request
	 * @param string $ownerId   The caller
	 *
	 * @return array{0: AccessRequest, 1: Secret}
	 *
	 * @throws NotFoundException When the caller has no such request
	 * @throws InvalidArgumentException When it was already answered
	 */
	private function loadPending(string $requestId, string $ownerId): array {
		try {
			$request = $this->requests->findById(id: $requestId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Access request not found');
		}

		if ($request->getOwnerId() !== $ownerId) {
			throw new NotFoundException(message: 'Access request not found');
		}

		try {
			$source = $this->loadOwnSource(secretId: $request->getSecretId(), ownerId: $ownerId);
		} catch (ForbiddenException) {
			throw new NotFoundException(message: 'Access request not found');
		}

		if ($request->getStatus() !== AccessRequest::STATUS_PENDING) {
			throw new InvalidArgumentException(message: 'The request was already answered');
		}

		return [$request, $source];
	}//end loadPending()

	/**
	 * The owner's own secret, never a received copy.
	 *
	 * @param string $secretId The secret
	 * @param string $ownerId  The caller
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the caller does not hold it
	 * @throws ForbiddenException When it is a received copy
	 */
	private function loadOwnSource(string $secretId, string $ownerId): Secret {
		$secret = $this->loadHeld(secretId: $secretId, userId: $ownerId);
		if ($this->rows->forCopy(copy: $secret)->countsAsCopy() === true) {
			throw new ForbiddenException(message: 'Only the owner of a login can mark it or answer requests');
		}

		return $secret;
	}//end loadOwnSource()

	/**
	 * A secret the user holds in their own vault.
	 *
	 * @param string $secretId The secret
	 * @param string $userId   The caller
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When it is missing or another user's
	 */
	private function loadHeld(string $secretId, string $userId): Secret {
		$secret = $this->loadSecret(secretId: $secretId);
		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return $secret;
	}//end loadHeld()

	/**
	 * A secret by id.
	 *
	 * @param string $secretId The secret
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When it is missing
	 */
	private function loadSecret(string $secretId): Secret {
		try {
			return $this->secrets->findById($secretId);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}
	}//end loadSecret()

	/**
	 * The source of a trusted received copy, or null for anything else.
	 *
	 * @param Secret $copy The secret
	 *
	 * @return string|null
	 */
	private function sourceOfCopy(Secret $copy): ?string {
		return $this->rows->forCopy(copy: $copy)->trustedRow()?->getSourceSecretId();
	}//end sourceOfCopy()

	/**
	 * Tell the requester how the owner answered, linking to their own copy.
	 *
	 * @param AccessRequest $request The answered request
	 * @param Secret        $source  The owner's login
	 * @param string        $result  approved or denied
	 *
	 * @return void
	 */
	private function notifyRequester(AccessRequest $request, Secret $source, string $result): void {
		$copyId = $this->rows->forPair(sourceId: $source->getId(), targetUserId: $request->getRequesterId())
			->trustedRow()?->getSecretId();

		$this->notifications->notify(
			subject: 'privileged_access_result',
			recipientId: $request->getRequesterId(),
			params: [
				'secret_id' => (string)$copyId,
				'secret_name' => $source->getName(),
				'result' => $result,
				'starts_at' => $request->getApprovedStart()?->format(DATE_ATOM),
				'ends_at' => $request->getApprovedEnd()?->format(DATE_ATOM),
			],
			objectType: 'secret',
			objectId: $copyId ?? $source->getId(),
		);
	}//end notifyRequester()

	/**
	 * Requests in their API form.
	 *
	 * @param AccessRequest[] $requests The rows
	 *
	 * @return list<array<string,mixed>>
	 */
	private function serialise(array $requests): array {
		return array_values(array_map(static fn (AccessRequest $request): array => $request->jsonSerialize(), $requests));
	}//end serialise()

	/**
	 * Audit one step. A failing audit write is logged, never swallowed silently.
	 *
	 * @param string              $actorId   Who acted
	 * @param string              $eventType The event
	 * @param Secret              $secret    The owner's login
	 * @param array<string,mixed> $metadata  The request, window and ranges
	 *
	 * @return void
	 */
	private function record(string $actorId, string $eventType, Secret $secret, array $metadata): void {
		try {
			$this->audit->record(
				AuditEvent::forUser(
					actorId: $actorId,
					eventType: $eventType,
					objectType: 'secret',
					objectId: $secret->getId(),
					objectName: $secret->getName(),
					metadata: $metadata
				)
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: privileged access step could not be audited: ' . $exception->getMessage(),
				['app' => 'keepiq', 'eventType' => $eventType]
			);
		}
	}//end record()

	/**
	 * The current moment, as the entities store it.
	 *
	 * @return DateTime
	 */
	private function now(): DateTime {
		return self::mutable(moment: $this->nowImmutable());
	}//end now()

	/**
	 * The current moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function nowImmutable(): DateTimeImmutable {
		return (new DateTimeImmutable())->setTimestamp($this->time->getTime());
	}//end nowImmutable()

	/**
	 * A mutable copy of a moment.
	 *
	 * @param DateTimeImmutable $moment The moment
	 *
	 * @return DateTime
	 */
	private static function mutable(DateTimeImmutable $moment): DateTime {
		return DateTime::createFromImmutable($moment);
	}//end mutable()
}//end class
