<?php

/**
 * Contract of FederatedShareReceiver
 *
 * The public surface of FederatedShareReceiver that other components call, so they depend
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

use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationShare;

/**
 * Contract of FederatedShareReceiver.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedShareReceiverInterface {

	/**
	 * Store an announced share as pending and notify the recipient.
	 *
	 * @param ICloudFederationShare $share The OCM share; shareWith is the local uid
	 *
	 * @return string The inbound share id
	 *
	 * @throws ProviderCouldNotAddShareException When the share is refused
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-non-partner-cannot-deliver
	 */
	public function receive(ICloudFederationShare $share): string;
}//end interface
