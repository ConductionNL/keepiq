<?php

/**
 * Contract of PasskeyService
 *
 * The public surface of PasskeyService that other components call, so they depend
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
use OCA\Keepiq\Db\PasskeyCredential;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Contract of PasskeyService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PasskeyServiceInterface {

	/**
	 * Enroll a passkey unlock envelope. The vault MUST be unlocked
	 * client-side — the server rejects a request that cannot present a
	 * wrapped envelope (§2.5). The envelope is bound to the CURRENT
	 * suite's unlock-key epoch so a later password change can stale it.
	 *
	 * The pending challenge pair is taken first, so it is spent by any
	 * attempt. The attestation and proof assertion must verify against it;
	 * the stored public key and authenticator model come from the verified
	 * data, and the claimed credential id must equal the verified one.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $dto {credentialId, prfSalt, wrappedUnlockKey, attestation, assertion, label, transports, clientKind, rpId}
	 *
	 * @return PasskeyCredential
	 *
	 * @throws InvalidArgumentException On a missing envelope, a failed verification or a duplicate credential
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function enroll(string $uid, array $dto): PasskeyCredential;

	/**
	 * Issue the enrolment challenge pair (base64url) as the only one the next
	 * enrolment may present: in the session for the web app, server-side and
	 * bound to the app password for the extension, which also gets the
	 * relying party and its random user handle.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 *
	 * @return array<string,string>
	 *
	 * @throws InvalidArgumentException When the extension may not enrol
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-enrol-a-platform-passkey-for-extension-unlock
	 */
	public function enrolmentChallenge(string $uid, string $client='web'): array;

	/**
	 * The owner's enrolled passkeys (management view — never the
	 * envelope or PRF salt).
	 *
	 * @param string $uid The calling user
	 *
	 * @return PasskeyCredential[]
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function listForOwner(string $uid): array;

	/**
	 * The unlock options for the lock screen (§2.2): the owner's ACTIVE
	 * credentials with their PRF salts, the current suite epoch, and a fresh
	 * unlock challenge held in the session. No wrapped envelope: unlock()
	 * returns one only after a verified assertion. Stale/revoked credentials
	 * are refused (not returned), and so is a credential enrolled without a
	 * verified public key, which no assertion can be checked against.
	 *
	 * Only the asking client's credentials are offered: the web app never
	 * gets an extension credential, and the extension only gets the
	 * credentials bound to its own relying party.
	 *
	 * @param string $uid The calling owner
	 * @param string $client web or extension
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-extension-credentials-are-visible-and-revocable-in-the-web-app
	 */
	public function loginOptions(string $uid, string $client='web'): array;

	/**
	 * Record a successful unlock (last-used stamp).
	 *
	 * @param string $uid The calling owner
	 * @param string $id The credential UUID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function recordUse(string $uid, string $id): void;

	/**
	 * Revoke (delete) one passkey — removes its unlock option immediately.
	 *
	 * @param string $uid The calling owner
	 * @param string $id The credential UUID
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When missing
	 * @throws InvalidArgumentException When not owned
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkeys-are-manageable-revocable-and-owner-scoped
	 */
	public function revoke(string $uid, string $id): void;

	/**
	 * Verify an unlock assertion and release the answering credential's
	 * wrapped envelope. The pending unlock challenge is taken first, so any
	 * attempt spends it. The credential must be one loginOptions() offers to
	 * this client; its counter and last-used stamp are updated.
	 *
	 * @param string $uid The calling owner
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 * @param string $client web or extension
	 *
	 * @return array{id: string, credentialId: string, wrappedUnlockKey: string, unlockKeyEpoch: int}
	 *
	 * @throws InvalidArgumentException When the assertion does not verify
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function unlock(string $uid, array $assertion, string $client='web'): array;
}//end interface
