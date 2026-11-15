<?php

/**
 * Keepiq Emergency Grant
 *
 * One designation once both suites are known.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * A designation with the grantee suite its envelope is sealed to, the
 * grantor suite whose rotation invalidates it, and the moment it is granted;
 * built by EmergencyAccessService for one designation.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
 */
final class EmergencyGrant {
	/**
	 * Constructor for EmergencyGrant.
	 *
	 * @param EmergencyDesignation $designation The validated designation
	 * @param string $granteeSuiteId The grantee suite the envelope is sealed to
	 * @param string $grantorSuiteId The grantor's active suite
	 * @param DateTime $now The moment of the grant
	 *
	 * @return void
	 */
	public function __construct(
		public readonly EmergencyDesignation $designation,
		public readonly string $granteeSuiteId,
		public readonly string $grantorSuiteId,
		public readonly DateTime $now,
	) {
	}//end __construct()

	/**
	 * A new contact row for the grantor and grantee, created now.
	 *
	 * @return EmergencyContact
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	private function newContact(): EmergencyContact {
		$contact = new EmergencyContact();
		$contact->setId(Uuid::uuid4()->toString());
		$contact->setGrantorUserId($this->designation->grantorUserId);
		$contact->setGranteeUserId($this->designation->granteeUserId);
		$contact->setCreatedAt($this->now);

		return $contact;
	}//end newContact()

	/**
	 * Write the grant onto a contact: view access, the wait period, granted
	 * state, the envelope and both suites, with any pending request and
	 * earlier invalidation cleared.
	 *
	 * @param EmergencyContact $contact The new or existing contact
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
	 */
	private function applyTo(EmergencyContact $contact): void {
		$contact->setAccessLevel(EmergencyContact::ACCESS_VIEW);
		$contact->setWaitPeriodDays($this->designation->waitPeriodDays);
		$contact->setState(EmergencyContact::STATE_GRANTED);
		$contact->setRequestedAt(null);
		$contact->setRecoveryEnvelope($this->designation->recoveryEnvelope);
		$contact->setGrantorSuiteId($this->grantorSuiteId);
		$contact->setGranteeSuiteId($this->granteeSuiteId);
		$contact->setInvalidatedReason(null);
		$contact->setUpdatedAt($this->now);
	}//end applyTo()

	/**
	 * Store the grant on the grantor's existing contact for this grantee, or
	 * on a new one.
	 *
	 * @param EmergencyContactMapper $mapper The contact store
	 *
	 * @return EmergencyContact The stored contact
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function save(EmergencyContactMapper $mapper): EmergencyContact {
		try {
			$contact = $mapper->findByGrantorAndGrantee(
				grantorUserId: $this->designation->grantorUserId,
				granteeUserId: $this->designation->granteeUserId
			);
		} catch (DoesNotExistException) {
			$contact = $this->newContact();
			$this->applyTo(contact: $contact);

			return $mapper->insert($contact);
		}

		$this->applyTo(contact: $contact);

		return $mapper->update($contact);
	}//end save()
}//end class
