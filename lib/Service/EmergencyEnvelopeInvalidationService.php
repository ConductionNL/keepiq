<?php

/**
 * Doriath Emergency Envelope Invalidation Service
 *
 * Envelope invalidation on key change (add-emergency-access): when a
 * grantor's or grantee's encryption suite is rotated or revoked, the
 * grantee-encrypted recovery envelopes that escrow the now-stale key must
 * stop being usable. Rotation MARKS the relationships invalid (the grantor
 * must re-establish emergency access); revocation of the grantor's suite
 * DELETES them outright, because the key they wrap is void.
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
use OCA\Doriath\Db\EmergencyContact;
use OCA\Doriath\Db\EmergencyContactMapper;

/**
 * Key-change invalidation of break-glass recovery envelopes.
 */
class EmergencyEnvelopeInvalidationService {
	/**
	 * Constructor for EmergencyEnvelopeInvalidationService.
	 *
	 * @param EmergencyContactMapper $mapper The emergency-contact mapper
	 * @param EmergencyAccessAuditTrail $auditTrail The emergency-access audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private EmergencyContactMapper $mapper,
		private EmergencyAccessAuditTrail $auditTrail,
	) {
	}//end __construct()

	/**
	 * Invalidate a grantor's recovery envelopes after their suite is ROTATED
	 * (compromise recovery). The envelopes hold the stale private key, so they
	 * are marked invalid and the grantor must re-establish emergency access.
	 *
	 * @param string $grantorSuiteId The rotated (old) suite ID
	 * @param string $reason The invalidation reason tag
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGrantorRotation(string $grantorSuiteId, string $reason = 'grantor_rotation'): int {
		$count = 0;
		foreach ($this->mapper->findByGrantorSuite(grantorSuiteId: $grantorSuiteId) as $contact) {
			if ($contact->getState() === EmergencyContact::STATE_INVALIDATED) {
				continue;
			}

			$this->invalidate(contact: $contact, reason: $reason);
			$count++;
		}

		return $count;
	}//end invalidateForGrantorRotation()

	/**
	 * Clear a grantor's recovery envelopes after their suite is REVOKED — the
	 * envelopes hold a now-void key and are deleted outright.
	 *
	 * @param string $grantorSuiteId The revoked suite ID
	 *
	 * @return int The number of relationships cleared
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function clearForGrantorRevocation(string $grantorSuiteId): int {
		$count = 0;
		foreach ($this->mapper->findByGrantorSuite(grantorSuiteId: $grantorSuiteId) as $contact) {
			$this->auditTrail->recordInvalidated(
				grantorUserId: $contact->getGrantorUserId(),
				granteeUserId: $contact->getGranteeUserId(),
				id: $contact->getId(),
				reason: 'grantor_revocation',
			);
			$this->mapper->delete($contact);
			$count++;
		}

		return $count;
	}//end clearForGrantorRevocation()

	/**
	 * Invalidate envelopes encrypted to a grantee whose suite was revoked — the
	 * grantee can no longer open them, so they are marked invalid.
	 *
	 * @param string $granteeSuiteId The revoked grantee suite ID
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGranteeRevocation(string $granteeSuiteId): int {
		$count = 0;
		foreach ($this->mapper->findByGranteeSuite(granteeSuiteId: $granteeSuiteId) as $contact) {
			if ($contact->getState() === EmergencyContact::STATE_INVALIDATED) {
				continue;
			}

			$this->invalidate(contact: $contact, reason: 'grantee_revocation');
			$count++;
		}

		return $count;
	}//end invalidateForGranteeRevocation()

	/**
	 * Mark a relationship invalidated: null the envelope, set the reason, and
	 * audit. The grantee can no longer break glass until the grantor re-establishes.
	 *
	 * @param EmergencyContact $contact The relationship
	 * @param string $reason The invalidation reason tag
	 *
	 * @return void
	 */
	private function invalidate(EmergencyContact $contact, string $reason): void {
		$contact->setState(EmergencyContact::STATE_INVALIDATED);
		$contact->setRecoveryEnvelope(null);
		$contact->setRequestedAt(null);
		$contact->setInvalidatedReason($reason);
		$contact->setUpdatedAt(new DateTime());
		$this->mapper->update($contact);

		$this->auditTrail->recordInvalidated(
			grantorUserId: $contact->getGrantorUserId(),
			granteeUserId: $contact->getGranteeUserId(),
			id: $contact->getId(),
			reason: $reason,
		);
	}//end invalidate()
}//end class
