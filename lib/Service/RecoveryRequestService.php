<?php

/**
 * Keepiq RecoveryRequestService
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
use OCA\Keepiq\Db\RecoveryApproval;
use OCA\Keepiq\Db\RecoveryApprovalMapper;
use OCA\Keepiq\Db\RecoveryEnrolmentMapper;
use OCA\Keepiq\Db\RecoveryRequest;
use OCA\Keepiq\Db\RecoveryRequestMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Recovery requests and officer approvals (crypto-organisation-account-
 * recovery D3, D4, D6). The server counts distinct proven approvals and
 * releases each piece of handoff material only to whoever it is for. A
 * caller something is not for gets the answer an unknown request gets.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The request lifecycle across
 *   requests, approvals, enrolments, keys, notifications and the audit trail.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One public method per step of
 *   the request lifecycle.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Every step re-checks who may
 *   act on which state; keeping those checks next to each other is the point.
 */
class RecoveryRequestService {

	public const STATUS_PENDING = 'pending';

	public const STATUS_APPROVED = 'approved';

	public const STATUS_FULFILLED = 'fulfilled';

	public const STATUS_DECLINED = 'declined';

	public const STATUS_EXPIRED = 'expired';

	/**
	 * Requests still in play.
	 *
	 * @var string[]
	 */
	public const OPEN = [self::STATUS_PENDING, self::STATUS_APPROVED];

	/**
	 * Why a request was filed: a forgotten master password, or a new device
	 * to unlock once (crypto-new-device-approval D6).
	 *
	 * @var string[]
	 */
	public const PURPOSES = ['password', 'device'];

	/**
	 * How long a request stays open (D3): 72 hours.
	 *
	 * @var string
	 */
	public const TTL = 'PT72H';

	/**
	 * Constructor for RecoveryRequestService.
	 *
	 * @param RecoveryRequestMapper   $requests      The request mapper
	 * @param RecoveryApprovalMapper  $approvals     The approval mapper
	 * @param RecoveryEnrolmentMapper $enrolments    The enrolment mapper
	 * @param RecoveryEnrolmentService $enrolment    The enrolment service
	 * @param RecoveryKeyService      $keys          The recovery keys
	 * @param RecoveryPolicyService   $policy        The officers and threshold
	 * @param NotificationService     $notifications The notification dispatcher
	 * @param RecoveryAudit           $audit         The audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI list.
	 */
	public function __construct(
		private RecoveryRequestMapper $requests,
		private RecoveryApprovalMapper $approvals,
		private RecoveryEnrolmentMapper $enrolments,
		private RecoveryEnrolmentService $enrolment,
		private RecoveryKeyService $keys,
		private RecoveryPolicyService $policy,
		private NotificationService $notifications,
		private RecoveryAudit $audit,
	) {
	}//end __construct()

