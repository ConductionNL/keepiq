<?php

/**
 * Keepiq Application Suite Provisioner
 *
 * The EncryptionSuite half of the registered-application lifecycle
 * (implement-application-mgmt §9.1/§9.4): provisioning a suite from a
 * PKCS#10 CSR when an application becomes active, and reading back the
 * active suite's public certificate.
 *
 * Both operations are deliberately fail-soft. A suite that cannot be
 * provisioned leaves the application active but suite-less — recoverable
 * by re-approval — and must never roll back the registration or approval
 * transaction that triggered it.
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

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provisions and reads the EncryptionSuite of a registered application.
 */
class ApplicationSuiteProvisioner {
	/**
	 * Constructor for ApplicationSuiteProvisioner.
	 *
	 * @param LoggerInterface $logger The logger
	 * @param EncryptionSuiteService|null $suiteService The encryption suite service
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the provisioning behaviour carries the spec anchors.
	 */
	public function __construct(
		private LoggerInterface $logger,
		private ?EncryptionSuiteService $suiteService = null,
	) {
	}//end __construct()

	/**
	 * Provision an EncryptionSuite for an application. Failures are
	 * logged + swallowed — a missing suite is recoverable via re-approval
	 * and must never roll back the approval transaction.
	 *
	 * @param string $applicationId The newly active application's ID
	 * @param string $csr The PEM-encoded PKCS#10 CSR
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.1
	 */
	public function provision(string $applicationId, string $csr): void {
		if ($this->suiteService === null) {
			return;
		}

		try {
			$this->suiteService->provisionForApplication(
				applicationId: $applicationId,
				csrPem: $csr,
			);
			$this->logger->info(
				'Provisioned EncryptionSuite for application ' . $applicationId,
				['app' => 'keepiq']
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Failed to provision EncryptionSuite for application '
				. $applicationId . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end provision()

	/**
	 * The active EncryptionSuite certificate of an application, or null when
	 * no suite exists (or no suite service is wired). Public key material
	 * only — the caller encrypts against it client-side (ADR-003).
	 *
	 * @param string $applicationId The application ID
	 *
	 * @return string|null The PEM-encoded certificate, or null when absent.
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
	 */
	public function activeCertificate(string $applicationId): ?string {
		if ($this->suiteService === null) {
			return null;
		}

		try {
			return $this->suiteService->getActiveSuite('application', $applicationId)->getCertificate();
		} catch (Throwable) {
			return null;
		}
	}//end activeCertificate()
}//end class
