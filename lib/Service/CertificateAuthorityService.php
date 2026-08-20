<?php

/**
 * Doriath Certificate Authority Service
 *
 * Owns the private CA HIERARCHY — the root and intermediate certificates
 * themselves: bootstrap (including recovery from a half-written
 * bootstrap), intermediate rollover, and root rollover. Everything the
 * CA does *for other objects* lives in dedicated collaborators:
 * CertificateIssuanceService issues and re-issues leaf certificates from
 * the active intermediate, X509CertificateAssembler mints the DER, and
 * CertificateAuthorityStatusService reports health and issued counts.
 * The signing and status entry points are kept here as thin forwards so
 * existing callers (the admin controller, the seed, the renewal job)
 * keep a single CA-shaped facade.
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
use OCA\Doriath\AppInfo\Application;
use OCA\Doriath\Db\CACertificate;
use OCA\Doriath\Db\CACertificateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Manages the private Certificate Authority (root + intermediate).
 */
class CertificateAuthorityService {
	private const ROOT_LIFETIME_DAYS = 7300;
	private const INTERMEDIATE_LIFETIME_DAYS = 1095;

	/**
	 * Constructor for CertificateAuthorityService.
	 *
	 * @param CACertificateMapper $caCertificateMapper The CA certificate mapper
	 * @param IAppConfig $appConfig The app config interface
	 * @param ICrypto $crypto The crypto service
	 * @param LoggerInterface $logger The logger interface
	 * @param CertificateIssuanceService $issuanceService The leaf-certificate issuer
	 * @param CertificateAuthorityStatusService $statusService The CA health/counts reporter
	 *
	 * @return void
	 */
	public function __construct(
		private CACertificateMapper $caCertificateMapper,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private LoggerInterface $logger,
		private CertificateIssuanceService $issuanceService,
		private CertificateAuthorityStatusService $statusService,
	) {
	}//end __construct()