	/**
	 * File a request from the lock screen with the browser's one-time key.
	 * Any earlier open request of the user ends.
	 *
	 * @param string $userId    The user who forgot their master password
	 * @param string $publicKey The one-time X25519 public key (base64, raw 32 bytes)
	 * @param string $purpose   `password` or `device`
	 *
	 * @return RecoveryRequest
	 *
	 * @throws ForbiddenException       When the user is not enrolled or recovery is off
	 * @throws InvalidArgumentException When the key is malformed
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	public function create(string $userId, string $publicKey, string $purpose = 'password'): RecoveryRequest {
		if (in_array($purpose, self::PURPOSES, true) === false) {
			throw new InvalidArgumentException(message: 'purpose must be password or device');
		}

		$raw = base64_decode($publicKey, true);
		if ($raw === false || strlen($raw) !== 32) {
			throw new InvalidArgumentException(message: 'publicKey must be a raw X25519 public key');
		}

		$enrolment = $this->enrolment->current(userId: $userId);
		if ($enrolment === null || $this->policy->policy() === 'off') {
			throw new ForbiddenException(message: 'You are not enrolled in account recovery');
		}

		$this->endOpenRequestsOf(userId: $userId);

		$now     = new DateTime();
		$request = new RecoveryRequest();
		$request->setId(Uuid::uuid4()->toString());
		$request->setUserId($userId);
		$request->setSuiteId($enrolment->getSuiteId());
		$request->setEnrolmentId($enrolment->getId());
		$request->setRequestPublicKey($publicKey);
		$request->setStatus(self::STATUS_PENDING);
		$request->setPurpose($purpose);
		$request->setCreatedAt($now);
		$request->setExpiresAt((clone $now)->add(new DateInterval(self::TTL)));
		$request = $this->requests->insert($request);

		$this->notifyOfficers(request: $request);

		$this->audit->record(
			actorId: $userId,
			eventType: RecoveryAudit::REQUESTED,
			objectId: $request->getId(),
			metadata: ['userId' => $userId]
		);

		return $request;
	}//end create()

	/**
	 * The open requests an officer may act on, with the approval count.
	 * Never the officer's own request, never key material.
	 *
	 * @param string $officerUid The officer
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws ForbiddenException When the caller is not an officer
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function forOfficer(string $officerUid): array {
		$this->assertOfficer(officerUid: $officerUid);
		$rows = [];
		foreach ($this->requests->findByStatuses(self::OPEN) as $request) {
			if ($request->getUserId() === $officerUid || $this->isLapsed(request: $request) === true) {
				continue;
			}

			$decisions = $this->approvals->findByRequest($request->getId());
			$rows[]    = [
				'id' => $request->getId(),
				'userId' => $request->getUserId(),
				'status' => $request->getStatus(),
				'purpose' => $request->getPurpose(),
				'requestPublicKey' => $request->getRequestPublicKey(),
				'createdAt' => $request->getCreatedAt()?->format('c'),
				'expiresAt' => $request->getExpiresAt()?->format('c'),
				'approvals' => count($this->approverIds(decisions: $decisions)),
				'threshold' => $this->policy->threshold(),
				'approvedByMe' => in_array($officerUid, $this->approverIds(decisions: $decisions), true),
				'handedOff' => ($request->getHandledBy() !== null),
			];
		}

		return $rows;
	}//end forOfficer()

	/**
	 * Count one officer's approval; move the request to approved at the
	 * threshold. The vault-key proof is checked before this runs.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return string The request status afterwards
	 *
	 * @throws ForbiddenException When the caller is not an officer or approves their own request
	 * @throws NotFoundException  When the request is not open
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function approve(string $id, string $officerUid): string {
		$this->assertOfficer(officerUid: $officerUid);
		$request = $this->loadOpen(id: $id);
		if ($request->getUserId() === $officerUid) {
			throw new ForbiddenException(message: 'You cannot approve the recovery of your own account');
		}

		$decisions = $this->approvals->findByRequest($id);
		if (in_array($officerUid, $this->approverIds(decisions: $decisions), true) === false) {
			$decision = new RecoveryApproval();
			$decision->setId(Uuid::uuid4()->toString());
			$decision->setRequestId($id);
			$decision->setOfficerUid($officerUid);
			$decision->setDecision('approve');
			$decision->setDecidedAt(new DateTime());
			$decisions[] = $this->approvals->insert($decision);
		}

		$count = count($this->approverIds(decisions: $decisions));
		if ($request->getStatus() === self::STATUS_PENDING && $count >= $this->policy->threshold()) {
			$request->setStatus(self::STATUS_APPROVED);
			$this->requests->update($request);
		}

		$this->audit->record(
			actorId: $officerUid,
			eventType: RecoveryAudit::APPROVED,
			objectId: $id,
			metadata: ['userId' => $request->getUserId(), 'approvals' => $count, 'threshold' => $this->policy->threshold()]
		);

		return $request->getStatus();
	}//end approve()

	/**
	 * Decline: any officer may end a request.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When the caller is not an officer
	 * @throws NotFoundException  When the request is not open
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-recovery-needs-a-threshold-of-proven-officer-approvals
	 */
	public function decline(string $id, string $officerUid): void {
		$this->assertOfficer(officerUid: $officerUid);
		$request = $this->loadOpen(id: $id);
		$request->setStatus(self::STATUS_DECLINED);
		$request->setSealedResult(null);
		$this->requests->update($request);
		$this->notifications->notify(subject: 'recovery_declined', recipientId: $request->getUserId());
		$this->audit->record(
			actorId: $officerUid,
			eventType: RecoveryAudit::DECLINED,
			objectId: $id,
			metadata: ['userId' => $request->getUserId()]
		);
	}//end decline()

