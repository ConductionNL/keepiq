<?php

/**
 * Contract of EphemeralSendService
 *
 * The public surface of EphemeralSendService that other components call, so they depend
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
use OCA\Keepiq\Db\EphemeralSend;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of EphemeralSendService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EphemeralSendServiceInterface {

	/**
	 * The ciphertext (+ wrapped key) for client-side decryption, counted
	 * before it leaves the server.
	 *
	 * Every serve consumes one view, and one attempt when the send has a
	 * password, atomically and only while both remain, so the view limit and
	 * the attempt cap hold whether or not the client confirms. The send is
	 * deleted (burned) as soon as the serve that reaches either one is read.
	 *
	 * @param string $token The URL token
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DoesNotExistException When missing, expired, burned or out of attempts
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
	 */
	public function access(string $token): array;

	/**
	 * Confirm a successful client-side decrypt. The view was counted when
	 * the ciphertext was served; a confirm only resets the attempts.
	 *
	 * @param string $token The URL token
	 *
	 * @return array{burned:bool, remainingViews:int}
	 *
	 * @throws DoesNotExistException When missing, expired, or burned
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
	 */
	public function confirmView(string $token): array;

	/**
	 * Create a send from client-encrypted material.
	 *
	 * @param string $ownerId The creating user
	 * @param array<string,mixed> $params {encryptedPayload, payloadType, maxViews?, ttlSeconds?, hasPassword?, wrappedKey?, argon2idSalt?}
	 *
	 * @return EphemeralSend
	 *
	 * @throws InvalidArgumentException On invalid parameters
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-create-a-standalone-ephemeral-send
	 */
	public function create(string $ownerId, array $params): EphemeralSend;

	/**
	 * The owner's sends, newest first.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return EphemeralSend[]
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
	 */
	public function listForOwner(string $ownerId): array;

	/**
	 * Public metadata for the access page — never the ciphertext.
	 *
	 * @param string $token The URL token
	 *
	 * @return array<string,mixed>
	 *
	 * @throws DoesNotExistException When missing, expired, or burned
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-anonymous-recipient-access-with-no-account
	 */
	public function peek(string $token): array;

	/**
	 * Delete TTL-elapsed and fully-burned sends (cron).
	 *
	 * @return int Rows purged
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
	 */
	public function purge(): int;

	/**
	 * Count a failed password attempt; the send permanently burns at 5.
	 *
	 * @param string $token The URL token
	 *
	 * @return array{burned:bool, attemptsLeft:int}
	 *
	 * @throws DoesNotExistException When missing, expired, or burned
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-burn-after-read-and-optional-expiry
	 */
	public function reportFailure(string $token): array;

	/**
	 * Revoke (delete) one of the owner's sends. A foreign send throws
	 * the SAME not-found as a missing one.
	 *
	 * @param string $id The send UUID
	 * @param string $ownerId The claiming owner
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When missing or foreign
	 *
	 * @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
	 */
	public function revoke(string $id, string $ownerId): void;
}//end interface
