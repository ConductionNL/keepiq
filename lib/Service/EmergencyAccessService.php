<?php

/**
 * Doriath Emergency Access Service
 *
 * The break-glass lifecycle: designate → request → (decline | approve-by-timeout)
 * → grantee view access, plus revoke and key-change invalidation
 * (add-emergency-access). The vault stays zero-knowledge (ADR-003): the server
 * stores ONLY the grantee-encrypted recovery envelope (built client-side, design
 * D1/D2) and never a usable key. The real controls are the grantor-configured
 * wait period, the grantor veto, and the fact that only the grantee's own
 * private key can open the envelope; the server's release-gate (approved +
 * caller-is-grantee) is defence-in-depth (design D4). Every transition is
 * audited with a non-sensitive actor/object reference — the envelope and any key
 * material NEVER enter an audit entry (design D8).
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EmergencyContact;
use OCA\Doriath\Db\EmergencyContactMapper;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Lifecycle service for break-glass emergency access.
 *
 * The audit entries of every transition are written by
 * EmergencyAccessAuditTrail (design D8), and the key-change invalidation
 * paths live in EmergencyEnvelopeInvalidationService.
 */
class EmergencyAccessService {
	/**
	 * The object type used for the emergency-access relation.
	 *
	 * @var string
	 */
	private const OBJECT_TYPE = EmergencyAccessAuditTrail::OBJECT_TYPE;

	/**
	 * The grantor-selectable wait periods, in days.
	 *
	 * @var int[]
	 */
	public const ALLOWED_WAIT_PERIODS = [1, 3, 7, 30];

	/**
	 * Constructor for EmergencyAccessService.
	 *
	 * @param EmergencyContactMapper $mapper The emergency-contact mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param EmergencyAccessAuditTrail $auditTrail The emergency-access audit trail
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private EmergencyContactMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private NotificationService $notificationService,
		private EmergencyAccessAuditTrail $auditTrail,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * List the emergency contacts a grantor has designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function listForGrantor(string $grantorUserId): array {
		return $this->mapper->findByGrantor(grantorUserId: $grantorUserId);
	}//end listForGrantor()

	/**
	 * List the relationships where the user is the grantee (incoming access).
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return EmergencyContact[]
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	public function listForGrantee(string $granteeUserId): array {
		return $this->mapper->findByGrantee(granteeUserId: $granteeUserId);
	}//end listForGrantee()

	/**
	 * Return the grantee's active-suite certificate so the grantor's browser
	 * can build the recovery envelope. Fails loudly when the grantee has no
	 * active suite (the envelope cannot be built — design D7).
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @return array{suiteId: string, certificate: string}
	 *
	 * @throws InvalidArgumentException When the grantee has no active suite
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function getGranteeCertificate(string $granteeUserId): array {
		try {
			$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $granteeUserId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('The grantee has no active encryption suite');
		}

		$certificate = (string)$suite->getCertificate();
		if ($certificate === '') {
			throw new InvalidArgumentException('The grantee has no active encryption suite');
		}

		return ['suiteId' => $suite->getId(), 'certificate' => $certificate];
	}//end getGranteeCertificate()

	/**
	 * Designate (or re-establish) a grantee as an emergency contact.
	 *
	 * The recovery envelope is built entirely in the grantor's browser and
	 * supplied here as opaque ciphertext — the server stores it verbatim and
	 * never sees a usable key (design D1/D2).
	 *
	 * @param string $grantorUserId The grantor (vault owner) Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param int $waitPeriodDays The wait period (1|3|7|30)
	 * @param string $accessLevel The access level (v1: only 'view')
	 * @param string $recoveryEnvelope The grantee-encrypted recovery envelope
	 *
	 * @return EmergencyContact
	 *
	 * @throws InvalidArgumentException When validation fails (bad level, wait
	 *                                  period, self-designation, or the grantee has no active suite)
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
	 */
	public function designate(
		string $grantorUserId,
		string $granteeUserId,
		int $waitPeriodDays,
		string $accessLevel,
		string $recoveryEnvelope,
	): EmergencyContact {
		if ($accessLevel !== EmergencyContact::ACCESS_VIEW) {
			throw new InvalidArgumentException('v1 emergency access supports the "view" access level only');
		}

		if (in_array($waitPeriodDays, self::ALLOWED_WAIT_PERIODS, true) === false) {
			throw new InvalidArgumentException('Unsupported wait period');
		}

		if ($granteeUserId === '' || $granteeUserId === $grantorUserId) {
			throw new InvalidArgumentException('Cannot designate yourself as an emergency contact');
		}

		if (trim($recoveryEnvelope) === '') {
			throw new InvalidArgumentException('A recovery envelope is required');
		}

		// The grantee MUST have an active suite (the envelope is encrypted to it).
		$granteeCert = $this->getGranteeCertificate(granteeUserId: $granteeUserId);
		$granteeSuiteId = $granteeCert['suiteId'];

		// The grantor MUST be unlocked/have an active suite — record it so a
		// rotation/revocation of the grantor's suite invalidates this envelope.
		try {
			$grantorSuite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $grantorUserId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('You have no active encryption suite');
		}

		$now = new DateTime();

		$isNew = false;
		try {
			$contact = $this->mapper->findByGrantorAndGrantee(grantorUserId: $grantorUserId, granteeUserId: $granteeUserId);
		} catch (DoesNotExistException) {
			$isNew = true;
			$contact = new EmergencyContact();
			$contact->setId(Uuid::uuid4()->toString());
			$contact->setGrantorUserId($grantorUserId);
			$contact->setGranteeUserId($granteeUserId);
			$contact->setCreatedAt($now);
		}

		$contact->setAccessLevel(EmergencyContact::ACCESS_VIEW);
		$contact->setWaitPeriodDays($waitPeriodDays);
		$contact->setState(EmergencyContact::STATE_GRANTED);
		$contact->setRequestedAt(null);
		$contact->setRecoveryEnvelope($recoveryEnvelope);
		$contact->setGrantorSuiteId($grantorSuite->getId());
		$contact->setGranteeSuiteId($granteeSuiteId);
		$contact->setInvalidatedReason(null);
		$contact->setUpdatedAt($now);

		$contact = match ($isNew) {
			true => $this->mapper->insert($contact),
			false => $this->mapper->update($contact),
		};

		$this->logger->debug('Doriath: emergency contact designated for grantee ' . $granteeUserId);

		$this->auditTrail->recordGranted(
			grantorUserId: $grantorUserId,
			granteeUserId: $granteeUserId,
			id: $contact->getId(),
			accessLevel: EmergencyContact::ACCESS_VIEW,
			waitPeriodDays: $waitPeriodDays,
		);

		return $contact;
	}//end designate()

