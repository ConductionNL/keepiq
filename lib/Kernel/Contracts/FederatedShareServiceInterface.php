<?php

/**
 * Contract of FederatedShareService
 *
 * The public surface of FederatedShareService that other components call, so they depend
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
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use RuntimeException;

/**
 * Contract of FederatedShareService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FederatedShareServiceInterface {

	/**
	 * The OCM resource type Keepiq registers and sends.
	 *
	 * @var string
	 */
	public const RESOURCE_TYPE = 'keepiq-secret';

	/**
	 * Answer the recipient server's pull of `/ocm/keepiq/shares/{id}`, or null
	 * for the unknown answer.
	 *
	 * @param string|null $signer The verified signer, null when unsigned
	 * @param string $shareId The share id from the path
	 * @param array<array-key,mixed> $payload The request payload, `{sharedSecret}`
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-bobs-share-follows-the-secret-to-a-new-owner
	 */
	public function answerPull(?string $signer, string $shareId, array $payload): ?array;

	/**
	 * Store the browser-made ciphertext for a federated recipient and announce
	 * the share over OCM.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $userId The owner
	 * @param string $recipientCloudId The recipient, `bob@cloud.partner.example`
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified and encrypted for
	 * @param array{key:string,login:?string,additionalFields:?string,totp?:?string,passkeys?:?string} $ciphertext Encrypted for the recipient
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When the secret is not the user's own
	 * @throws InvalidArgumentException `invalid`, `not_a_partner`, `unknown_recipient`, `already_shared`, or
	 *   `copy_fields_mismatch` when its totp or passkeys does not mirror the source's
	 * @throws PartnerUpgradeRequiredException When it carries a value field the partner has not advertised
	 * @throws RuntimeException `federation_unavailable`, or `delivery_failed` when the partner refused it
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function create(
		string $secretId,
		string $userId,
		string $recipientCloudId,
		string $certFingerprint,
		array $ciphertext,
	): FederatedShare;

	/**
	 * The federated shares of one of the user's own secrets.
	 *
	 * @param string $secretId The owner's secret
	 * @param string $userId The owner
	 *
	 * @return FederatedShare[]
	 *
	 * @throws NotFoundException When the secret is not the user's own
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function listForSecret(string $secretId, string $userId): array;

	/**
	 * Revoke a share: nothing is served any more, and the recipient's
	 * instance is told to delete its copy (task 4.2). The row goes once that
	 * notification arrives; until then the retry job keeps trying.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 *
	 * @return void
	 *
	 * @throws NotFoundException When it is not the user's share
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
	 */
	public function revoke(string $shareId, string $userId): void;

	/**
	 * Suspend a share whose recipient certificate no longer verifies in the
	 * owner's browser (task 4.2): nothing is served until the owner revokes
	 * it or shares again.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param string $reason Why, as the browser reports it
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When it is not the user's live share
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-partner-copies-change-only-with-the-owners-share-save
	 */
	public function suspend(string $shareId, string $userId, string $reason): FederatedShare;

	/**
	 * Suspend every share to users of a partner that was removed (task 4.2).
	 *
	 * @param string $partnerId The removed partner
	 *
	 * @return int How many shares were suspended
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
	 */
	public function suspendForPartner(string $partnerId): int;

	/**
	 * Replace a share's ciphertext after the owner changed the secret, and
	 * tell the recipient's instance to pull again (task 4.1). The owner's
	 * browser made the new ciphertext for a freshly verified certificate.
	 *
	 * @param string $shareId The federated share
	 * @param string $userId The owner
	 * @param string $certFingerprint SHA-256 of the certificate the browser verified now
	 * @param array{key:string,login:?string,additionalFields:?string,totp?:?string,passkeys?:?string} $ciphertext For the recipient,
	 *   see writeCiphertext() for an absent `totp` or `passkeys`
	 *
	 * @return FederatedShare
	 *
	 * @throws NotFoundException When it is not the user's live share
	 * @throws InvalidArgumentException `invalid`, or `copy_fields_mismatch` when its totp or passkeys does not mirror
	 *   the source's
	 * @throws PartnerUpgradeRequiredException When it carries a value field the partner has not advertised
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
	 */
	public function update(string $shareId, string $userId, string $certFingerprint, array $ciphertext): FederatedShare;
}//end interface
