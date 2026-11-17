<?php

/**
 * Keepiq RecoveryKitService
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryKit;
use OCA\Keepiq\Db\RecoveryKitMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Kernel\Contracts\RecoveryAuditInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryKitServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * A user's own recovery kit (crypto-recovery-kit).
 *
 * The browser makes the recovery code, derives a key from it and wraps the
 * suite private key; only the wrapped copy and the derivation parameters
 * arrive here. Every operation acts on the signed-in user's own active
 * suite, chosen here and never taken from the request, so nobody reaches
 * another user's copy and nobody stores one for a suite that is not theirs.
 * The audit trail gets the suite only, as the event's object.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
 */
class RecoveryKitService implements RecoveryKitServiceInterface {

	/**
	 * The largest wrapped copy or parameter set accepted, in bytes.
	 */
	public const MAX_LENGTH = 65536;

	/**
	 * Constructor.
	 *
	 * @param RecoveryKitMapper      $mapper      The kits.
	 * @param EncryptionSuiteMapper  $suiteMapper The suites, for the user's active one.
	 * @param RecoveryAuditInterface $audit       The audit trail.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function __construct(
		private RecoveryKitMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private RecoveryAuditInterface $audit,
	) {
	}//end __construct()

	/**
	 * Whether the user has a kit for their active suite, and when it was made.
	 *
	 * @param string $userId The owner.
	 *
	 * @return array{kit: array{suiteId: string, createdAt: string|null}|null}
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function status(string $userId): array {
		return ['kit' => $this->current(userId: $userId)?->jsonSerialize()];
	}//end status()

	/**
	 * Store a new wrapped copy for the user's active suite, replacing the old one.
	 *
	 * @param string $userId     The owner.
	 * @param string $wrappedKey The suite private key, wrapped in the browser.
	 * @param string $kdf        The derivation parameters the browser used.
	 *
	 * @return array{kit: array{suiteId: string, createdAt: string|null}|null}
	 *
	 * @throws InvalidArgumentException When a value is missing or too large, or the user has no active suite.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function create(string $userId, string $wrappedKey, string $kdf): array {
		foreach (['wrappedKey' => $wrappedKey, 'kdf' => $kdf] as $field => $value) {
			if (trim($value) === '' || strlen($value) > self::MAX_LENGTH) {
				throw new InvalidArgumentException(message: $field . ' is missing or too large');
			}
		}

		$suiteId = $this->activeSuiteId(userId: $userId);
		if ($suiteId === '') {
			throw new InvalidArgumentException(message: 'You have no active encryption suite');
		}

		if ($this->mapper->deleteForUser($userId, $suiteId) > 0) {
			$this->record(userId: $userId, eventType: RecoveryAuditInterface::KIT_REVOKED, suiteId: $suiteId);
		}

		$kit = new RecoveryKit();
		$kit->setId(Uuid::uuid4()->toString());
		$kit->setUserId($userId);
		$kit->setSuiteId($suiteId);
		$kit->setWrappedKey($wrappedKey);
		$kit->setKdf($kdf);
		$kit->setCreatedAt(new DateTime());
		$kit = $this->mapper->insert($kit);
		$this->record(userId: $userId, eventType: RecoveryAuditInterface::KIT_CREATED, suiteId: $suiteId);

		return ['kit' => $kit->jsonSerialize()];
	}//end create()

	/**
	 * The user's wrapped copy for their active suite, to unwrap in the browser.
	 *
	 * @param string $userId The owner.
	 *
	 * @return array{suiteId: string, wrappedKey: string, kdf: string}
	 *
	 * @throws NotFoundException When there is none.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	public function fetch(string $userId): array {
		$kit = $this->current(userId: $userId);
		if ($kit === null) {
			throw new NotFoundException(message: 'No recovery kit');
		}

		return ['suiteId' => $kit->getSuiteId(), 'wrappedKey' => $kit->getWrappedKey(), 'kdf' => $kit->getKdf()];
	}//end fetch()

	/**
	 * Delete the spent copy after a recovery and record its use.
	 *
	 * @param string $userId The owner.
	 *
	 * @return void
	 *
	 * @throws NotFoundException When there is none.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	public function spend(string $userId): void {
		$kit = $this->current(userId: $userId);
		if ($kit === null) {
			throw new NotFoundException(message: 'No recovery kit');
		}

		$this->mapper->deleteForUser($userId, $kit->getSuiteId());
		$this->record(userId: $userId, eventType: RecoveryAuditInterface::KIT_USED, suiteId: $kit->getSuiteId());
	}//end spend()

	/**
	 * Delete the user's copies without using them, and record each.
	 *
	 * @param string $userId The owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function revoke(string $userId): void {
		foreach ($this->mapper->findByUser($userId) as $kit) {
			$this->mapper->delete($kit);
			$this->record(userId: $userId, eventType: RecoveryAuditInterface::KIT_REVOKED, suiteId: $kit->getSuiteId());
		}
	}//end revoke()

	/**
	 * The user's kit for their active suite, or null.
	 *
	 * @param string $userId The owner.
	 *
	 * @return RecoveryKit|null
	 */
	private function current(string $userId): ?RecoveryKit {
		$suiteId = $this->activeSuiteId(userId: $userId);
		if ($suiteId === '') {
			return null;
		}

		foreach ($this->mapper->findByUser($userId) as $kit) {
			if ($kit->getSuiteId() === $suiteId) {
				return $kit;
			}
		}

		return null;
	}//end current()

	/**
	 * The user's active suite id, empty when they have none.
	 *
	 * @param string $userId The owner.
	 *
	 * @return string
	 */
	private function activeSuiteId(string $userId): string {
		try {
			return (string)$this->suiteMapper->findActiveByOwner('user', $userId)->getId();
		} catch (DoesNotExistException) {
			return '';
		}
	}//end activeSuiteId()

	/**
	 * Write one kit event to the audit trail: the suite is its object, and
	 * there is no metadata.
	 *
	 * @param string $userId    The owner, also the actor.
	 * @param string $eventType The event.
	 * @param string $suiteId   The suite.
	 *
	 * @return void
	 */
	private function record(string $userId, string $eventType, string $suiteId): void {
		$this->audit->record(actorId: $userId, eventType: $eventType, objectId: $suiteId);
	}//end record()
}//end class
