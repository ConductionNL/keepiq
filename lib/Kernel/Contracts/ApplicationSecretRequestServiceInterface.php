<?php

/**
 * Contract of ApplicationSecretRequestService
 *
 * The public surface of ApplicationSecretRequestService that other components call, so they depend
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\SecretRequest;

/**
 * Contract of ApplicationSecretRequestService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApplicationSecretRequestServiceInterface {

	/**
	 * Create a request in an application's OWN vault, with no user session.
	 *
	 * The session-bound siblings (`create`, `createForApplication`) both require
	 * a `userId`, which an unattended application does not have — that is the
	 * gap this closes. Authority comes from the caller having already proven the
	 * application's identity (JWT-Bearer on the machine route, or a verified
	 * signed proof on the DI seam); this method takes the application id as
	 * established fact and never accepts a user.
	 *
	 * The Secret shell is created here rather than demanded from the caller,
	 * because an application has nothing to point at yet. Shell and request are
	 * created together, and a failure after the shell is written removes it: an
	 * empty orphan Secret in an application's vault is indistinguishable from a
	 * real unfilled credential, so it must not survive a failed creation.
	 *
	 * @param string $applicationId The application whose vault receives the request
	 * @param array<int,string> $requestedFields Field names being asked for
	 * @param string|null $name Optional secret name
	 * @param string|null $folderId Optional folder placement
	 * @param DateTime|null $expiresAt Optional fill-link expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the application has no usable suite
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function createForApplicationVault(
		string $applicationId,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest;

	/**
	 * The pending requests an application itself created.
	 *
	 * Exists so a fill-link is retrievable after creation: the token is returned
	 * once at creation, and an application that lost the response has no other
	 * way back to it.
	 *
	 * Scoping is structural rather than filtered-after-the-fact. Rows are keyed
	 * on `created_by = "application:<id>"`, a value only this service writes —
	 * no request body can influence it, so one application cannot enumerate
	 * another's. Requests a USER created against this application's vault are
	 * deliberately not listed: they are the user's to manage, and surfacing them
	 * here would widen what a machine token can see.
	 *
	 * @param string $applicationId The calling application
	 *
	 * @return array<int,SecretRequest> Pending requests, newest first
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-requests/spec.md#requirement-session-less-application-initiated-request-creation
	 */
	public function listPendingForApplicationVault(string $applicationId): array;
}//end interface