	/**
	 * Revoke an emergency contact: delete the recovery envelope and cancel any
	 * pending request. A revoked contact cannot break glass until re-designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantor
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
	 */
	public function revoke(string $grantorUserId, string $id): void {
		$contact = $this->loadOwnedByGrantor(id: $id, grantorUserId: $grantorUserId);
		$granteeUserId = $contact->getGranteeUserId();

		$this->mapper->delete($contact);

		$this->auditTrail->recordRevoked(
			grantorUserId: $grantorUserId,
			granteeUserId: $granteeUserId,
			id: $id,
		);
	}//end revoke()

	/**
	 * Initiate a break-glass request as the grantee. Starts the grantor's wait
	 * timer and notifies the grantor; releases no key material.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantee or the state
	 *                            does not allow a request
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	public function request(string $granteeUserId, string $id): EmergencyContact {
		$contact = $this->loadOwnedByGrantee(id: $id, granteeUserId: $granteeUserId);

		if ($contact->getState() !== EmergencyContact::STATE_GRANTED) {
			throw new ForbiddenException(message: 'Emergency access cannot be requested in its current state');
		}

		$now = new DateTime();
		$contact->setState(EmergencyContact::STATE_REQUESTED);
		$contact->setRequestedAt($now);
		$contact->setUpdatedAt($now);
		$contact = $this->mapper->update($contact);

		$grantorUserId = $contact->getGrantorUserId();
		$this->notificationService->notify(
			subject: 'emergency_access_requested',
			recipientId: $grantorUserId,
			params: [
				'granteeUserId' => $granteeUserId,
				'grantee_name' => $granteeUserId,
				'waitPeriodDays' => $contact->getWaitPeriodDays(),
			],
			objectType: self::OBJECT_TYPE,
			objectId: $id,
		);

		$this->auditTrail->recordRequested(
			grantorUserId: $grantorUserId,
			granteeUserId: $granteeUserId,
			id: $id,
			waitPeriodDays: $contact->getWaitPeriodDays(),
		);

		return $contact;
	}//end request()

	/**
	 * Decline (veto) a pending break-glass request as the grantor. Releases
	 * nothing and returns the relationship to the `granted` state so the contact
	 * remains designated.
	 *
	 * @param string $grantorUserId The grantor Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantor
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
	 */
	public function decline(string $grantorUserId, string $id): EmergencyContact {
		$contact = $this->loadOwnedByGrantor(id: $id, grantorUserId: $grantorUserId);

		if ($contact->getState() !== EmergencyContact::STATE_REQUESTED) {
			throw new ForbiddenException(message: 'There is no pending request to decline');
		}

		$now = new DateTime();
		// Leave the requested state — return to granted, releasing nothing.
		$contact->setState(EmergencyContact::STATE_GRANTED);
		$contact->setRequestedAt(null);
		$contact->setUpdatedAt($now);
		$contact = $this->mapper->update($contact);

		$this->auditTrail->recordDeclined(
			grantorUserId: $grantorUserId,
			granteeUserId: $contact->getGranteeUserId(),
			id: $id,
		);

		return $contact;
	}//end decline()