	/**
	 * The handoff material, only for an officer who approved an approved
	 * request: the user's enrolment envelope and the officer's own copy of
	 * the recovery key it is wrapped to.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return array{requestPublicKey:string,envelope:string,wrappedRecoveryKey:string,recoveryKeyId:string}
	 *
	 * @throws NotFoundException For every caller and state it is not for
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function handoff(string $id, string $officerUid): array {
		$request = $this->loadForApprover(id: $id, officerUid: $officerUid);
		try {
			$enrolment = $this->enrolments->findById($request->getEnrolmentId());
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Request not found');
		}

		$copy = $this->keys->ownCopy(officerUid: $officerUid, recoveryKeyId: $enrolment->getRecoveryKeyId());

		return [
			'requestPublicKey' => $request->getRequestPublicKey(),
			'envelope' => $enrolment->getEnvelope(),
			'wrappedRecoveryKey' => $copy->getWrappedPrivateKey(),
			'recoveryKeyId' => $enrolment->getRecoveryKeyId(),
		];
	}//end handoff()

	/**
	 * Store the private key an approving officer sealed to the request key.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 * @param string $sealed     The HPKE-sealed private key
	 *
	 * @return void
	 *
	 * @throws NotFoundException        For every caller and state it is not for
	 * @throws InvalidArgumentException When the sealed result is empty
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function postSealed(string $id, string $officerUid, string $sealed): void {
		if (trim($sealed) === '') {
			throw new InvalidArgumentException(message: 'sealedResult is required');
		}

		$request = $this->loadForApprover(id: $id, officerUid: $officerUid);
		$request->setSealedResult($sealed);
		$request->setHandledBy($officerUid);
		$this->requests->update($request);
		$this->notifications->notify(subject: 'recovery_ready', recipientId: $request->getUserId());
		$this->audit->record(
			actorId: $officerUid,
			eventType: RecoveryAudit::HANDED_OFF,
			objectId: $id,
			metadata: ['userId' => $request->getUserId()]
		);
	}//end postSealed()

	/**
	 * The user's own latest request, with the sealed result once it exists.
	 *
	 * @param string $userId The user
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function forUser(string $userId): ?array {
		$request = ($this->requests->findByUser($userId)[0] ?? null);
		if ($request === null) {
			return null;
		}

		$status = $request->getStatus();
		if (in_array($status, self::OPEN, true) === true && $this->isLapsed(request: $request) === true) {
			$status = self::STATUS_EXPIRED;
		}

		$view = [
			'id' => $request->getId(),
			'status' => $status,
			'purpose' => $request->getPurpose(),
			'createdAt' => $request->getCreatedAt()?->format('c'),
			'expiresAt' => $request->getExpiresAt()?->format('c'),
			'suiteId' => $request->getSuiteId(),
			'handledBy' => $request->getHandledBy(),
		];
		if ($status === self::STATUS_APPROVED && $request->getSealedResult() !== null) {
			$view['sealedResult'] = $request->getSealedResult();
		}

		return $view;
	}//end forUser()

	/**
	 * Mark the user's request fulfilled after their browser re-wrapped the
	 * suite under a new master password, and delete the sealed result.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return string The officer who handled it
	 *
	 * @throws NotFoundException For every caller and state it is not for
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovered-key-reaches-only-the-requesting-browser
	 */
	public function complete(string $id, string $userId): string {
		$request = $this->load(id: $id);
		if ($request->getUserId() !== $userId
			|| $request->getStatus() !== self::STATUS_APPROVED
			|| $request->getSealedResult() === null
		) {
			throw new NotFoundException(message: 'Request not found');
		}

		$request->setStatus(self::STATUS_FULFILLED);
		$request->setSealedResult(null);
		$request->setFulfilledAt(new DateTime());
		$this->requests->update($request);
		$handledBy = (string)$request->getHandledBy();
		$this->audit->record(
			actorId: $userId,
			eventType: RecoveryAudit::COMPLETED,
			objectId: $id,
			metadata: ['handledBy' => $handledBy]
		);

		return $handledBy;
	}//end complete()

	/**
	 * Expire open requests past their 72 hours and drop any sealed result.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number expired
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-a-recovery-request-carries-a-one-time-key-and-a-verification-phrase
	 */
	public function expireLapsed(DateTime $now): int {
		$count = 0;
		foreach ($this->requests->findLapsed(self::OPEN, $now) as $request) {
			$request->setStatus(self::STATUS_EXPIRED);
			$request->setSealedResult(null);
			$this->requests->update($request);
			$this->audit->record(
				actorId: $request->getUserId(),
				eventType: RecoveryAudit::EXPIRED,
				objectId: $request->getId(),
				metadata: ['userId' => $request->getUserId()]
			);
			++$count;
		}

		return $count;
	}//end expireLapsed()

