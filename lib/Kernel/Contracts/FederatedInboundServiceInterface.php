<?php

/**
 * Contract of FederatedInboundService
 *
 * The public surface of FederatedInboundService that other components call, so they depend
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

use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of FederatedInboundService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedInboundServiceInterface {

	/**
	 * Accept a pending share: pull the ciphertext and store the read-only copy.
	 *
	 * @param string $id The inbound share
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound
	 *
	 * @throws NotFoundException When it is not the user's pending share
	 * @throws \RuntimeException `pull_failed` or `no_suite`
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function accept(string $id, string $userId): FederatedInbound;

	/**
	 * Decline a pending share. Nothing is pulled, and the owner's instance
	 * gets OCM `SHARE_DECLINED`, as when an accepted copy is deleted. A
	 * decline that does not arrive goes again at the owner's next change.
	 *
	 * @param string $id The inbound share
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound
	 *
	 * @throws NotFoundException When it is not the user's pending share
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-declines-a-pending-share
	 */
	public function decline(string $id, string $userId): FederatedInbound;

	/**
	 * The user's inbound shares, newest first.
	 *
	 * @param string $userId The recipient
	 *
	 * @return FederatedInbound[]
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
	 */
	public function listFor(string $userId): array;
}//end interface
