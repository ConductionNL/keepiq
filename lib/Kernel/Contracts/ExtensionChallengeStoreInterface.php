<?php

/**
 * Contract of ExtensionChallengeStore
 *
 * The public surface of ExtensionChallengeStore that other components call, so they depend
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
 * Contract of ExtensionChallengeStore.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExtensionChallengeStoreInterface {

	/**
	 * Delete every pending challenge of an app password.
	 *
	 * @param int $tokenId The token id
	 *
	 * @return int The number of challenges deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-credentials-follow-their-app-password
	 */
	public function forgetToken(int $tokenId): int;

	/**
	 * Delete every expired challenge.
	 *
	 * @return int The number of challenges deleted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-challenges-are-server-side-bound-and-single-use
	 */
	public function purgeExpired(): int;
}//end interface
