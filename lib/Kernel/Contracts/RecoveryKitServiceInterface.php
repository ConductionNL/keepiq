<?php

/**
 * Keepiq RecoveryKitServiceInterface
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\\Contracts
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of RecoveryKitService: a user's own recovery kit, always for the
 * signed-in user's active suite.
 *
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
 */
interface RecoveryKitServiceInterface {

	/**
	 * Whether the user has a kit for their active suite, and when it was made.
	 *
	 * @param string $userId The owner.
	 *
	 * @return array{kit: array{suiteId: string, createdAt: string|null}|null}
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function status(string $userId): array;

	/**
	 * Store a new wrapped copy for the user's active suite, replacing the old one.
	 *
	 * @param string $userId     The owner.
	 * @param string $wrappedKey The suite private key, wrapped in the browser.
	 * @param string $kdf        The derivation parameters the browser used.
	 *
	 * @return array{kit: array{suiteId: string, createdAt: string|null}|null}
	 *
	 * @throws InvalidArgumentException When a value is missing or too large, or the user has no active suite.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
	 */
	public function create(string $userId, string $wrappedKey, string $kdf): array;

	/**
	 * The user's wrapped copy for their active suite, to unwrap in the browser.
	 *
	 * @param string $userId The owner.
	 *
	 * @return array{suiteId: string, wrappedKey: string, kdf: string}
	 *
	 * @throws NotFoundException When there is none.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	public function fetch(string $userId): array;

	/**
	 * Delete the spent copy after a recovery and record its use.
	 *
	 * @param string $userId The owner.
	 *
	 * @return void
	 *
	 * @throws NotFoundException When there is none.
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-the-recovery-code-reopens-the-vault-and-sets-a-new-master-password
	 */
	public function spend(string $userId): void;

	/**
	 * Delete the user's copy without using it, and record it.
	 *
	 * @param string $userId The owner.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
	 */
	public function revoke(string $userId): void;
}//end interface
