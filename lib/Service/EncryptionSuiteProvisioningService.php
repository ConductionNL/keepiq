<?php

/**
 * Keepiq Encryption Suite Provisioning Service
 *
 * Puts an EncryptionSuite into service: resolving the certificate common
 * name for its owner, obtaining a CA-signed certificate for its public
 * key, and persisting the new suite. Covers both entry points — a user
 * suite created from a browser-generated key pair and an application
 * suite provisioned from a PKCS#10 CSR — plus the re-issue a revoked
 * suite needs when it is reinstated.
 *
 * The suite's LATER lifecycle (revoke, reinstate, compromise, lookups)
 * is EncryptionSuiteService's; this class only ever mints and stores.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Support\SuppressesDiagnostics;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Mints and stores EncryptionSuites and their CA certificates.
 */
class EncryptionSuiteProvisioningService {
	use SuppressesDiagnostics;

	/**
	 * Constructor for EncryptionSuiteProvisioningService.
	 *
	 * @param EncryptionSuiteMapper $mapper The encryption suite mapper
	 * @param CertificateAuthorityService $caService The CA service
	 * @param IAppConfig $appConfig The app config interface
	 * @param IUserManager $userManager The user manager
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private EncryptionSuiteMapper $mapper,
		private CertificateAuthorityService $caService,
		private IAppConfig $appConfig,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create an EncryptionSuite for a user or application.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 *
	 * @return EncryptionSuite
	 *
	 * @throws ConflictException When the owner already has an active suite. Use
	 *                           createSuccessorSuite() for a compromise recovery,
	 *                           which is the one flow allowed a second active suite.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function createSuite(
		string $ownerType,
		string $ownerId,
		string $publicKeyPem,
		string $encryptedPrivateKey,
	): EncryptionSuite {
		// Sign BEFORE the duplicate check, deliberately, and this order was wrong in
		// the first version of this fix. Checking state first meant a caller who both
		// held a suite AND submitted malformed key material got 409, hiding a client
		// error behind a state error — caught by the Newman contract assertion "invalid
		// public key is a 400 client error (not 500)". Input validity is a property of
		// the request; whether a suite already exists is a property of the world, and
		// the request should be judged on its own terms first.
		//
		// The cost of this order is a certificate that gets discarded when the refusal
		// follows. That is CPU only: signing performs no database write and serials are
		// random_int() rather than a persisted counter, so nothing is consumed and no
		// gap is left behind.
		$certificate = $this->signFor(
			ownerType: $ownerType,
			ownerId: $ownerId,
			publicKeyPem: $publicKeyPem
		);

		// Reported as #289 — the endpoint checked auth, parameters and the migration
		// write-lock, but never whether a suite already existed, so any session could
		// mint a second active suite. Resolution picks the NEWEST active suite, so new
		// secrets were sealed to a key the owner was not unlocking with: they decrypt
		// for nobody, and nothing reports it at the time.
		$active = $this->mapper->countActiveByOwner(ownerType: $ownerType, ownerId: $ownerId);
		if ($active > 0) {
			throw new ConflictException(
				message: 'An active EncryptionSuite already exists for this owner. '
				. 'Change the master password or start a compromise recovery instead of creating a second suite.'
			);
		}

		return $this->persistSuite(
			ownerType: $ownerType,
			ownerId: $ownerId,
			certificate: $certificate,
			encryptedPrivateKey: $encryptedPrivateKey
		);
	}//end createSuite()

	/**
	 * Create a successor suite for a compromise recovery, bypassing the single-active
	 * rule.
	 *
	 * The ONE flow permitted two active suites at once. The old suite must stay active
	 * and readable for the whole migration — the browser decrypts what it is migrating
	 * with it — while the successor takes new writes. See "Suite Resolution Is
	 * Deterministic During A Migration": resolution selects the newest active suite,
	 * which is always the write target.
	 *
	 * Named rather than flagged so that the exception to the rule is legible at its
	 * call site, and so that reaching it is a deliberate act.
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
		$certificate = $this->signFor(
			ownerType: $ownerType,
			ownerId: $ownerId,
			publicKeyPem: $publicKeyPem
		);

		return $this->persistSuite(
			ownerType: $ownerType,
			ownerId: $ownerId,
			certificate: $certificate,
			encryptedPrivateKey: $encryptedPrivateKey
		);
	}//end createSuccessorSuite()

	/**
	 * Assert the CA is usable and sign the submitted public key.
	 *
	 * Separated from persistence so a caller can order the two around its own checks.
	 * `createSuite()` needs to sign first — a malformed key must be reported as a bad
	 * request rather than masked by a state conflict — and only then refuse a
	 * duplicate.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $publicKeyPem PEM-encoded public key
	 *
	 * @return string The issued PEM certificate
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
	 */
	private function signFor(string $ownerType, string $ownerId, string $publicKeyPem): string {
		$caStatus = $this->appConfig->getValueString(Application::APP_ID, 'ca_status', 'unknown');
		if ($caStatus !== 'healthy') {
			throw new RuntimeException('Cannot create EncryptionSuite: CA is not healthy (status: ' . $caStatus . ')');
		}

		$commonName = $this->resolveCommonName(ownerType: $ownerType, ownerId: $ownerId);

		return $this->caService->signPublicKey(publicKeyPem: $publicKeyPem, commonName: $commonName);
	}//end signFor()

