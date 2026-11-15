<?php

/**
 * Contract of FederationRootService
 *
 * The public surface of FederationRootService that other components call, so they depend
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

/**
 * Contract of FederationRootService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederationRootServiceInterface {

	/**
	 * Whether this Nextcloud can federate: the OCM endpoint event arrived in 33.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function isSupported(): bool;

	/**
	 * The fingerprint of this instance's Keepiq root, or null before the CA exists.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function localRootFingerprint(): ?string;
}//end interface
