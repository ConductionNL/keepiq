<?php

/**
 * Contract of FederatedRemoteChangeService
 *
 * The public surface of FederatedRemoteChangeService that other components call, so they depend
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

use OCP\Share\Exceptions\ShareNotFound;

/**
 * Contract of FederatedRemoteChangeService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedRemoteChangeServiceInterface {

	/**
	 * Handle one notification from the sending instance.
	 *
	 * @param string $type `SHARE_UPDATED` or `SHARE_UNSHARED`; others are acknowledged and ignored
	 * @param string $providerId The share id on the sender
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (its SHA-256)
	 *
	 * @return array<array-key,string>
	 *
	 * @throws ShareNotFound For every refusal
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function handle(string $type, string $providerId, array $notification): array;

	/**
	 * The sender of the share a notification's shared secret hash belongs to,
	 * or '' for none. Nextcloud 35 needs it to verify the signature
	 * (ISignedCloudFederationProvider::getFederationIdFromSharedSecret()).
	 *
	 * @param string $presented The hash the sender presented
	 * @param array<array-key,mixed> $notification The payload, with `providerId`
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function senderOf(string $presented, array $notification): string;
}//end interface
