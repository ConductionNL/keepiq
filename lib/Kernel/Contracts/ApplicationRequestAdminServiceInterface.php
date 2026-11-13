<?php

/**
 * Contract of ApplicationRequestAdminService
 *
 * The public surface of ApplicationRequestAdminService that other components call, so they depend
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
use OCA\Keepiq\Db\SecretRequest;

/**
 * Contract of ApplicationRequestAdminService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationRequestAdminServiceInterface {

	/**
	 * Every secret request an application created, for an administrator.
	 *
	 * Admin-scoped rather than registrar-scoped on purpose. An application's vault
	 * belongs to no single user, registration is a historical act rather than a
	 * continuing responsibility, and the registrar may have left the organisation.
	 * Tying audit visibility to it would make "who can see this" depend on who
	 * happened to click Register months ago.
	 *
	 * Every status is returned, not only pending: "what has this application been
	 * asking people for" is the audit question, and a list of only what is
	 * outstanding answers a narrower one.
	 *
	 * The caller passes its own authorization answer, matching how the other admin
	 * paths in this app work — the controller resolves `isAdmin` from the group
	 * manager, and the refusal is enforced HERE as well, so an endpoint cannot
	 * expose the listing by carrying the wrong annotation.
	 *
	 * @param string $applicationId The application whose requests to list
	 * @param bool $isAdmin Whether the caller is an administrator
	 *
	 * @return array<int,SecretRequest> Newest first
	 *
	 * @throws InvalidArgumentException 403 when the caller is not an administrator
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function listForApplication(string $applicationId, bool $isAdmin): array;

	/**
	 * Revoke an application's secret request as an administrator.
	 *
	 * The point of the whole change: a pending fill link is a bearer credential in
	 * a URL, and it needs an off switch that does not depend on the application
	 * cooperating. `SecretRequestService::decline()` cannot serve here — it asserts
	 * the caller CREATED the request, and an administrator never did.
	 *
	 * Scoping is structural rather than trusted: the request must actually be this
	 * application's. Without that check, an endpoint addressed by request id would
	 * revoke ANY request — including a user's own — through an application route.
	 *
	 * @param string $requestId The request to revoke
	 * @param string $applicationId The application it must belong to
	 * @param string $adminUserId The administrator performing the revoke
	 * @param bool $isAdmin Whether the caller is an administrator
	 *
	 * @return SecretRequest The revoked request
	 *
	 * @throws InvalidArgumentException 403 not an admin, 404 not that application's, 400 not pending
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function revokeForApplication(
		string $requestId,
		string $applicationId,
		string $adminUserId,
		bool $isAdmin,
	): SecretRequest;
}//end interface
