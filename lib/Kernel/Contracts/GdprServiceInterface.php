<?php

/**
 * Contract of GdprService
 *
 * The public surface of GdprService that other components call, so they depend
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
 * Contract of GdprService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GdprServiceInterface {

	/**
	 * Collect all server-readable personal data for a user.
	 *
	 * Strictly self-scoped: the userId is the only selector. The encrypted
	 * private-key blob is deliberately excluded from suite records — it is
	 * unreadable to the data subject without the master password they already
	 * hold, and shipping it in an unprotected JSON file only widens the attack
	 * surface. The exclusion is documented inside the returned package.
	 *
	 * @param string $userId The session user
	 *
	 * @return array<string,mixed> The versioned, self-describing metadata document
	 *
	 * @spec openspec/specs/gdpr-compliance/spec.md
	 */
	public function collectMetadata(string $userId): array;
}//end interface
