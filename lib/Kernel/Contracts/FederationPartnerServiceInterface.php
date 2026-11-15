<?php

/**
 * Contract of FederationPartnerService
 *
 * The public surface of FederationPartnerService that other components call, so they depend
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
use OCA\Keepiq\Db\FederationPartner;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of FederationPartnerService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederationPartnerServiceInterface {

	/**
	 * Add a partner. The fingerprint the administrator confirmed must still be
	 * the one the partner publishes, so a change between preview and save is
	 * caught.
	 *
	 * @param string $url The partner address
	 * @param string $confirmedFingerprint The fingerprint the administrator compared
	 * @param bool $allowOutbound Whether users here may share to the partner
	 * @param bool $allowInbound Whether the partner may look up and deliver here
	 * @param string $adminId The administrator
	 *
	 * @return FederationPartner
	 *
	 * @throws InvalidArgumentException When the partner is invalid, known, or its fingerprint changed
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function add(
		string $url,
		string $confirmedFingerprint,
		bool $allowOutbound,
		bool $allowInbound,
		string $adminId,
	): FederationPartner;

	/**
	 * Every partner.
	 *
	 * @return FederationPartner[]
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function all(): array;

	/**
	 * Whether any partner exists; with none, federation is off.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function hasAny(): bool;

	/**
	 * Read a partner's discovery document and its federation block.
	 *
	 * @param string $url The partner address
	 *
	 * @return array{baseUrl:string,host:string,rootFingerprint:string}
	 *
	 * @throws InvalidArgumentException When the partner cannot federate
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function preview(string $url): array;

	/**
	 * Remove a partner.
	 *
	 * @param string $id The partner UUID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function remove(string $id): void;

	/**
	 * Change a partner's directions.
	 *
	 * @param string $id The partner UUID
	 * @param bool $allowOutbound Whether users here may share to the partner
	 * @param bool $allowInbound Whether the partner may look up and deliver here
	 *
	 * @return FederationPartner
	 *
	 * @throws DoesNotExistException When the partner does not exist
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function update(string $id, bool $allowOutbound, bool $allowInbound): FederationPartner;
}//end interface