	/**
	 * Fetch the recovery envelope as the grantee. Released ONLY when the request
	 * is `approved` and the caller is the named grantee — every other state or
	 * caller is refused identically (no oracle, design D4). Notifies the grantor
	 * on actual access.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param string $id The relationship ID
	 *
	 * @return string The grantee-encrypted recovery envelope ciphertext
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When not approved or the caller is not the grantee
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function fetchEnvelope(string $granteeUserId, string $id): string {
		try {
			$contact = $this->mapper->findById(id: $id);
		} catch (DoesNotExistException) {
			// Same refusal shape as a wrong-state/wrong-caller attempt.
			throw new ForbiddenException(message: 'Emergency access is not available');
		}

		// Lazy promotion so a fetch right after the deadline works even if the
		// background job has not yet run (design D5).
		$contact = $this->promoteIfElapsed(contact: $contact);

		$isGrantee = ($contact->getGranteeUserId() === $granteeUserId);
		$isApproved = ($contact->getState() === EmergencyContact::STATE_APPROVED);
		$envelope = (string)$contact->getRecoveryEnvelope();

		// One identical refusal for wrong-state, wrong-caller, or missing
		// envelope — never reveal which condition failed.
		if ($isGrantee === false || $isApproved === false || $envelope === '') {
			throw new ForbiddenException(message: 'Emergency access is not available');
		}

		$grantorUserId = $contact->getGrantorUserId();
		$this->notificationService->notify(
			subject: 'emergency_access_accessed',
			recipientId: $grantorUserId,
			params: ['granteeUserId' => $granteeUserId, 'grantee_name' => $granteeUserId],
			objectType: self::OBJECT_TYPE,
			objectId: $id,
		);

		$this->auditTrail->recordAccessed(
			grantorUserId: $grantorUserId,
			granteeUserId: $granteeUserId,
			id: $id,
		);

		return $envelope;
	}//end fetchEnvelope()

	/**
	 * Promote a single `requested` relationship to `approved` when its wait
	 * period has elapsed with no decline. Records `emergency_access.approved`
	 * with the system as actor. Idempotent for non-requested rows.
	 *
	 * @param EmergencyContact $contact The relationship to evaluate
	 *
	 * @return EmergencyContact The (possibly promoted) relationship
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function promoteIfElapsed(EmergencyContact $contact): EmergencyContact {
		if ($contact->getState() !== EmergencyContact::STATE_REQUESTED) {
			return $contact;
		}

		$requestedAt = $contact->getRequestedAt();
		if ($requestedAt === null) {
			return $contact;
		}

		$deadline = (clone $requestedAt)->modify('+' . $contact->getWaitPeriodDays() . ' days');
		if (new DateTime() < $deadline) {
			return $contact;
		}

		$contact->setState(EmergencyContact::STATE_APPROVED);
		$contact->setUpdatedAt(new DateTime());
		$contact = $this->mapper->update($contact);

		$this->auditTrail->recordApproved(
			grantorUserId: $contact->getGrantorUserId(),
			granteeUserId: $contact->getGranteeUserId(),
			id: $contact->getId(),
		);

		return $contact;
	}//end promoteIfElapsed()

	/**
	 * Approve every elapsed pending request (the background-job entry point).
	 *
	 * @return int The number of relationships promoted to `approved`
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	public function approveElapsed(): int {
		$promoted = 0;
		foreach ($this->mapper->findAllRequested() as $contact) {
			$before = $contact->getState();
			$after = $this->promoteIfElapsed(contact: $contact)->getState();
			if ($before !== $after) {
				$promoted++;
			}
		}

		return $promoted;
	}//end approveElapsed()

	/**
	 * Load a relationship and assert the caller is its grantor.
	 *
	 * @param string $id The relationship ID
	 * @param string $grantorUserId The expected grantor
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantor
	 */
	private function loadOwnedByGrantor(string $id, string $grantorUserId): EmergencyContact {
		try {
			$contact = $this->mapper->findById(id: $id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Emergency contact not found');
		}

		if ($contact->getGrantorUserId() !== $grantorUserId) {
			throw new ForbiddenException(message: 'You do not own this emergency contact');
		}

		return $contact;
	}//end loadOwnedByGrantor()

	/**
	 * Load a relationship and assert the caller is its grantee.
	 *
	 * @param string $id The relationship ID
	 * @param string $granteeUserId The expected grantee
	 *
	 * @return EmergencyContact
	 *
	 * @throws NotFoundException When the relationship does not exist
	 * @throws ForbiddenException When the caller is not the grantee
	 */
	private function loadOwnedByGrantee(string $id, string $granteeUserId): EmergencyContact {
		try {
			$contact = $this->mapper->findById(id: $id);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Emergency contact not found');
		}

		if ($contact->getGranteeUserId() !== $granteeUserId) {
			throw new ForbiddenException(message: 'You are not the grantee of this emergency contact');
		}

		return $contact;
	}//end loadOwnedByGrantee()
}//end class
