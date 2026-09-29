<?php

/**
 * Keepiq EncryptionSuiteRevokedEvent
 *
 * Dispatched when an EncryptionSuite is revoked — listeners cascade
 * through share targets the suite's owner can no longer decrypt and
 * promote any temporary delegations they had granted to permanent.
 *
 * @category Event
 * @package  OCA\Keepiq\Event
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

namespace OCA\Keepiq\Event;

use OCP\EventDispatcher\Event;

/**
 * Fired when an EncryptionSuite enters the 'revoked' state.
 */
class EncryptionSuiteRevokedEvent extends Event {
	/**
	 * Constructor for EncryptionSuiteRevokedEvent.
	 *
	 * @param string $suiteId The revoked suite ID
	 * @param string $ownerType The owner type ('user' or 'application')
	 * @param string $ownerId The owner Nextcloud user ID or application ID
	 * @param string $revokedBy The user that triggered the revocation
	 * @param bool $compromised Whether the revocation treats the suite as compromised
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $compromised carries the
	 *   administrator's explicit, transient compromise decision (ADR-005); it is
	 *   a payload field on the event, not a mode switch between two behaviours.
	 */
	public function __construct(
		private string $suiteId,
		private string $ownerType,
		private string $ownerId,
		private string $revokedBy,
		private bool $compromised = false,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the revoked suite ID.
	 *
	 * @return string
	 */
	public function getSuiteId(): string {
		return $this->suiteId;
	}//end getSuiteId()

	/**
	 * Get the suite owner type.
	 *
	 * @return string
	 */
	public function getOwnerType(): string {
		return $this->ownerType;
	}//end getOwnerType()

	/**
	 * Get the suite owner ID.
	 *
	 * @return string
	 */
	public function getOwnerId(): string {
		return $this->ownerId;
	}//end getOwnerId()

	/**
	 * Get the user that triggered the revocation.
	 *
	 * @return string
	 */
	public function getRevokedBy(): string {
		return $this->revokedBy;
	}//end getRevokedBy()

	/**
	 * Whether this revocation treats the suite's secrets as compromised.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.BooleanGetMethodName) The accessor mirrors the
	 *   event's other get* getters and its callers/tests read it as
	 *   getCompromised(); the flag is a plain payload field (admin-suite-revocation).
	 */
	public function getCompromised(): bool {
		return $this->compromised;
	}//end getCompromised()
}//end class
