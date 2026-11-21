<?php

/**
 * Contract of MachineSecretResponseService
 *
 * The public surface of MachineSecretResponseService that other components call, so they depend
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

use OCA\Keepiq\Db\Secret;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Contract of MachineSecretResponseService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MachineSecretResponseServiceInterface {

	/**
	 * Build the envelope response for a single secret, with lease policy,
	 * ETag / 304 handling and an `application.secret_retrieved` audit event
	 * (on a full read only — a 304 dispatches nothing).
	 *
	 * @param Secret $secret The secret
	 * @param string $applicationId The calling application id (audit actor)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function envelope(Secret $secret, string $applicationId): JSONResponse;
}//end interface
