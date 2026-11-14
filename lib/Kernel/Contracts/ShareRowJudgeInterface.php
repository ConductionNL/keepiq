<?php

/**
 * Contract of ShareRowJudge
 *
 * The public surface of ShareRowJudge that other components call, so they depend
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Service\ShareRowVerdict;

/**
 * Contract of ShareRowJudge.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ShareRowJudgeInterface {

	/**
	 * The verdict on the share row naming a secret id as its copy. A secret
	 * that does not exist is no copy.
	 *
	 * @param string $copyId The secret that may be a received copy
	 *
	 * @return ShareRowVerdict
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function forCopyId(string $copyId): ShareRowVerdict;

	/**
	 * The verdict on the share row naming a secret as its copy.
	 *
	 * @param Secret $copy The secret that may be a received copy
	 *
	 * @return ShareRowVerdict
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
	 */
	public function forCopy(Secret $copy): ShareRowVerdict;
}//end interface
