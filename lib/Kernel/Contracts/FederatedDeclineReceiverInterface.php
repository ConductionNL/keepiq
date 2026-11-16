<?php

/**
 * Contract of FederatedDeclineReceiver
 *
 * The public surface of FederatedDeclineReceiver that other components call, so they depend
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
 * Contract of FederatedDeclineReceiver.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedDeclineReceiverInterface {

	/**
	 * Apply a `SHARE_DECLINED` from the recipient's instance.
	 *
	 * @param string $providerId The share id here
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (the secret itself)
	 *
	 * @return array<array-key,string>
	 *
	 * @throws ShareNotFound For every refusal
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-server-cannot-end-a-suspension
	 */
	public function handle(string $providerId, array $notification): array;

	/**
	 * The recipient of the share a presented shared secret belongs to, or ''
	 * for none. Nextcloud 35 verifies the decline's signature against it.
	 *
	 * @param string $presented The secret the recipient's instance presented
	 * @param array<array-key,mixed> $notification The payload, with `providerId`
	 *
	 * @return string
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
	 */
	public function recipientOf(string $presented, array $notification): string;

	/**
	 * Apply a `SHARE_ACCEPTED` from the recipient's instance: a declined
	 * share whose copy the recipient restored becomes live again. One that
	 * is already live stays so.
	 *
	 * @param string $providerId The share id here
	 * @param array<array-key,mixed> $notification The payload, `{sharedSecret}` (the secret itself)
	 *
	 * @return array<array-key,string>
	 *
	 * @throws ShareNotFound For every refusal, and for a share that ended
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-the-owner-revoked-the-share-meanwhile
	 */
	public function resume(string $providerId, array $notification): array;
}//end interface
