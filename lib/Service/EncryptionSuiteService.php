<?php

/**
 * Keepiq Encryption Suite Service
 *
 * Business logic for EncryptionSuite lifecycle: create, revoke, reinstate.
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
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;

/**
 * Business logic for the EncryptionSuite lifecycle AFTER provisioning:
 * revoke, reinstate, compromise and lookups. Minting a suite and its
 * CA certificate is EncryptionSuiteProvisioningService's job; the two
 * creation entry points stay here as thin forwards so callers keep one
 * suite-shaped service.
 */
class EncryptionSuiteService {
	/**
	 * Constructor for EncryptionSuiteService.
	 *
	 * @param EncryptionSuiteMapper $mapper The encryption suite mapper
	 * @param EncryptionSuiteProvisioningService $provisioning The suite/certificate minter
	 * @param LoggerInterface $logger The logger interface
	 * @param IEventDispatcher|null $eventDispatcher The event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteMapper $mapper,
		private EncryptionSuiteProvisioningService $provisioning,
		private LoggerInterface $logger,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Create an EncryptionSuite for a user or application.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 * @return EncryptionSuite
	 *
	 * Refuses when the owner already has an active suite — see the provisioning
	 * service, which throws. A compromise recovery uses createSuccessorSuite().
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createSuite(
		string $ownerType,
		string $ownerId,
		string $publicKeyPem,
		string $encryptedPrivateKey,
	): EncryptionSuite {
		return $this->provisioning->createSuite(
			ownerType: $ownerType,
			ownerId: $ownerId,
			publicKeyPem: $publicKeyPem,
			encryptedPrivateKey: $encryptedPrivateKey,
		);
	}//end createSuite()

	/**
	 * Create a successor suite for a compromise recovery.
	 *
	 * The one flow allowed a second active suite: the old one stays active and
	 * readable so the browser can decrypt what it is migrating, while the successor
	 * takes new writes.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 *
	 * @return EncryptionSuite
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createSuccessorSuite(
		string $ownerType,
		string $ownerId,
		string $publicKeyPem,
		string $encryptedPrivateKey,
	): EncryptionSuite {
		return $this->provisioning->createSuccessorSuite(
			ownerType: $ownerType,
			ownerId: $ownerId,
			publicKeyPem: $publicKeyPem,
			encryptedPrivateKey: $encryptedPrivateKey,
		);
	}//end createSuccessorSuite()

	/**
	 * Provision an EncryptionSuite for a registered application.
	 *
	 * The application supplies its public key via a PKCS#10 CSR; the
	 * private key never leaves the application's possession, so the
	 * stored `private_key` blob is intentionally empty (applications
	 * decrypt server-issued ciphertext with their own private key).
	 *
	 * The CSR is parsed via OpenSSL, the public key extracted as PEM,
	 * and the public key is then signed by the active CA intermediate
	 * via `createSuite()`. The resulting suite is keyed to
	 * `owner_type=application` / `owner_id=$applicationId`.
	 *
	 * @param string $applicationId The Application ID
	 * @param string $csrPem The PEM-encoded PKCS#10 CSR
	 *
	 * @return EncryptionSuite
	 *
	 * @throws \RuntimeException When the CSR's public key cannot be extracted.
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
	 */
	public function provisionForApplication(string $applicationId, string $csrPem): EncryptionSuite {
		return $this->provisioning->provisionForApplication(applicationId: $applicationId, csrPem: $csrPem);
	}//end provisionForApplication()

