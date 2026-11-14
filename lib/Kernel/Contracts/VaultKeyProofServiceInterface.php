<?php

/**
 * Contract of VaultKeyProofService
 *
 * The public surface of VaultKeyProofService that other components call, so they depend
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

use OCA\Keepiq\Exception\KeyProofRequiredException;

/**
 * Contract of VaultKeyProofService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VaultKeyProofServiceInterface {

	/**
	 * Stable public purpose identifiers. Both the guarded method's attribute and
	 * the client's challenge request name one of these, and the challenge is
	 * bound to it, so a proof for one operation cannot be presented to another.
	 */
	public const PURPOSE_COMPROMISE_RECOVERY = 'compromise-recovery';

	public const PURPOSE_UPDATE_PRIVATE_KEY = 'update-private-key';

	public const PURPOSE_COMPLETE_MIGRATION = 'complete-migration';

	public const PURPOSE_EMERGENCY_DESTROY = 'emergency-access-destroy';

	public const PURPOSE_REVOKE_SUITE = 'revoke-suite';

	public const PURPOSE_EMERGENCY_DESIGNATE = 'emergency-access-designate';

	public const PURPOSE_EMERGENCY_RE_ENVELOPE = 'emergency-access-re-envelope';

	public const PURPOSE_DELETE_ACCOUNT_DATA = 'delete-account-data';

	public const PURPOSE_ABORT_MIGRATION = 'abort-migration';

	public const PURPOSE_SHARE_NEW_RECIPIENT = 'share-new-recipient';

	public const PURPOSE_SHARE_REGISTER_BATCH = 'share-register-batch';

	public const PURPOSE_DELEGATION_CREATE = 'delegation-create';

	public const PURPOSE_DELEGATION_HANDOVER = 'delegation-handover';

	public const PURPOSE_APPROVE_DEVICE = 'approve-device';

	public const PURPOSE_APPROVE_ACCOUNT_RECOVERY = 'approve-account-recovery';

	/**
	 * The purposes a challenge may be issued for.
	 */
	public const ALLOWED_PURPOSES = [
		self::PURPOSE_COMPROMISE_RECOVERY,
		self::PURPOSE_UPDATE_PRIVATE_KEY,
		self::PURPOSE_COMPLETE_MIGRATION,
		self::PURPOSE_EMERGENCY_DESTROY,
		self::PURPOSE_REVOKE_SUITE,
		self::PURPOSE_EMERGENCY_DESIGNATE,
		self::PURPOSE_EMERGENCY_RE_ENVELOPE,
		self::PURPOSE_DELETE_ACCOUNT_DATA,
		self::PURPOSE_ABORT_MIGRATION,
		self::PURPOSE_SHARE_NEW_RECIPIENT,
		self::PURPOSE_SHARE_REGISTER_BATCH,
		self::PURPOSE_DELEGATION_CREATE,
		self::PURPOSE_DELEGATION_HANDOVER,
		self::PURPOSE_APPROVE_DEVICE,
		self::PURPOSE_APPROVE_ACCOUNT_RECOVERY,
	];

	/**
	 * Issue a challenge for a caller and a purpose.
	 *
	 * @param string $userId The caller's user id
	 * @param string $purpose The operation the challenge authorises
	 *
	 * @return array{nonce:string,expiresAt:int}
	 *
	 * @spec openspec/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	public function issueChallenge(string $userId, string $purpose): array;

	/**
	 * Verify a proof, or throw.
	 *
	 * Every failure path throws the same KeyProofRequiredException with no
	 * indication of which check failed, so a caller learns only pass/fail.
	 *
	 * @param string $nonce The challenge the client echoed back
	 * @param string $signatureB64 The base64 signature over the bound payload
	 * @param string $certificatePem The subject suite's certificate (its public key)
	 * @param string $userId The caller, which the challenge must name
	 * @param string $purpose The operation, which the challenge must name
	 * @param string[] $boundValues The request parameter values the proof commits to
	 *
	 * @return void
	 *
	 * @throws KeyProofRequiredException When the proof is absent, stale, mis-bound or invalid
	 *
	 * @spec openspec/specs/vault-key-proof/spec.md#requirement-irreversible-operations-require-a-verified-key-proof
	 */
	public function verify(
		string $nonce,
		string $signatureB64,
		string $certificatePem,
		string $userId,
		string $purpose,
		array $boundValues,
	): void;
}//end interface
