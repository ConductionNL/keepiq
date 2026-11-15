<?php

/**
 * Contract of RecoveryAudit
 *
 * The public surface of RecoveryAudit that other components call, so they depend
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

use OCA\Keepiq\Event\Audit\AuditEventTypes;

/**
 * Contract of RecoveryAudit.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecoveryAuditInterface {

	public const SETTINGS_CHANGED = AuditEventTypes::RECOVERY_SETTINGS_CHANGED;

	/**
	 * Record one recovery step.
	 *
	 * @param string              $actorId   Who acted
	 * @param string              $eventType One of the constants above
	 * @param string              $objectId  The key, enrolment or request id
	 * @param array<string,mixed> $metadata  Whitelisted identifiers
	 *
	 * @return void
	 *
	 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-the-user-is-told-what-happened-and-offered-a-rotation
	 */
	public function record(string $actorId, string $eventType, string $objectId, array $metadata = []): void;
}//end interface