	/**
	 * Build and store the suite row.
	 *
	 * Deliberately carries NO single-active check: whether an additional active suite
	 * is permitted is decided by whichever public method the caller chose, so it cannot
	 * be skipped by reaching this directly.
	 *
	 * @param string $ownerType 'user' or 'application'
	 * @param string $ownerId Nextcloud user ID or Application ID
	 * @param string $certificate The issued PEM certificate
	 * @param string $encryptedPrivateKey Base64-encoded AES-GCM envelope of the private key
	 *
	 * @return EncryptionSuite
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
	 */
	private function persistSuite(
		string $ownerType,
		string $ownerId,
		string $certificate,
		string $encryptedPrivateKey,
	): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId(Uuid::uuid4()->toString());
		$suite->setOwnerType($ownerType);
		$suite->setOwnerId($ownerId);
		$suite->setCertificate($certificate);
		$suite->setPrivateKey($encryptedPrivateKey);
		$suite->setStatus('active');
		$suite->setCreatedAt(new DateTime());

		$this->mapper->insert($suite);

		$this->logger->info("Keepiq: EncryptionSuite created for {$ownerType}/{$ownerId}");

		return $suite;
	}//end persistSuite()

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
	 * @throws RuntimeException When the CSR's public key cannot be extracted.
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
	 */
	public function provisionForApplication(string $applicationId, string $csrPem): EncryptionSuite {
		if ($applicationId === '') {
			throw new InvalidArgumentException('applicationId is required');
		}

		if ($csrPem === '') {
			throw new InvalidArgumentException('csrPem is required');
		}

		// The openssl_csr_get_public_key() call warns on a malformed CSR and
		// returns false; the false return is the condition we act on.
		$publicKeyResource = $this->withoutDiagnostics(call: static fn () => openssl_csr_get_public_key($csrPem));
		if ($publicKeyResource === false) {
			throw new RuntimeException('Could not extract public key from CSR');
		}

		$details = openssl_pkey_get_details($publicKeyResource);
		if ($details === false || isset($details['key']) === false) {
			throw new RuntimeException('Public key details unreadable from CSR');
		}

		$publicKeyPem = (string)$details['key'];

		return $this->createSuite(
			ownerType: 'application',
			ownerId: $applicationId,
			publicKeyPem: $publicKeyPem,
			// Applications hold their own private key — server stores no envelope.
			encryptedPrivateKey: '',
		);
	}//end provisionForApplication()

	/**
	 * Re-sign a suite's EXISTING public key with the active intermediate,
	 * returning the fresh certificate PEM. Used when a revoked suite is
	 * reinstated: the key pair is untouched, only the certificate is new.
	 *
	 * @param EncryptionSuite $suite The suite whose certificate to re-issue
	 *
	 * @return string The new certificate PEM
	 *
	 * @throws RuntimeException When the public key cannot be read back.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	public function reissueCertificateForSuite(EncryptionSuite $suite): string {
		// Re-sign the existing public key with the active intermediate.
		$publicKey = openssl_pkey_get_public(public_key: $suite->getCertificate());
		if ($publicKey === false) {
			throw new RuntimeException('Could not extract public key from suite certificate');
		}

		$details = openssl_pkey_get_details(key: $publicKey);
		$publicKeyPem = $details['key'];
		$commonName = $this->resolveCommonName(
			ownerType: $suite->getOwnerType(),
			ownerId: $suite->getOwnerId()
		);

		return $this->caService->signPublicKey(
			publicKeyPem: $publicKeyPem,
			commonName: $commonName
		);
	}//end reissueCertificateForSuite()

	/**
	 * Resolve the certificate common name for an owner.
	 *
	 * For users, returns the federated cloud ID (user@instance) if available,
	 * otherwise falls back to the user ID. For applications, returns the owner ID.
	 *
	 * @param string $ownerType The owner type (user or application)
	 * @param string $ownerId The owner ID
	 *
	 * @return string
	 */
	private function resolveCommonName(string $ownerType, string $ownerId): string {
		if ($ownerType === 'user') {
			$user = $this->userManager->get($ownerId);
			if ($user !== null) {
				return $user->getCloudId();
			}
		}

		return $ownerId;
	}//end resolveCommonName()
}//end class
