<?php

/**
 * Keepiq Emergency Designation
 *
 * A grantor's designation of an emergency contact.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContact;

/**
 * The grantor, the grantee, the wait period, the access level and the
 * recovery envelope the grantor's browser built for the grantee.
 *
 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
 */
final class EmergencyDesignation {
	/**
	 * Constructor for EmergencyDesignation.
	 *
	 * @param string $grantorUserId The grantor (vault owner) Nextcloud user ID
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param int $waitPeriodDays The wait period (1|3|7|30)
	 * @param string $accessLevel The access level (v1: only 'view')
	 * @param string $recoveryEnvelope The grantee-encrypted recovery envelope
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $grantorUserId,
		public readonly string $granteeUserId,
		public readonly int $waitPeriodDays,
		public readonly string $accessLevel,
		public readonly string $recoveryEnvelope,
	) {
	}//end __construct()

	/**
	 * Refuse an unsupported access level or wait period, a self-designation
	 * and a missing envelope, in that order.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the designation is invalid
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	public function assertValid(): void {
		if ($this->accessLevel !== EmergencyContact::ACCESS_VIEW) {
			throw new InvalidArgumentException('v1 emergency access supports the "view" access level only');
		}

		if (in_array($this->waitPeriodDays, EmergencyAccessService::ALLOWED_WAIT_PERIODS, true) === false) {
			throw new InvalidArgumentException('Unsupported wait period');
		}

		if ($this->granteeUserId === '' || $this->granteeUserId === $this->grantorUserId) {
			throw new InvalidArgumentException('Cannot designate yourself as an emergency contact');
		}

		if (trim($this->recoveryEnvelope) === '') {
			throw new InvalidArgumentException('A recovery envelope is required');
		}
	}//end assertValid()

	/**
	 * The grant of this designation, sealed to the grantee suite and bound to
	 * the grantor suite, made now.
	 *
	 * @param string $granteeSuiteId The grantee suite the envelope is sealed to
	 * @param string $grantorSuiteId The grantor's active suite
	 *
	 * @return EmergencyGrant
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-client-side-recovery-envelope-escrow
	 */
	public function grantWith(string $granteeSuiteId, string $grantorSuiteId): EmergencyGrant {
		return new EmergencyGrant(
			designation: $this,
			granteeSuiteId: $granteeSuiteId,
			grantorSuiteId: $grantorSuiteId,
			now: new DateTime(),
		);
	}//end grantWith()
}//end class