	/**
	 * Bootstrap the CA: generate root + intermediate certificates.
	 *
	 * Idempotent in three ways:
	 *  - Skips entirely when both root and an active intermediate already exist (healthy).
	 *  - Recovers (creates only the missing intermediate) when a root exists but no
	 *    active intermediate does — this is the half-broken state left behind by the
	 *    pre-#40 Postgres SMALLINT/boolean mismatch that aborted the second insert
	 *    after the root was already persisted.
	 *  - Performs the full root + intermediate bootstrap when neither exists.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) PHP's openssl_x509_export / openssl_pkey_export
	 *   populate $rootCertPem / $rootKeyPem via by-reference output params — PHPMD cannot trace
	 *   by-ref semantics and incorrectly reports these as undefined.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function bootstrap(): void {
		try {
			$root = $this->caCertificateMapper->findRoot();
		} catch (DoesNotExistException) {
			$root = null;
		}

		if ($root !== null) {
			try {
				$this->caCertificateMapper->findActiveIntermediate();
				$this->logger->info('Doriath: CA already bootstrapped, skipping');
				// Healthy state — make sure ca_status reflects it in case a prior
				// partial-state install left it unset or degraded.
				$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
				return;
			} catch (DoesNotExistException) {
				// Partial state: root exists but intermediate does not.
				// Recover by issuing only the missing intermediate against the existing root.
				$this->logger->warning(
					'Doriath: detected partial CA state (root present, no active intermediate) — recovering intermediate'
				);
				$recovered = $this->recoverIntermediate(root: $root);
				$this->logger->info('Doriath: CA partial-state recovery: intermediate issued', ['id' => $recovered->getId()]);
				$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
				$this->logger->info('Doriath: CA partial-state recovery complete');
				return;
			}
		}//end if

		$this->logger->info('Doriath: Bootstrapping Certificate Authority');

		// Generate root CA key pair.
		$rootKey = openssl_pkey_new(
			options: [
				'private_key_bits' => 4096,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
		// @codeCoverageIgnoreStart
		if ($rootKey === false) {
			$this->setDegraded();
			throw new RuntimeException('Failed to generate root CA key: ' . openssl_error_string());
		}

		// @codeCoverageIgnoreEnd
		// Self-sign the root certificate.
		$rootCsr = openssl_csr_new(
			distinguished_names: array_merge(CertificateIssuanceService::DEFAULT_DN, ['commonName' => 'Doriath Root CA']),
			private_key: $rootKey,
			options: ['digest_alg' => 'sha256']
		);
		$rootCert = openssl_csr_sign(
			csr: $rootCsr,
			ca_certificate: null,
			private_key: $rootKey,
			days: self::ROOT_LIFETIME_DAYS,
			options: ['digest_alg' => 'sha256'],
			serial: random_int(1, PHP_INT_MAX)
		);

		// @codeCoverageIgnoreStart
		if ($rootCert === false) {
			$this->setDegraded();
			throw new RuntimeException('Failed to sign root CA certificate: ' . openssl_error_string());
		}

		// @codeCoverageIgnoreEnd
		openssl_x509_export(certificate: $rootCert, output: $rootCertPem);
		openssl_pkey_export(key: $rootKey, output: $rootKeyPem);

		// Store root certificate with encrypted private key.
		$rootEntity = new CACertificate();
		$rootEntity->setId(Uuid::uuid4()->toString());
		$rootEntity->setType('root');
		$rootEntity->setCertificate($rootCertPem);
		$rootEntity->setPrivateKey($this->crypto->encrypt($rootKeyPem));
		$rootEntity->setCreatedAt(new DateTime());
		$rootEntity->setExpiresAt(new DateTime('+' . self::ROOT_LIFETIME_DAYS . ' days'));
		$rootEntity->setIsActive(false);
		$this->caCertificateMapper->insert($rootEntity);

		// Generate and sign the intermediate certificate.
		$this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

		$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
		$this->logger->info('Doriath: CA bootstrap complete');
	}//end bootstrap()

	/**
	 * Retry bootstrap (called from admin panel when CA is degraded).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function retryBootstrap(): void {
		$this->bootstrap();
	}//end retryBootstrap()

	/**
	 * Sign a public key PEM with the active intermediate, returning an X.509 certificate PEM.
	 *
	 * @param string $publicKeyPem The PEM-encoded public key
	 * @param string $commonName The common name for the certificate (e.g. user ID or app name)
	 * @param string|null $privateKeyPem The PEM-encoded matching private key, when available
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function signPublicKey(
		string $publicKeyPem,
		string $commonName = 'Doriath User',
		?string $privateKeyPem = null,
	): string {
		return $this->issuanceService->signPublicKey(
			publicKeyPem: $publicKeyPem,
			commonName: $commonName,
			privateKeyPem: $privateKeyPem
		);
	}//end signPublicKey()

	/**
	 * Sign a PKCS#10 CSR with the active intermediate certificate.
	 *
	 * @param string $csrPem The PEM-encoded CSR
	 *
	 * @return string
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function signCsr(string $csrPem): string {
		return $this->issuanceService->signCsr(csrPem: $csrPem);
	}//end signCsr()

	/**
	 * Renew the intermediate certificate.
	 *
	 * @param bool $forced If true, immediately revoke the old intermediate.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forced does not select between two
	 *   behaviours: the rollover (generate, deactivate, re-sign) is identical either
	 *   way. It adds one extra fact to the old intermediate — an immediate revokedAt —
	 *   for the compromise path. The scheduled caller (RenewIntermediateCertificate)
	 *   relies on the false default, so the default cannot be dropped.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewIntermediate(bool $forced = false): int {
		$root = $this->caCertificateMapper->findRoot();
		$rootKey = openssl_pkey_get_private(
			private_key: $this->crypto->decrypt($root->getPrivateKey())
		);
		$rootCert = $root->getCertificate();

		$oldIntermediate = $this->caCertificateMapper->findActiveIntermediate();

		// Generate new intermediate.
		$newIntermediate = $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

		// Deactivate old intermediate.
		$oldIntermediate->setIsActive(false);
		$oldIntermediate->setSuccessorId($newIntermediate->getId());

		if ($forced === true) {
			$oldIntermediate->setRevokedAt(new DateTime());
		}

		$this->caCertificateMapper->update($oldIntermediate);

		// Re-sign all active suites in batches.
		$resignedCount = $this->issuanceService->resignAllActiveSuites();

		$this->logger->info(
			"Doriath: Intermediate renewed, {$resignedCount} suites re-signed",
			[
				'forced' => $forced,
			]
		);

		return $resignedCount;
	}//end renewIntermediate()

	/**
	 * Renew the root certificate and generate a new intermediate.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export / openssl_pkey_export
	 *   populate $rootCertPem / $rootKeyPem via by-reference output params — PHPMD cannot
	 *   trace by-ref semantics.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewRoot(): int {
		$oldRoot = $this->caCertificateMapper->findRoot();

		// Generate new root key pair.
		$rootKey = openssl_pkey_new(
			options: [
				'private_key_bits' => 4096,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$rootCsr = openssl_csr_new(
			distinguished_names: array_merge(CertificateIssuanceService::DEFAULT_DN, ['commonName' => 'Doriath Root CA']),
			private_key: $rootKey,
			options: ['digest_alg' => 'sha256']
		);
		$rootCert = openssl_csr_sign(
			csr: $rootCsr,
			ca_certificate: null,
			private_key: $rootKey,
			days: self::ROOT_LIFETIME_DAYS,
			options: ['digest_alg' => 'sha256'],
			serial: random_int(1, PHP_INT_MAX)
		);

		openssl_x509_export(certificate: $rootCert, output: $rootCertPem);
		openssl_pkey_export(key: $rootKey, output: $rootKeyPem);

		// Store new root.
		$newRoot = new CACertificate();
		$newRoot->setId(Uuid::uuid4()->toString());
		$newRoot->setType('root');
		$newRoot->setCertificate($rootCertPem);
		$newRoot->setPrivateKey($this->crypto->encrypt($rootKeyPem));
		$newRoot->setCreatedAt(new DateTime());
		$newRoot->setExpiresAt(new DateTime('+' . self::ROOT_LIFETIME_DAYS . ' days'));
		$newRoot->setIsActive(false);
		$this->caCertificateMapper->insert($newRoot);

		// Link old root to successor.
		$oldRoot->setSuccessorId($newRoot->getId());
		$this->caCertificateMapper->update($oldRoot);

		// Deactivate old intermediate and create new one.
		try {
			$oldIntermediate = $this->caCertificateMapper->findActiveIntermediate();
			$oldIntermediate->setIsActive(false);
			$oldIntermediate->setSuccessorId(null);
			$this->caCertificateMapper->update($oldIntermediate);
		} catch (DoesNotExistException) {
			// No active intermediate — bootstrap was incomplete.
		}

		$newIntermediate = $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

		// Link old intermediate to new one.
		if (isset($oldIntermediate) === true) {
			$oldIntermediate->setSuccessorId($newIntermediate->getId());
			$this->caCertificateMapper->update($oldIntermediate);
		}

		$resignedCount = $this->issuanceService->resignAllActiveSuites();

		$this->logger->info("Doriath: Root renewed, {$resignedCount} suites re-signed");

		return $resignedCount;
	}//end renewRoot()

	/**
	 * Get the current CA status.
	 *
	 * The `issued` key is present only on the configured path — the
	 * `not_configured` early returns have no CA to count against.
	 *
	 * @return array{status: string, root: ?array, intermediate: ?array, issued?: array<string,int>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function getStatus(): array {
		return $this->statusService->getStatus();
	}//end getStatus()

	/**
	 * Issue the intermediate certificate against an existing persisted root.
	 *
	 * Used by partial-state recovery in {@see self::bootstrap()} when a previous
	 * bootstrap attempt persisted the root but failed to persist the intermediate
	 * (the pre-#40 Postgres SMALLINT/boolean mismatch). The root row is reused
	 * as-is — only the intermediate is generated and persisted.
	 *
	 * @param CACertificate $root The existing root certificate entity
	 *
	 * @return CACertificate The newly created intermediate
	 */
	private function recoverIntermediate(CACertificate $root): CACertificate {
		$rootKey = openssl_pkey_get_private(
			private_key: $this->crypto->decrypt($root->getPrivateKey())
		);

		// @codeCoverageIgnoreStart
		if ($rootKey === false) {
			$this->setDegraded();
			throw new RuntimeException('Failed to load persisted root CA private key: ' . openssl_error_string());
		}

		// @codeCoverageIgnoreEnd
		return $this->generateIntermediate(rootKey: $rootKey, rootCert: $root->getCertificate());
	}//end recoverIntermediate()

