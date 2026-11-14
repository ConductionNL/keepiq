<?php

/**
 * Contract of RecipientStatusService
 *
 * The public surface of RecipientStatusService that other components call, so they depend
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

/**
 * Contract of RecipientStatusService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecipientStatusServiceInterface {

	/**
	 * The most users one answer covers: one page of the share dialog's search.
	 *
	 * @var int
	 */
	public const MAX_USERS = 25;

	/**
	 * For each requested user that the sharee search for `$search` returns,
	 * whether they hold an active suite. Requested ids the search does not
	 * return are left out of the answer entirely.
	 *
	 * @param string $search The term the caller searched for
	 * @param array<int,string> $userIds Distinct user ids from that search, at most MAX_USERS
	 *
	 * @return array<int,array{userId:string,hasSuite:bool}> In the order requested
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
	 */
	public function statusFor(string $search, array $userIds): array;
}//end interface
