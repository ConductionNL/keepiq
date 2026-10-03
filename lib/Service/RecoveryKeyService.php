<?php

/**
 * Keepiq RecoveryKeyService
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RecoveryKey;
use OCA\Keepiq\Db\RecoveryKeyMapper;
use OCA\Keepiq\Db\RecoveryOfficer;
use OCA\Keepiq\Db\RecoveryOfficerMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * The organisation recovery key (crypto-organisation-account-recovery D2):
 * an officer's browser generates it, the instance CA certifies its public
 * half, and the server stores one copy per officer, wrapped to that
 * officer's suite certificate. The server never holds the private key in
 * any other form, and hands each officer only their own copy.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The key lifecycle touches the
 *   CA, the suites and both recovery tables.
 */
class RecoveryKeyService {

	public const STATUS_ACTIVE = 'active';

	public const STATUS_RETIRING = 'retiring';

	public const STATUS_RETIRED = 'retired';

	/**
	 * Constructor for RecoveryKeyService.
	 *
	 * @param RecoveryKeyMapper          $keyMapper     The recovery key mapper
	 * @param RecoveryOfficerMapper      $officerMapper The officer copy mapper
	 * @param RecoveryPolicyService      $policy        The officers and threshold
	 * @param EncryptionSuiteMapper      $suiteMapper   The suite mapper
	 * @param CertificateIssuanceService $issuance      The CA issuance service
	 * @param CACertificateMapper        $caMapper      The CA certificate mapper (chain)
	 * @param RecoveryAudit              $audit         The recovery audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private RecoveryKeyMapper $keyMapper,
		private RecoveryOfficerMapper $officerMapper,
		private RecoveryPolicyService $policy,
		private EncryptionSuiteMapper $suiteMapper,
		private CertificateIssuanceService $issuance,
		private CACertificateMapper $caMapper,
		private RecoveryAudit $audit,
	) {
	}//end __construct()

	/**
	 * The active recovery key, or null when none exists yet.
	 *
	 * @return RecoveryKey|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function activeKey(): ?RecoveryKey {
		return ($this->keyMapper->findByStatus(self::STATUS_ACTIVE)[0] ?? null);
	}//end activeKey()

	/**
	 * What any user may see of the active key: the certificate, its
	 * fingerprint and the instance CA chain to check it against.
	 *
	 * @return array{id:string,certificate:string,fingerprint:string,caChain:string[]}|null
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public function publicInfo(): ?array {
		$key = $this->activeKey();
		if ($key === null) {
			return null;
		}

		$chain = [];
		try {
			$chain[] = $this->caMapper->findActiveIntermediate()->getCertificate();
			$chain[] = $this->caMapper->findRoot()->getCertificate();
		} catch (DoesNotExistException) {
			// No CA chain: the browser cannot check and will refuse to enrol.
			$chain = [];
		}

		return [
			'id' => $key->getId(),
			'certificate' => $key->getCertificate(),
			'fingerprint' => $key->getFingerprint(),
			'caChain' => $chain,
		];
	}//end publicInfo()

	/**
	 * Create a new recovery key from an officer's browser: certify its public
	 * half and store one wrapped copy per named officer. An existing active
	 * key becomes `retiring`, so enrolled users re-enrol at their next unlock.
	 *
	 * @param string               $officerUid   The officer creating it
	 * @param string               $publicKeyPem The recovery public key
	 * @param array<string,string> $copies       Officer uid => wrapped private key
	 *
	 * @return RecoveryKey
	 *
	 * @throws ForbiddenException       When the caller is not an officer
	 * @throws InvalidArgumentException When a copy is missing or one is for a non-officer
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function createKey(string $officerUid, string $publicKeyPem, array $copies): RecoveryKey {
		if ($this->policy->isOfficer(userId: $officerUid) === false) {
			throw new ForbiddenException(message: 'Only a recovery officer can create the recovery key');
		}

		$officers = $this->policy->officers();
		$given    = array_keys($copies);
		sort($officers);
		sort($given);
		if ($given !== $officers) {
			throw new InvalidArgumentException(message: 'Send exactly one wrapped copy for each named officer');
		}

		$certificate = $this->issuance->signPublicKey(publicKeyPem: $publicKeyPem, commonName: 'Keepiq organisation recovery');

		foreach ($this->keyMapper->findByStatus(self::STATUS_ACTIVE) as $previous) {
			$previous->setStatus(self::STATUS_RETIRING);
			$this->keyMapper->update($previous);
		}

		$key = new RecoveryKey();
		$key->setId(Uuid::uuid4()->toString());
		$key->setCertificate($certificate);
		$key->setFingerprint(self::fingerprint(certificatePem: $certificate));
		$key->setThreshold($this->policy->threshold());
		$key->setStatus(self::STATUS_ACTIVE);
		$key->setCreatedBy($officerUid);
		$key->setCreatedAt(new DateTime());
		$key = $this->keyMapper->insert($key);

		foreach ($copies as $uid => $wrapped) {
			$this->storeCopy(key: $key, officerUid: (string)$uid, wrapped: (string)$wrapped, addedBy: $officerUid);
		}

		$this->audit->record(actorId: $officerUid, eventType: RecoveryAudit::KEY_CREATED, objectId: $key->getId());

		return $key;
	}//end createKey()

	/**
	 * An officer's own copy of a recovery key (the active one by default).
	 *
	 * @param string      $officerUid    The officer
	 * @param string|null $recoveryKeyId The key, or null for the active one
	 *
	 * @return RecoveryOfficer
	 *
	 * @throws NotFoundException When the officer holds no copy
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-recovery-private-key-is-generated-and-held-by-officers-only
	 */
	public function ownCopy(string $officerUid, ?string $recoveryKeyId = null): RecoveryOfficer {
		$keyId = ($recoveryKeyId ?? $this->activeKey()?->getId());
		if ($keyId === null || $this->policy->isOfficer(userId: $officerUid) === false) {
			throw new NotFoundException(message: 'No recovery key copy');
		}

		try {
			return $this->officerMapper->findForKeyAndOfficer($keyId, $officerUid);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'No recovery key copy');
		}
	}//end ownCopy()

	/**
	 * Replace an officer's own copy after their suite rotated (D7).
	 *
	 * @param string $officerUid    The officer
	 * @param string $recoveryKeyId The key
	 * @param string $wrapped       The copy wrapped to the officer's new suite
	 *
	 * @return void
	 *
	 * @throws NotFoundException        When the officer holds no copy of that key
	 * @throws InvalidArgumentException When the officer has no active suite
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function replaceOwnCopy(string $officerUid, string $recoveryKeyId, string $wrapped): void {
		$copy = $this->ownCopy(officerUid: $officerUid, recoveryKeyId: $recoveryKeyId);
		$copy->setWrappedPrivateKey($wrapped);
		$copy->setOfficerSuiteId($this->activeSuiteId(userId: $officerUid));
		$this->officerMapper->update($copy);
	}//end replaceOwnCopy()

	/**
	 * Retire a recovery key: no new enrolments go to it.
	 *
	 * @param string $recoveryKeyId The key
	 * @param string $adminUid      The administrator
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the key does not exist
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-enrolments-and-officer-copies-follow-the-suite
	 */
	public function retire(string $recoveryKeyId, string $adminUid): void {
		try {
			$key = $this->keyMapper->findById($recoveryKeyId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Recovery key not found');
		}

		$key->setStatus(self::STATUS_RETIRED);
		$key->setRetiredAt(new DateTime());
		$this->keyMapper->update($key);
		$this->audit->record(actorId: $adminUid, eventType: RecoveryAudit::KEY_RETIRED, objectId: $key->getId());
	}//end retire()

	/**
	 * The SHA-256 fingerprint (hex) of a PEM certificate's DER bytes.
	 *
	 * @param string $certificatePem The certificate
	 *
	 * @return string
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-users-enrol-by-wrapping-their-own-key-to-the-recovery-certificate
	 */
	public static function fingerprint(string $certificatePem): string {
		$body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $certificatePem);
		$der  = base64_decode((string)$body, true);
		if ($der === false) {
			return hash('sha256', $certificatePem);
		}

		return hash('sha256', $der);
	}//end fingerprint()

	/**
	 * Store one officer's wrapped copy.
	 *
	 * @param RecoveryKey $key        The key
	 * @param string      $officerUid The officer
	 * @param string      $wrapped    The wrapped private key
	 * @param string      $addedBy    Who stored it
	 *
	 * @return void
	 */
	private function storeCopy(RecoveryKey $key, string $officerUid, string $wrapped, string $addedBy): void {
		if (trim($wrapped) === '') {
			throw new InvalidArgumentException(message: 'A wrapped copy is empty');
		}

		$copy = new RecoveryOfficer();
		$copy->setId(Uuid::uuid4()->toString());
		$copy->setRecoveryKeyId($key->getId());
		$copy->setOfficerUid($officerUid);
		$copy->setOfficerSuiteId($this->activeSuiteId(userId: $officerUid));
		$copy->setWrappedPrivateKey($wrapped);
		$copy->setAddedBy($addedBy);
		$copy->setAddedAt(new DateTime());
		$this->officerMapper->insert($copy);
	}//end storeCopy()

	/**
	 * A user's active suite id.
	 *
	 * @param string $userId The user
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the user has no active suite
	 */
	private function activeSuiteId(string $userId): string {
		try {
			return $this->suiteMapper->findActiveByOwner('user', $userId)->getId();
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException(message: 'Officer ' . $userId . ' has no active encryption suite');
		}
	}//end activeSuiteId()
}//end class