	/**
	 * Generate a new intermediate certificate signed by the given root.
	 *
	 * @param \OpenSSLAsymmetricKey $rootKey Root private key
	 * @param \OpenSSLCertificate|string $rootCert Root certificate
	 *
	 * @return CACertificate
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export / openssl_pkey_export
	 *   populate $intCertPem / $intKeyPem via by-reference output params — PHPMD cannot
	 *   trace by-ref semantics.
	 */
	private function generateIntermediate($rootKey, $rootCert): CACertificate {
		$intKey = openssl_pkey_new(
			options: [
				'private_key_bits' => 4096,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);

		$intCsr = openssl_csr_new(
			distinguished_names: array_merge(
				CertificateIssuanceService::DEFAULT_DN,
				['commonName' => 'Doriath Intermediate CA']
			),
			private_key: $intKey,
			options: ['digest_alg' => 'sha256']
		);

		$intCert = openssl_csr_sign(
			csr: $intCsr,
			ca_certificate: $rootCert,
			private_key: $rootKey,
			days: self::INTERMEDIATE_LIFETIME_DAYS,
			options: ['digest_alg' => 'sha256'],
			serial: random_int(1, PHP_INT_MAX)
		);

		// @codeCoverageIgnoreStart
		if ($intCert === false) {
			$this->setDegraded();
			throw new RuntimeException('Failed to sign intermediate CA: ' . openssl_error_string());
		}

		// @codeCoverageIgnoreEnd
		openssl_x509_export(certificate: $intCert, output: $intCertPem);
		openssl_pkey_export(key: $intKey, output: $intKeyPem);

		$entity = new CACertificate();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setType('intermediate');
		$entity->setCertificate($intCertPem);
		$entity->setPrivateKey($this->crypto->encrypt($intKeyPem));
		$entity->setCreatedAt(new DateTime());
		$entity->setExpiresAt(new DateTime('+' . self::INTERMEDIATE_LIFETIME_DAYS . ' days'));
		$entity->setIsActive(true);

		$this->caCertificateMapper->insert($entity);

		return $entity;
	}//end generateIntermediate()

	/**
	 * Set the CA status to degraded.
	 *
	 * @return void
	 *
	 * @codeCoverageIgnore
	 */
	private function setDegraded(): void {
		$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'degraded');
		$this->logger->error('Doriath: CA bootstrap failed, entering degraded state');
	}//end setDegraded()
}//end class
