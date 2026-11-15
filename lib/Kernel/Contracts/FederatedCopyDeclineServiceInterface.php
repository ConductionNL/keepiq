<?php

/**
 * Contract of FederatedCopyDeclineService
 *
 * The public surface of FederatedCopyDeclineService that other components call, so they depend
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
 * Contract of FederatedCopyDeclineService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedCopyDeclineServiceInterface {

	/**
	 * The OCM notification that the recipient declined a share.
	 *
	 * @var string
	 */
	public const SHARE_DECLINED = 'SHARE_DECLINED';

	/**
	 * The OCM notification that the recipient accepted a share, here: took
	 * a declined one back by restoring the copy.
	 *
	 * @var string
	 */
	public const SHARE_ACCEPTED = 'SHARE_ACCEPTED';
}//end interface
