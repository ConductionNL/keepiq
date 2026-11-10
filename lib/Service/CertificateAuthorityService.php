<?php

/**
 * Keepiq Certificate Authority Service
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
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
	 * The openssl_csr_sign() options for the root: a CA, whatever the host
	 * openssl.cnf says.
	 *
	 * @var array<string,string>
	 */
	private const ROOT_SIGN_OPTIONS = [
		'digest_alg' => 'sha256',
		'config' => CertificateIssuanceService::OPENSSL_CONFIG,
		'x509_extensions' => 'keepiq_root',
	];

	/**
	 * The openssl_csr_sign() options for an intermediate: a CA that signs
	 * only user and application certificates (pathlen 0).
	 *
	 * @var array<string,string>
	 */
	private const INTERMEDIATE_SIGN_OPTIONS = [
		'digest_alg' => 'sha256',
		'config' => CertificateIssuanceService::OPENSSL_CONFIG,
		'x509_extensions' => 'keepiq_intermediate',
	];

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
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function bootstrap(): void {
		$root = $this->findRootOrNull();
		if ($root !== null) {
			$this->ensureIntermediate(root: $root);
			return;
		}

		$this->bootstrapFresh();
	}//end bootstrap()

	/**
	 * The persisted root, or null when the CA was never bootstrapped.
	 *
	 * @return ?CACertificate
	 */
	private function findRootOrNull(): ?CACertificate {
		try {
			return $this->caCertificateMapper->findRoot();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end findRootOrNull()

	/**
	 * With a root in place: skip when an active intermediate exists, else issue
	 * only the missing intermediate against the existing root.
	 *
	 * @param CACertificate $root The persisted root
	 *
	 * @return void
	 */
	private function ensureIntermediate(CACertificate $root): void {
		try {
			$this->caCertificateMapper->findActiveIntermediate();
			$this->logger->info('Keepiq: CA already bootstrapped, skipping');
			// Healthy state — make sure ca_status reflects it in case a prior
			// partial-state install left it unset or degraded.
			$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
		} catch (DoesNotExistException) {
			// Partial state: root exists but intermediate does not.
			// Recover by issuing only the missing intermediate against the existing root.
			$this->logger->warning(
				'Keepiq: detected partial CA state (root present, no active intermediate) — recovering intermediate'
			);
			$recovered = $this->recoverIntermediate(root: $root);
			$this->logger->info('Keepiq: CA partial-state recovery: intermediate issued', ['id' => $recovered->getId()]);
			$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
			$this->logger->info('Keepiq: CA partial-state recovery complete');
		}//end try
	}//end ensureIntermediate()

	/**
	 * The full bootstrap: a new self-signed root, stored with its encrypted
	 * key, and an intermediate under it.
	 *
	 * @return void
	 */
	private function bootstrapFresh(): void {
		$this->logger->info('Keepiq: Bootstrapping Certificate Authority');

		$rootKey = $this->newRsaKey();
		$this->failUnless(succeeded: $rootKey !== false, message: 'Failed to generate root CA key: ');

		$rootCert = $this->signRoot(rootKey: $rootKey);
		$this->failUnless(succeeded: $rootCert !== false, message: 'Failed to sign root CA certificate: ');

		// Store root certificate with encrypted private key.
		$this->insertCa(type: 'root', pems: $this->exportPems(key: $rootKey, certificate: $rootCert));

		// Generate and sign the intermediate certificate.
		$this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

		$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'healthy');
		$this->logger->info('Keepiq: CA bootstrap complete');
	}//end bootstrapFresh()

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
		string $commonName = 'Keepiq User',
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
	 * Renew the intermediate certificate on schedule.
	 *
	 * The old intermediate is deactivated but NOT revoked: certificates it
	 * signed stay valid until they are re-signed. Called by the daily
	 * RenewIntermediateCertificate job.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewIntermediate(): int {
		return $this->rollIntermediate(revokeOldAt: null);
	}//end renewIntermediate()

	/**
	 * Renew the intermediate certificate and revoke the old one immediately.
	 *
	 * The compromise path: the same rollover as renewIntermediate(), plus a
	 * revokedAt on the old intermediate. Called by the admin force-renew
	 * endpoint.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewIntermediateRevokingOld(): int {
		return $this->rollIntermediate(revokeOldAt: new DateTime());
	}//end renewIntermediateRevokingOld()

	/**
	 * Generate a new intermediate, deactivate the old one, and re-sign all
	 * active suites.
	 *
	 * @param DateTime|null $revokeOldAt Revocation time to stamp on the old
	 *                                   intermediate, or null to leave it unrevoked
	 *
	 * @return int Number of suites re-signed.
	 */
	private function rollIntermediate(?DateTime $revokeOldAt): int {
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

		if ($revokeOldAt !== null) {
			$oldIntermediate->setRevokedAt($revokeOldAt);
		}

		$this->caCertificateMapper->update($oldIntermediate);

		// Re-sign all active suites in batches.
		$resignedCount = $this->issuanceService->resignAllActiveSuites();

		$this->logger->info(
			"Keepiq: Intermediate renewed, {$resignedCount} suites re-signed",
			[
				'forced' => $revokeOldAt !== null,
			]
		);

		return $resignedCount;
	}//end rollIntermediate()

	/**
	 * Renew the root certificate and generate a new intermediate.
	 *
	 * @return int Number of suites re-signed.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-1
	 */
	public function renewRoot(): int {
		$oldRoot = $this->caCertificateMapper->findRoot();

		$rootKey  = $this->newRsaKey();
		$rootCert = $this->signRoot(rootKey: $rootKey);

		// Store new root.
		$newRoot = $this->insertCa(type: 'root', pems: $this->exportPems(key: $rootKey, certificate: $rootCert));

		// Link old root to successor.
		$oldRoot->setSuccessorId($newRoot->getId());
		$this->caCertificateMapper->update($oldRoot);

		// Deactivate old intermediate and create new one.
		$oldIntermediate = $this->deactivateIntermediate();
		$newIntermediate = $this->generateIntermediate(rootKey: $rootKey, rootCert: $rootCert);

		// Link old intermediate to new one.
		if ($oldIntermediate !== null) {
			$oldIntermediate->setSuccessorId($newIntermediate->getId());
			$this->caCertificateMapper->update($oldIntermediate);
		}

		$resignedCount = $this->issuanceService->resignAllActiveSuites();

		$this->logger->info("Keepiq: Root renewed, {$resignedCount} suites re-signed");

		return $resignedCount;
	}//end renewRoot()

	/**
	 * Deactivate the active intermediate, if there is one.
	 *
	 * @return ?CACertificate The deactivated intermediate, or null when there was none
	 */
	private function deactivateIntermediate(): ?CACertificate {
		$oldIntermediate = null;
		try {
			$oldIntermediate = $this->caCertificateMapper->findActiveIntermediate();
			$oldIntermediate->setIsActive(false);
			$oldIntermediate->setSuccessorId(null);
			$this->caCertificateMapper->update($oldIntermediate);
		} catch (DoesNotExistException) {
			// No active intermediate — bootstrap was incomplete.
		}

		return $oldIntermediate;
	}//end deactivateIntermediate()

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
		$this->failUnless(succeeded: $rootKey !== false, message: 'Failed to load persisted root CA private key: ');

		return $this->generateIntermediate(rootKey: $rootKey, rootCert: $root->getCertificate());
	}//end recoverIntermediate()

	/**
	 * Generate a new intermediate certificate signed by the given root.
	 *
	 * @param \OpenSSLAsymmetricKey $rootKey Root private key
	 * @param \OpenSSLCertificate|string $rootCert Root certificate
	 *
	 * @return CACertificate
	 */
	private function generateIntermediate($rootKey, $rootCert): CACertificate {
		$intKey = $this->newRsaKey();

		$intCert = openssl_csr_sign(
			csr: $this->intermediateCsr(intKey: $intKey),
			ca_certificate: $rootCert,
			private_key: $rootKey,
			days: self::INTERMEDIATE_LIFETIME_DAYS,
			options: self::INTERMEDIATE_SIGN_OPTIONS,
			serial: random_int(1, PHP_INT_MAX)
		);
		$this->failUnless(succeeded: $intCert !== false, message: 'Failed to sign intermediate CA: ');

		return $this->insertCa(type: 'intermediate', pems: $this->exportPems(key: $intKey, certificate: $intCert));
	}//end generateIntermediate()

	/**
	 * A new 4096-bit RSA key pair, for a root or an intermediate.
	 *
	 * @return mixed The key pair (an OpenSSLAsymmetricKey), or false when OpenSSL failed
	 */
	private function newRsaKey() {
		return openssl_pkey_new(
			options: [
				'private_key_bits' => 4096,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			]
		);
	}//end newRsaKey()

	/**
	 * The root certificate, self-signed with its own key.
	 *
	 * @param \OpenSSLAsymmetricKey|bool $rootKey Root private key
	 *
	 * @return mixed The certificate (an OpenSSLCertificate), or false when OpenSSL failed
	 */
	private function signRoot($rootKey) {
		$rootCsr = openssl_csr_new(
			distinguished_names: array_merge(CertificateIssuanceService::DEFAULT_DN, ['commonName' => 'Keepiq Root CA']),
			private_key: $rootKey,
			options: ['digest_alg' => 'sha256']
		);

		return openssl_csr_sign(
			csr: $rootCsr,
			ca_certificate: null,
			private_key: $rootKey,
			days: self::ROOT_LIFETIME_DAYS,
			options: self::ROOT_SIGN_OPTIONS,
			serial: random_int(1, PHP_INT_MAX)
		);
	}//end signRoot()

	/**
	 * The signing request for an intermediate key.
	 *
	 * @param \OpenSSLAsymmetricKey|bool $intKey Intermediate private key
	 *
	 * @return mixed The signing request, or false when OpenSSL failed
	 */
	private function intermediateCsr($intKey) {
		return openssl_csr_new(
			distinguished_names: array_merge(
				CertificateIssuanceService::DEFAULT_DN,
				['commonName' => 'Keepiq Intermediate CA']
			),
			private_key: $intKey,
			options: ['digest_alg' => 'sha256']
		);
	}//end intermediateCsr()

	/**
	 * The certificate and its private key, PEM-encoded.
	 *
	 * @param \OpenSSLAsymmetricKey|bool $key The private key
	 * @param \OpenSSLCertificate|bool $certificate The certificate
	 *
	 * @return array<string,string>
	 *
	 * @SuppressWarnings(PHPMD.UndefinedVariable) openssl_x509_export / openssl_pkey_export
	 *   populate $certPem / $keyPem via by-reference output params — PHPMD cannot
	 *   trace by-ref semantics.
	 */
	private function exportPems($key, $certificate): array {
		openssl_x509_export(certificate: $certificate, output: $certPem);
		openssl_pkey_export(key: $key, output: $keyPem);

		return ['certificate' => $certPem, 'key' => $keyPem];
	}//end exportPems()

	/**
	 * Store a CA certificate with its encrypted private key. A root is stored
	 * inactive and lives ROOT_LIFETIME_DAYS; an intermediate is the active one
	 * and lives INTERMEDIATE_LIFETIME_DAYS.
	 *
	 * @param string $type 'root' or 'intermediate'
	 * @param array<string,string> $pems The PEM-encoded certificate and key
	 *
	 * @return CACertificate
	 */
	private function insertCa(string $type, array $pems): CACertificate {
		$isRoot = $type === 'root';
		$days   = self::INTERMEDIATE_LIFETIME_DAYS;
		if ($isRoot === true) {
			$days = self::ROOT_LIFETIME_DAYS;
		}

		$entity = new CACertificate();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setType($type);
		$entity->setCertificate($pems['certificate']);
		$entity->setPrivateKey($this->crypto->encrypt($pems['key']));
		$entity->setCreatedAt(new DateTime());
		$entity->setExpiresAt(new DateTime('+' . $days . ' days'));
		$entity->setIsActive($isRoot === false);
		$this->caCertificateMapper->insert($entity);

		return $entity;
	}//end insertCa()

	/**
	 * Enter the degraded state and throw when an OpenSSL step failed.
	 *
	 * @param bool $succeeded Whether the step succeeded
	 * @param string $message The failure, to which OpenSSL's own error is appended
	 *
	 * @return void
	 *
	 * @codeCoverageIgnore
	 */
	private function failUnless(bool $succeeded, string $message): void {
		if ($succeeded === false) {
			$this->setDegraded();
			throw new RuntimeException($message . openssl_error_string());
		}
	}//end failUnless()

	/**
	 * Set the CA status to degraded.
	 *
	 * @return void
	 *
	 * @codeCoverageIgnore
	 */
	private function setDegraded(): void {
		$this->appConfig->setValueString(Application::APP_ID, 'ca_status', 'degraded');
		$this->logger->error('Keepiq: CA bootstrap failed, entering degraded state');
	}//end setDegraded()
}//end class
