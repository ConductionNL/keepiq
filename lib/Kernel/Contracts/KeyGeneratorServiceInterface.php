<?php

/**
 * Contract of KeyGeneratorService
 *
 * The public surface of KeyGeneratorService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of KeyGeneratorService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KeyGeneratorServiceInterface {

	/**
	 * Generate a random string from the supplied configuration.
	 *
	 * When a non-empty regex is provided it overrides length,
	 * includeSpecialCharacters and excludedCharacters. The boolean
	 * includeSpecialCharacters flag is part of the public API contract.
	 *
	 * @param int $length Desired output length (default mode)
	 * @param bool $includeSpecialCharacters Whether to include the OWASP special set
	 * @param string $excludedCharacters Characters to remove from the resolved set
	 * @param string $regex Optional regex override
	 *
	 * @return string The generated key
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws RuntimeException When generation cannot satisfy the regex
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $includeSpecialCharacters mirrors the
	 *   optional request field of KeyGeneratorController::generate() one-for-one; this
	 *   method is the service half of that HTTP contract, so it carries the same
	 *   defaulted parameter. It is forwarded, never branched on, to generateFromCharset().
	 * @SuppressWarnings(PHPMD.LongVariable)        Same reason: the name is the wire field name
	 *   posted by src/dialogs/KeyGeneratorModal.vue and is called out above as part of
	 *   the public API contract, so it is not free to shorten.
	 *
	 * @spec openspec/specs/key-generator/spec.md#requirement-default-generation
	 */
	public function generate(
		int $length = 16,
		bool $includeSpecialCharacters = true,
		string $excludedCharacters = '',
		string $regex = '',
	): string;
}//end interface
