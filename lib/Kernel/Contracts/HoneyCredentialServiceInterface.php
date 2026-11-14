<?php

/**
 * Contract of HoneyCredentialService
 *
 * The public surface of HoneyCredentialService that other components call, so they depend
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
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyFlag;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of HoneyCredentialService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HoneyCredentialServiceInterface {

	/**
	 * Acknowledge an alert (owner of the decoy or admin).
	 *
	 * @param string $alertId The alert UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyAlert
	 *
	 * @throws DoesNotExistException When the alert is missing
	 * @throws InvalidArgumentException When the caller may not act on it
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
	 */
	public function acknowledge(string $alertId, string $actorId, bool $isAdmin): HoneyAlert;

	/**
	 * Flag a secret as a decoy (owner or admin, §2.1). Upsert — a
	 * repeated flag call updates the note.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 * @param string|null $note Optional placement note
	 *
	 * @return HoneyFlag
	 *
	 * @throws DoesNotExistException When the secret does not exist
	 * @throws InvalidArgumentException When the caller may not flag it
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
	 */
	public function flag(string $secretId, string $actorId, bool $isAdmin, ?string $note = null): HoneyFlag;

	/**
	 * The flag of a secret for the owner/admin detail view, or null.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyFlag|null
	 *
	 * @throws InvalidArgumentException When the caller may not see it
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
	 */
	public function getFlag(string $secretId, string $actorId, bool $isAdmin): ?HoneyFlag;

	/**
	 * List alerts: owner sees own decoys' alerts; admins instance-wide.
	 *
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return HoneyAlert[]
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
	 */
	public function listAlerts(string $actorId, bool $isAdmin): array;

	/**
	 * Snooze future paging for the alert's accessor (owner or admin).
	 * A snoozed accessor stops paging but every access is still audited.
	 *
	 * @param string $alertId The alert UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 * @param int $hours Snooze duration in hours
	 *
	 * @return HoneyAlert
	 *
	 * @throws DoesNotExistException When the alert is missing
	 * @throws InvalidArgumentException When the caller may not act on it
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
	 */
	public function snooze(string $alertId, string $actorId, bool $isAdmin, int $hours = 24): HoneyAlert;

	/**
	 * Remove the decoy flag (owner or admin). Alert rows are KEPT — the
	 * forensic trail survives unflagging.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $actorId The calling user
	 * @param bool $isAdmin Whether the caller is an admin
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the secret is not flagged
	 * @throws InvalidArgumentException When the caller may not unflag it
	 *
	 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
	 */
	public function unflag(string $secretId, string $actorId, bool $isAdmin): void;
}//end interface
