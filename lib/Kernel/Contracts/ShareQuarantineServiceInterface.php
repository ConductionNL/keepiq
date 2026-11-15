<?php

/**
 * Contract of ShareQuarantineService
 *
 * The public surface of ShareQuarantineService that other components call, so they depend
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;

/**
 * Contract of ShareQuarantineService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareQuarantineServiceInterface {

	/**
	 * Drop a quarantined row for good. Its copy is kept: it may be the
	 * recipient's own secret. With $clearRestrictions, the use-only flag and
	 * access end date a planted row wrote onto that copy are cleared, but only
	 * when the row's recipient holds the copy.
	 *
	 * @param string $id                The share-target row id
	 * @param bool   $clearRestrictions Whether to clear the copy's use-only flag and access end date
	 *
	 * @return bool Whether the copy's restrictions were cleared
	 *
	 * @throws InvalidArgumentException When no quarantined row has this id
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the command's --clear-restrictions option.
	 */
	public function drop(string $id, bool $clearRestrictions = false): bool;

	/**
	 * Every quarantined row with its copy (null when the copy is gone), oldest
	 * quarantine first.
	 *
	 * @return list<array{row:ShareTarget,copy:Secret|null}>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function list(): array;

	/**
	 * Release a row the scan quarantined by mistake: it is a live share again,
	 * which its owner sees and may revoke. Its reason stays, so a later scan
	 * leaves it alone. Refused while the owner holds another live share with
	 * the same recipient, as one source and recipient have one share; drop the
	 * row then. Released without force only when its copy is gone or held by
	 * the row's creator, as a legacy two-step client left it. Released only
	 * when forced: a row whose copy was already shared before it; a row whose
	 * copy belongs to the recipient, as revoking the share then deletes what
	 * may be the recipient's own secret; a row whose copy is held by anyone
	 * else or is the source of a live share, as releasing it makes that
	 * holder's own secret look like a received copy again; and a row whose
	 * share or copy carries an access end date, as the expiry job revokes an
	 * ended share and deletes its copy. Never released, even when forced: a
	 * team-folder row whose folder is gone or whose recipient the folder no
	 * longer covers, as releasing it would give someone the owner removed
	 * the source's updates again; drop the row then.
	 *
	 * @param string $id    The share-target row id
	 * @param bool   $force Whether the administrator releases a row that needs force
	 *
	 * @return ShareTarget The released row
	 *
	 * @throws InvalidArgumentException When no quarantined row has this id, it needs force, a live share replaces it, or
	 *   its team folder no longer grants it
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function release(string $id, bool $force): ShareTarget;
}//end interface