	/**
	 * End the open requests of a revoked suite (D7).
	 *
	 * @param string $suiteId The suite
	 *
	 * @return int The number ended
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function endForSuite(string $suiteId): int {
		$count = 0;
		foreach ($this->requests->findBySuite($suiteId) as $request) {
			if (in_array($request->getStatus(), self::OPEN, true) === false) {
				continue;
			}

			$request->setStatus(self::STATUS_DECLINED);
			$request->setSealedResult(null);
			$this->requests->update($request);
			++$count;
		}

		return $count;
	}//end endForSuite()

	/**
	 * End the user's earlier open requests: one request at a time.
	 *
	 * @param string $userId The user
	 *
	 * @return void
	 */
	private function endOpenRequestsOf(string $userId): void {
		foreach ($this->requests->findByUser($userId) as $old) {
			if (in_array($old->getStatus(), self::OPEN, true) === true) {
				$old->setStatus(self::STATUS_EXPIRED);
				$old->setSealedResult(null);
				$this->requests->update($old);
			}
		}
	}//end endOpenRequestsOf()

	/**
	 * Tell every officer but the requester about a new request.
	 *
	 * @param RecoveryRequest $request The request
	 *
	 * @return void
	 */
	private function notifyOfficers(RecoveryRequest $request): void {
		foreach ($this->policy->officers() as $officer) {
			if ($officer === $request->getUserId()) {
				continue;
			}

			$this->notifications->notify(
				subject: 'recovery_requested',
				recipientId: $officer,
				params: ['user' => $request->getUserId()],
				objectType: 'account_recovery',
				objectId: $request->getId(),
			);
		}
	}//end notifyOfficers()

	/**
	 * An approved request the officer approved, or the unknown-request answer.
	 *
	 * @param string $id         The request
	 * @param string $officerUid The officer
	 *
	 * @return RecoveryRequest
	 *
	 * @throws NotFoundException
	 */
	private function loadForApprover(string $id, string $officerUid): RecoveryRequest {
		$request = $this->load(id: $id);
		$allowed = $this->policy->isOfficer(userId: $officerUid)
			&& $request->getStatus() === self::STATUS_APPROVED
			&& $this->isLapsed(request: $request) === false
			&& in_array($officerUid, $this->approverIds(decisions: $this->approvals->findByRequest($id)), true);
		if ($allowed === false) {
			throw new NotFoundException(message: 'Request not found');
		}

		return $request;
	}//end loadForApprover()

	/**
	 * A pending, unexpired request, or the unknown-request answer.
	 *
	 * @param string $id The request
	 *
	 * @return RecoveryRequest
	 *
	 * @throws NotFoundException
	 */
	private function loadOpen(string $id): RecoveryRequest {
		$request = $this->load(id: $id);
		if ($request->getStatus() !== self::STATUS_PENDING || $this->isLapsed(request: $request) === true) {
			throw new NotFoundException(message: 'Request not found');
		}

		return $request;
	}//end loadOpen()

	/**
	 * A request by id, or the unknown-request answer.
	 *
	 * @param string $id The request
	 *
	 * @return RecoveryRequest
	 *
	 * @throws NotFoundException
	 */
	private function load(string $id): RecoveryRequest {
		try {
			return $this->requests->findById($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Request not found');
		}
	}//end load()

	/**
	 * The distinct officers who approved.
	 *
	 * @param array<int,RecoveryApproval> $decisions The decisions
	 *
	 * @return string[]
	 */
	private function approverIds(array $decisions): array {
		$ids = [];
		foreach ($decisions as $decision) {
			if ($decision->getDecision() === 'approve' && in_array($decision->getOfficerUid(), $ids, true) === false) {
				$ids[] = $decision->getOfficerUid();
			}
		}

		return $ids;
	}//end approverIds()

	/**
	 * Refuse a caller who is not a named officer.
	 *
	 * @param string $officerUid The caller
	 *
	 * @return void
	 *
	 * @throws ForbiddenException
	 */
	private function assertOfficer(string $officerUid): void {
		if ($this->policy->isOfficer(userId: $officerUid) === false) {
			throw new ForbiddenException(message: 'Only a recovery officer can do this');
		}
	}//end assertOfficer()

	/**
	 * Whether a request is past its expiry.
	 *
	 * @param RecoveryRequest $request The request
	 *
	 * @return bool
	 */
	private function isLapsed(RecoveryRequest $request): bool {
		$expires = $request->getExpiresAt();
		return $expires === null || $expires <= new DateTime();
	}//end isLapsed()
}//end class
