<?php

/**
 * Contract of DeviceApprovalService
 *
 * The public surface of DeviceApprovalService that other components call, so they depend
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
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\DeviceApprovalRequest;

/**
 * Contract of DeviceApprovalService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeviceApprovalServiceInterface {

	/**
	 * Approve one of the user's open requests with the unlock key sealed to
	 * its one-time key. The vault-key proof is checked before this runs
	 * (VaultKeyProofRequired on the route).
	 *
	 * @param string $id              The request
	 * @param string $userId          The user
	 * @param string $sealedUnlockKey The HPKE-sealed unlock key
	 *
	 * @return void
	 *
	 * @throws NotFoundException        When the request is not an open request of this user
	 * @throws InvalidArgumentException When no sealed key is given
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-approval-seals-the-unlock-key-and-needs-proof-of-the-master-password
	 */
	public function approve(string $id, string $userId, string $sealedUnlockKey): void;

	/**
	 * Store a request from a locked device and notify the user.
	 *
	 * @param DeviceApprovalRequest $request The user, the device key, kind and label, and the caller's address and agent
	 *
	 * @return array{id:string,requestSecret:string,expiresAt:string}
	 *
	 * @throws ForbiddenException       When the feature is off
	 * @throws InvalidArgumentException When the input is malformed
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-a-new-device-requests-approval-with-a-one-time-key
	 */
	public function create(DeviceApprovalRequest $request): array;

	/**
	 * Deny one of the user's open requests.
	 *
	 * @param string $id     The request
	 * @param string $userId The user
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the request is not an open request of this user
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function deny(string $id, string $userId): void;

	/**
	 * Mark lapsed requests expired and drop any sealed key never picked up.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return int The number expired
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function expireLapsed(DateTime $now): int;

	/**
	 * Whether an administrator left device approval on.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-deny-expiry-audit-and-administrator-switch
	 */
	public function isEnabled(): bool;

	/**
	 * The user's open requests, for the approving device.
	 *
	 * @param string $userId The user
	 *
	 * @return DeviceApproval[]
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-both-devices-show-the-same-verification-phrase
	 */
	public function pending(string $userId): array;

	/**
	 * The requesting device's poll. Needs the request secret. Returns the
	 * sealed key once, then clears it and marks the request consumed.
	 *
	 * @param string $id            The request
	 * @param string $userId        The user
	 * @param string $requestSecret The secret returned at creation
	 *
	 * @return array{status:string,sealedUnlockKey?:string}
	 *
	 * @throws NotFoundException When the request is unknown, foreign, or the secret is wrong
	 *
	 * @spec openspec/specs/new-device-approval/spec.md#requirement-pickup-is-one-time-and-unlocks-one-session
	 */
	public function pickup(string $id, string $userId, string $requestSecret): array;
}//end interface