	/**
	 * Revoke an EncryptionSuite.
	 *
	 * @param string $id The suite ID
	 * @param string $reason The reason for revocation
	 * @param string $revokedBy The user who revoked the suite
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function revokeSuite(string $id, string $reason, string $revokedBy): EncryptionSuite {
		$suite = $this->mapper->findById($id);

		if ($suite->getStatus() === 'compromised') {
			throw new InvalidArgumentException('Cannot revoke a compromised suite — it has already been replaced');
		}

		$suite->setStatus('revoked');
		$suite->setRevokedAt(new DateTime());
		$suite->setRevokedReason($reason);
		$suite->setRevokedBy($revokedBy);

		$this->mapper->update($suite);

		$this->logger->info("Keepiq: EncryptionSuite {$id} revoked by {$revokedBy}: {$reason}");

		// Implement-user-sharing §10.3 — dispatch a revocation event so
		// EncryptionSuiteRevokedListener can cascade share-target
		// cleanup and promote temporary delegations to permanent.
		if ($this->eventDispatcher !== null) {
			$this->eventDispatcher->dispatchTyped(
				new EncryptionSuiteRevokedEvent(
					suiteId: $id,
					ownerType: $suite->getOwnerType(),
					ownerId: $suite->getOwnerId(),
					revokedBy: $revokedBy,
				)
			);
		}

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $revokedBy,
				eventType: AuditEventTypes::SUITE_REVOKED,
				objectType: 'suite',
				objectId: $id,
				metadata: ['reason' => $reason],
			)
		);

		return $suite;
	}//end revokeSuite()

	/**
	 * Reinstate a revoked EncryptionSuite. Re-signs the public key with the active intermediate.
	 *
	 * @param string $id The suite ID
	 * @param string $reinstatedBy The user who reinstated the suite
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function reinstateSuite(string $id, string $reinstatedBy): EncryptionSuite {
		$suite = $this->mapper->findById($id);

		if ($suite->getStatus() !== 'revoked') {
			throw new InvalidArgumentException(
				'Only revoked suites can be reinstated (current status: ' . $suite->getStatus() . ')'
			);
		}

		// Re-sign the existing public key with the active intermediate.
		$newCertificate = $this->provisioning->reissueCertificateForSuite(suite: $suite);

		$suite->setCertificate($newCertificate);
		$suite->setStatus('active');
		$suite->setReinstatedAt(new DateTime());
		$suite->setReinstatedBy($reinstatedBy);

		$this->mapper->update($suite);

		$this->logger->info("Keepiq: EncryptionSuite {$id} reinstated by {$reinstatedBy}");

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $reinstatedBy,
				eventType: AuditEventTypes::SUITE_REINSTATED,
				objectType: 'suite',
				objectId: $id,
			)
		);

		return $suite;
	}//end reinstateSuite()

	/**
	 * Mark an EncryptionSuite as compromised. Called immediately when
	 * compromise recovery is initiated — before migration begins.
	 *
	 * @param string $id The suite ID
	 * @param string $compromisedBy The user who reported the compromise
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function markCompromised(string $id, string $compromisedBy): EncryptionSuite {
		$suite = $this->mapper->findById($id);

		$suite->setStatus('compromised');
		$suite->setRevokedAt(new DateTime());
		$suite->setRevokedReason('Master password compromised');
		$suite->setRevokedBy($compromisedBy);

		$this->mapper->update($suite);

		$this->logger->warning("Keepiq: EncryptionSuite {$id} marked compromised by {$compromisedBy}");

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $compromisedBy,
				eventType: AuditEventTypes::SUITE_RECOVERY_STARTED,
				objectType: 'suite',
				objectId: $id,
			)
		);

		return $suite;
	}//end markCompromised()

	/**
	 * Persist changes to an EncryptionSuite.
	 *
	 * @param EncryptionSuite $suite The suite to update
	 *
	 * @return EncryptionSuite
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function updateSuite(EncryptionSuite $suite): EncryptionSuite {
		return $this->mapper->update($suite);
	}//end updateSuite()

	/**
	 * Get the active EncryptionSuite for an owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 */
	public function getActiveSuite(string $ownerType, string $ownerId): EncryptionSuite {
		return $this->mapper->findActiveByOwner($ownerType, $ownerId);
	}//end getActiveSuite()

	/**
	 * Get an EncryptionSuite by ID.
	 *
	 * @param string $id The suite ID
	 *
	 * @return EncryptionSuite
	 *
	 * @throws DoesNotExistException
	 */
	public function getSuite(string $id): EncryptionSuite {
		return $this->mapper->findById($id);
	}//end getSuite()

	/**
	 * Get all EncryptionSuites for an owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return EncryptionSuite[]
	 */
	public function getSuitesByOwner(string $ownerType, string $ownerId): array {
		return $this->mapper->findByOwner($ownerType, $ownerId);
	}//end getSuitesByOwner()
}//end class
