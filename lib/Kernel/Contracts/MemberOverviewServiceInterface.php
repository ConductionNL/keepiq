<?php

/**
 * Contract of MemberOverviewService
 *
 * The public surface of MemberOverviewService that other components call, so they depend
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

/**
 * Contract of MemberOverviewService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MemberOverviewServiceInterface {

	/**
	 * Default page size.
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * One page of the member overview.
	 *
	 * Without a status filter, the page is one user manager page. With a
	 * filter, users are read in batches until the page is full or the users
	 * run out, so `offset` counts matching rows, not users.
	 *
	 * @param string $status Vault status filter (`none`, `active`, `revoked`, `compromised`) or ''
	 * @param string $search Search on user id or display name, '' for everyone
	 * @param int $limit Page size, 1 to 200
	 * @param int $offset Rows to skip, 0 or more
	 *
	 * @return array{results:array<int,array<string,mixed>>,limit:int,offset:int,hasMore:bool}
	 *
	 * @throws InvalidArgumentException On an unknown status or an out-of-range page
	 *
	 * @spec openspec/specs/admin-member-overview/spec.md#requirement-administrator-lists-vault-status-per-user
	 */
	public function list(string $status, string $search, int $limit, int $offset): array;
}//end interface
