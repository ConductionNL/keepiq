<?php

/**
 * Contract of MigrationWorkService
 *
 * The public surface of MigrationWorkService that other components call, so they depend
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
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\FieldsNotReencryptedException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Contract of MigrationWorkService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MigrationWorkServiceInterface {

	/**
	 * Commit a re-wrapped attachment grant.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $grantId The grant to re-point
	 * @param string $wrappedFileKey The file key re-wrapped under the new suite
	 *
	 * @return AttachmentGrant
	 *
	 * @throws NotFoundException When the grant does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitAttachmentGrant(
		SuiteMigration $migration,
		string $ownerId,
		string $grantId,
		string $wrappedFileKey,
	): AttachmentGrant;

	/**
	 * Commit a re-encrypted secret.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $secretId The secret to re-point
	 * @param string $key The new key ciphertext
	 * @param string|null $login The new login ciphertext
	 * @param string|null $additionalFields The new additional-fields ciphertext
	 * @param string|null $totp The new TOTP ciphertext, null when not sent
	 * @param string|null $passkeys The new passkeys ciphertext, null when not sent
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 * @throws FieldsNotReencryptedException When a stored value field was not re-encrypted
	 * @throws InvalidArgumentException When a value field the record holds none for is sent one
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitSecret(
		SuiteMigration $migration,
		string $ownerId,
		string $secretId,
		string $key,
		?string $login,
		?string $additionalFields,
		?string $totp = null,
		?string $passkeys = null,
	): Secret;

	/**
	 * Commit a re-encrypted secret version.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $versionId The version to re-point
	 * @param string $key The new key ciphertext
	 * @param string|null $login The new login ciphertext
	 * @param string|null $additionalFields The new additional-fields ciphertext
	 * @param string|null $totp The new TOTP ciphertext, null when not sent
	 * @param string|null $passkeys The new passkeys ciphertext, null when not sent
	 *
	 * @return SecretVersion
	 *
	 * @throws NotFoundException When the version or its secret does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 * @throws FieldsNotReencryptedException When a stored value field was not re-encrypted
	 * @throws InvalidArgumentException When a value field the record holds none for is sent one
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitVersion(
		SuiteMigration $migration,
		string $ownerId,
		string $versionId,
		string $key,
		?string $login,
		?string $additionalFields,
		?string $totp = null,
		?string $passkeys = null,
	): SecretVersion;

	/**
	 * List the outstanding work for a migration, paged per store.
	 *
	 * Each record carries the ciphertext the browser needs to re-encrypt it, so
	 * one request yields a directly workable batch instead of a page of ids
	 * followed by a fetch per id. Every field here is ciphertext the caller
	 * already owns and is about to decrypt in their own browser — the server
	 * still decrypts nothing (ADR-003).
	 *
	 * Read-only: this mutates nothing, so the version rows that will be
	 * dropped are reported as a count only. The drop happens when the
	 * migration terminates, which is what the version-history spec ties it to.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The migration owner's user ID
	 * @param int $limit Page size per store
	 * @param int $offset Page offset per store
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function listWork(
		SuiteMigration $migration,
		string $ownerId,
		int $limit = 25,
		int $offset = 0,
	): array;

	/**
	 * Record a per-record migration failure on the owning secret.
	 *
	 * `migration_error` exists only on `keepiq_secrets`, so a version or
	 * grant failure is recorded against the secret that owns it, prefixed with
	 * the store it came from. That keeps the user's failure list one flat list
	 * pointing at things they recognise, and needs no schema change.
	 *
	 * A failure never aborts the run — the caller records and moves on.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $store The store the failure came from
	 * @param string $recordId The failing record's ID
	 * @param string $message The failure message
	 *
	 * @return string The secret ID the failure was recorded against
	 *
	 * @throws NotFoundException When the record does not exist
	 * @throws ForbiddenException When the record is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
	 */
	public function recordFailure(
		SuiteMigration $migration,
		string $ownerId,
		string $store,
		string $recordId,
		string $message,
	): string;
}//end interface
