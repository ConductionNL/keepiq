<?php

/**
 * Contract of SecretService
 *
 * The public surface of SecretService that other components call, so they depend
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Exception\StaleWriteException;
use OCA\Keepiq\Exception\SuiteBlockedException;
use OCA\Keepiq\Exception\WriteLockedException;

/**
 * Contract of SecretService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretServiceInterface {

	/**
	 * The default page size.
	 *
	 * @var int
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * The fields a client may change through update(); `mergedPending` and
	 * `baseUpdatedAt` ride along but are not fields of the row.
	 *
	 * @var string[]
	 */
	public const UPDATABLE_FIELDS = ['name', 'url', 'typeId', 'folderId', 'key', 'login', 'additionalFields', 'totp', 'passkeys'];

	/**
	 * Create a secret for a user.
	 *
	 * @param array<string,mixed> $data The submitted fields (encrypted blobs + metadata)
	 * @param string $userId The owning Nextcloud user ID
	 * @param bool $allowUnfilled Permit an empty `key` because this Secret is the
	 *                            placeholder a secret request will write into.
	 *                            Defaults to false, so an ordinary user create
	 *                            still cannot store a valueless secret.
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When required fields are missing or invalid
	 * @throws NotFoundException When the named folder does not exist
	 * @throws ForbiddenException When the folder belongs to another user or a vault policy refuses the write
	 * @throws SuiteBlockedException When the user has no active suite
	 * @throws WriteLockedException When a compromise-recovery migration is in progress
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) - `$allowUnfilled` is an explicit
	 * opt-in for a single caller (the secret-request placeholder), not a mode
	 * switch. Same adjudication as `createByApplication()` (#266): splitting it
	 * into two public methods would duplicate the write-lock, validation, type
	 * resolution and suite checks below, while relaxing the key requirement for
	 * every user write would let an ordinary create silently store a valueless
	 * secret — the failure this flag exists to prevent.
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function create(array $data, string $userId, bool $allowUnfilled = false): Secret;

	/**
	 * Create a secret written by the application itself (machine write-back).
	 *
	 * The application is the principal — it submits metadata plus fields it
	 * already encrypted with its own public certificate (which it trivially
	 * holds). The server validates shape only and can never inspect the
	 * plaintext. The audit actor is the application, not a user. Used by the
	 * machine secret-store API `POST /api/v1/app/secrets`.
	 *
	 * @param array<string,mixed> $data The submitted fields (ciphertext + metadata)
	 * @param string $applicationId The owning application ID
	 * @param bool $allowUnfilled Permit an empty `key` because this is a
	 *                            secret-request shell awaiting a human fill.
	 *                            Defaults to false, so an ordinary write-back
	 *                            still cannot store a valueless secret.
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When required fields are missing, or a totp or passkeys value is sent
	 * @throws SuiteBlockedException When the application has no active suite
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) - `$allowUnfilled` is an explicit
	 * opt-in for a single caller (the secret-request shell), not a mode switch.
	 * Splitting it into two public methods would duplicate the validation and
	 * suite checks below, and relaxing the key requirement for every application
	 * write instead would let an ordinary write-back silently store a valueless
	 * secret — the failure this flag exists to prevent. See the comment on the
	 * check itself.
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function createByApplication(
		array $data,
		string $applicationId,
		bool $allowUnfilled = false,
	): Secret;

	/**
	 * Create a secret keyed to an application's EncryptionSuite.
	 *
	 * Used by the write-secret-for-app flow: an authenticated NC user
	 * encrypts a secret with the application's public key client-side
	 * and POSTs the ciphertext + metadata; the server stores the row
	 * with `owner_type=application` / `owner_id=$applicationId` and
	 * links it to the application's active EncryptionSuite. The writing
	 * user is NOT recorded on the row — once written, only the
	 * application can decrypt (it holds the private key).
	 *
	 * @param array<string,mixed> $data The submitted fields (ciphertext + metadata)
	 * @param string $applicationId The owning application ID
	 * @param string $writingUserId The NC user performing the write (audit only)
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException When required fields are missing, or a totp or passkeys value is sent
	 * @throws SuiteBlockedException When the application has no active suite
	 *
	 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
	 */
	public function createForApplication(array $data, string $applicationId, string $writingUserId): Secret;

	/**
	 * Delete a secret owned by the user, cascading to its link shares.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 * @param string|null $purgeReason Set when this delete purges a trashed secret:
	 *                                 'owner' or 'retention' (vault-trash-and-archive)
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the secret belongs to another user
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-restoring-and-purging-trashed-secrets
	 */
	public function delete(string $id, string $userId, ?string $purgeReason = null): void;

	/**
	 * Load an owned secret WITHOUT dispatching a secret.read audit event
	 * (rotation-expiry-policies §5.1) — for metadata-only lookups such as
	 * effective-expiry resolution; no encrypted blob leaves this path.
	 *
	 * @param string $id The secret UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string|null $baseUpdatedAt The version an offline change was made from; a
	 *                                   secret changed since is refused (offline-edit-queue)
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the secret belongs to another user
	 * @throws \OCA\Keepiq\Exception\StaleWriteException When it changed since `baseUpdatedAt`
	 *
	 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry-without-ciphertext-change
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function findOwned(string $id, string $userId, ?string $baseUpdatedAt=null): Secret;

	/**
	 * Get a secret owned by the user, enforcing revoked-suite blocking.
	 *
	 * @param string $id The secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the secret belongs to another user
	 * @throws SuiteBlockedException When the encryption suite is revoked/compromised
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function get(string $id, string $userId): Secret;

	/**
	 * List a user's secrets, paginated, optionally filtered by folder, sorted.
	 *
	 * Blocked secrets (revoked/compromised suite) are included with metadata
	 * only — their encrypted blobs are omitted.
	 *
	 * @param string $userId The requesting Nextcloud user ID
	 * @param string|null $folderId The folder filter (null = no filter)
	 * @param string|null $sort The sort column
	 * @param string $direction The sort direction
	 * @param int $page The 1-based page number
	 * @param int $limit The page size
	 * @param string|null $typeId The secret-type filter (null = all types)
	 * @param string $state The trash/archive state (SecretMapper::STATE_*), live by default
	 * @param bool|null $favourite Only the user's starred secrets when true
	 * @param string|null $tag Only secrets the user tagged with this tag
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, limit: int}
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function list(
		string $userId,
		?string $folderId,
		?string $sort,
		string $direction,
		int $page,
		int $limit,
		?string $typeId = null,
		string $state = SecretMapper::STATE_LIVE,
		?bool $favourite = null,
		?string $tag = null,
	): array;

	/**
	 * Search a user's secrets with two-stage fuzzy matching.
	 *
	 * @param string $userId The requesting Nextcloud user ID
	 * @param string $term The search term
	 * @param int $page The 1-based page number
	 * @param int $limit The page size
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, limit: int}
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function search(string $userId, string $term, int $page, int $limit): array;

	/**
	 * Set or clear a secret's per-secret expiry (rotation-expiry-policies
	 * §2.3) — owner-only; NEVER touches ciphertext or `key_updated_at`.
	 *
	 * The row is moved off the version it was read at, as an update is, so
	 * its new version always follows the stored one. The expiry is not part
	 * of the value, so a save that lands between the read and the write is
	 * kept and the expiry is set once more on the row as it is now.
	 *
	 * @param string $id The secret UUID
	 * @param DateTime|null $expiresAt The expiry instant (null = clear)
	 * @param string $userId The caller (must own the secret)
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the secret belongs to another user
	 * @throws StaleWriteException When the row moved on twice during the write
	 *
	 * @spec openspec/changes/rotation-expiry-policies/specs/rotation-expiry-policies/spec.md#requirement-per-secret-expiry
	 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
	 */
	public function setExpiry(string $id, ?DateTime $expiresAt, string $userId): Secret;

	/**
	 * Update a secret owned by the user.
	 *
	 * @param string $id The secret ID
	 * @param array<string,mixed> $data The fields to update
	 * @param string $userId The requesting Nextcloud user ID
	 * @param DateTime|null $savedAt The new version; a share-save passes the one it already locked in
	 * @param bool $restoring Whether the data is a version being restored (a share-save restore)
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the secret belongs to another user, or its value changes on a copy its holder may only view
	 * @throws WriteLockedException When a compromise-recovery migration is in progress
	 * @throws InvalidArgumentException When a provided field is invalid
	 * @throws StaleWriteException When `baseUpdatedAt` names an older version, or the row moved on after it was read
	 * @throws ShareScanPendingException When the secret is a copy whose share row waits for the share quarantine scan
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$restoring` only tells the
	 *   compromise rule that the totp and passkeys written are old values; the
	 *   write itself is the same.
	 */
	public function update(string $id, array $data, string $userId, ?DateTime $savedAt = null, bool $restoring = false): Secret;

	/**
	 * Update a secret written by the application itself (machine write-back).
	 *
	 * Strictly own-vault scoped: the secret must belong to the calling
	 * application or a NotFoundException is raised (no existence oracle).
	 * Replaces the client-encrypted ciphertext and advances updatedAt;
	 * advances keyUpdatedAt only when the `key` ciphertext blob changes
	 * (ciphertext-age tracking, never decrypts). The server validates shape
	 * only. Used by `PUT /api/v1/app/secrets/{id}`.
	 *
	 * @param string $id The secret ID
	 * @param array<string,mixed> $data The submitted fields (ciphertext + metadata)
	 * @param string $applicationId The owning application ID
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist in this vault
	 * @throws InvalidArgumentException When a submitted field is invalid, or a totp or passkeys value is sent
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function updateByApplication(string $id, array $data, string $applicationId): Secret;

	/**
	 * Add the holder's tags to serialised secrets (vault-favourites-tags-and-last-used).
	 * Every item gets a `tags` list, empty when it has none or no tag mapper is wired.
	 *
	 * @param array<int,array<string,mixed>> $items  Serialised secrets of the user
	 * @param string                         $userId The holder
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function withTags(array $items, string $userId): array;
}//end interface
