<?php

/**
 * Contract of ApplicationService
 *
 * The public surface of ApplicationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Keepiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Db\Application;

/**
 * Contract of ApplicationService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationServiceInterface {

	/**
	 * Approve a pending application.
	 *
	 * @param string $applicationId The application ID
	 * @param string $adminUserId The approving admin's Nextcloud user ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.4
	 */
	public function approve(string $applicationId, string $adminUserId, bool $isAdmin): Application;

	/**
	 * Delete an application. Admin-only. Its secrets, secret requests and
	 * encryption suite go first, in one transaction (keepiq#753), so a
	 * failure leaves the application in place for a retry rather than
	 * orphaned rows nobody can see; then the row, its leases and the audit
	 * entry.
	 *
	 * @param string $applicationId The application ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-delete-application
	 */
	public function delete(string $applicationId, bool $isAdmin): void;

	/**
	 * Get a single application. Admin sees any; non-admin only sees apps
	 * they registered or active apps (a baseline RBAC check the
	 * controller forwards).
	 *
	 * @param string $applicationId The application ID
	 * @param string $userId The current Nextcloud user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
	 */
	public function get(string $applicationId, string $userId, bool $isAdmin): Application;

	/**
	 * Get the active EncryptionSuite certificate for an application.
	 *
	 * Used by the write-secret-for-app flow: the caller fetches the
	 * application's public certificate, encrypts the secret with the
	 * embedded public key client-side, and POSTs the encrypted blob to
	 * the secrets API. Active applications without a suite return null
	 * (admin must re-approve to provision).
	 *
	 * @param string $applicationId The application ID
	 *
	 * @return string|null The PEM-encoded certificate or null when no suite exists.
	 *
	 * @throws InvalidArgumentException When the application is not active.
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
	 */
	public function getCertificate(string $applicationId): ?string;

	/**
	 * List applications. Admin sees all; non-admin sees own registrations
	 * + active applications.
	 *
	 * @param string $userId The current Nextcloud user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application[]
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-register-application
	 */
	public function listForUser(string $userId, bool $isAdmin): array;

	/**
	 * List pending applications. Admin-only.
	 *
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application[]
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-approval-queue
	 */
	public function listPending(bool $isAdmin): array;

	/**
	 * Register a new application.
	 *
	 * Admin callers auto-approve (status=active); non-admin and anonymous
	 * callers create a pending row that an admin must approve. The CSR
	 * is stored verbatim on pending rows and validated/processed when the
	 * full build cycle ships EncryptionSuite provisioning.
	 *
	 * @param string $name The human-readable name
	 * @param string|null $description Optional free-form description
	 * @param string $type Application type (internal|external)
	 * @param string|null $csr Optional PKCS#10 CSR (stored on pending rows)
	 * @param string|null $userId The Nextcloud user creating the row
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return Application
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-3.2
	 */
	public function register(
		string $name,
		?string $description,
		string $type,
		?string $csr,
		?string $userId,
		bool $isAdmin,
	): Application;

	/**
	 * Reject a pending application — hard-deletes the row per spec D7.
	 *
	 * @param string $applicationId The application ID
	 * @param string $adminUserId The rejecting admin's Nextcloud user ID
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.7
	 */
	public function reject(string $applicationId, string $adminUserId, bool $isAdmin): void;
}//end interface
