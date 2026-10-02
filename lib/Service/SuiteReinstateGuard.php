<?php

/**
 * Keepiq Suite Reinstate Guard
 *
 * Decides whether a revoked encryption suite may be reinstated.
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

use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ReinstateRefusedException;

/**
 * Refuses a reinstate that would undo a containment or duplicate an identity.
 *
 * A suite revoked as compromised stays revoked (keepiq#865). When the audit
 * trail cannot show how the suite was revoked, the reinstate is refused too:
 * without a trail this guard fails closed.
 */
class SuiteReinstateGuard {
	/**
	 * Constructor for SuiteReinstateGuard.
	 *
	 * @param EncryptionSuiteMapper $mapper       The encryption suite mapper
	 * @param AuditEntryMapper|null $auditEntries The audit trail (reads how a suite was revoked)
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteMapper $mapper,
		private ?AuditEntryMapper $auditEntries = null,
	) {
	}//end __construct()

	/**
	 * Refuse a reinstate that would undo a containment or duplicate an identity.
	 *
	 * @param EncryptionSuite $suite The revoked suite
	 *
	 * @return void
	 *
	 * @throws ReinstateRefusedException
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
	 */
	public function assertReinstatable(EncryptionSuite $suite): void {
		$revokedAsCompromise = $this->wasRevokedAsCompromise(suiteId: (string)$suite->getId());
		if ($revokedAsCompromise !== false) {
			throw new ReinstateRefusedException(
				error: 'revoked_as_compromised',
				message: 'This suite was revoked as compromised, or its revocation cannot be confirmed. '
					. 'Its owner has to set up a new vault instead.'
			);
		}

		if ($this->mapper->countActiveByOwner($suite->getOwnerType(), $suite->getOwnerId()) > 0) {
			throw new ReinstateRefusedException(
				error: 'owner_has_active_suite',
				message: 'The owner already has an active suite. Reinstating this one would give them two.'
			);
		}
	}//end assertReinstatable()

	/**
	 * Whether the suite's last revocation was marked as a compromise.
	 *
	 * @param string $suiteId The suite
	 *
	 * @return bool|null True or false from the last SUITE_REVOKED entry, null when none is found
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-revoked-as-compromised-cannot-be-reinstated
	 */
	private function wasRevokedAsCompromise(string $suiteId): ?bool {
		if ($this->auditEntries === null) {
			return null;
		}

		foreach ($this->auditEntries->findByObject(objectType: 'suite', objectId: $suiteId) as $entry) {
			if ($entry->getEventType() !== AuditEventTypes::SUITE_REVOKED) {
				continue;
			}

			$metadata = json_decode((string)$entry->getMetadata(), true);
			return is_array($metadata) === true && ($metadata['markCompromised'] ?? false) === true;
		}

		return null;
	}//end wasRevokedAsCompromise()
}//end class
