<?php

/**
 * Contract of ImportService
 *
 * The public surface of ImportService that other components call, so they depend
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
use OCA\Keepiq\Exception\SuiteBlockedException;

/**
 * Contract of ImportService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ImportServiceInterface {

	/**
	 * Maximum items accepted in a single batch request.
	 *
	 * @var int
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Commit one chunk of already-encrypted items for the session user.
	 *
	 * Folders are ensured before items so an item can reference a freshly
	 * created folder; a path with zero successful items is still ensured only
	 * when at least one of its items is committed (folders are ensured lazily
	 * per item, never for all-failed paths).
	 *
	 * @param array<int,array<string,mixed>> $items The encrypted items
	 * @param string $userId The session user id
	 *
	 * @return array{results: array<int,array<string,mixed>>, foldersCreated: string[]}
	 *
	 * @throws SuiteBlockedException When the user has no active EncryptionSuite
	 * @throws InvalidArgumentException When the chunk exceeds the item cap
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
	 */
	public function commitChunk(array $items, string $userId): array;
}//end interface
