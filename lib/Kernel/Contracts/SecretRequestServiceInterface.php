<?php

/**
 * Contract of SecretRequestService
 *
 * The public surface of SecretRequestService that other components call, so they depend
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
use OCA\Keepiq\Db\SecretRequest;

/**
 * Contract of SecretRequestService.
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SecretRequestServiceInterface {

	/**
	 * Approve (mark fulfilled) a pending secret request.
	 *
	 * The caller (controller) is responsible for the encryption-blob writes
	 * to the linked Secret row before flipping the status. This method
	 * only enforces the lifecycle transition and the expiry/ownership
	 * checks.
	 *
	 * @param string $requestId The request ID
	 * @param string $userId The Nextcloud user approving the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-write-once
	 */
	public function approve(string $requestId, string $userId): SecretRequest;

	/**
	 * Create a new pending secret request.
	 *
	 * @param string $secretId The Secret ID (unfilled or re-request)
	 * @param string $encryptionSuiteId The recipient's active suite ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param bool $isReRequest Whether this is a re-request
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function create(
		string $secretId,
		string $encryptionSuiteId,
		array $requestedFields,
		bool $isReRequest,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest;

	/**
	 * Create a new pending secret request keyed to an application.
	 *
	 * Resolves the application's active EncryptionSuite through the policy
	 * so the caller does not need to know the suite ID. Enforces the same
	 * invariants as `create()` plus an explicit application-active check
	 * (no requests for pending or rejected applications).
	 *
	 * @param string $secretId The Secret ID
	 * @param string $applicationId The recipient application ID
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the application has no active suite
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.3
	 */
	public function createForApplication(
		string $secretId,
		string $applicationId,
		array $requestedFields,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest;

	/**
	 * Create a FRESH request in the requester's own vault, creating the Secret.
	 *
	 * This is the path a person takes when they have nothing to point at yet.
	 * The spec has always required it — "the system MUST create an unfilled
	 * Secret and a SecretRequest" — but the only surface that existed demanded a
	 * pre-existing Secret, and `create()` refused an empty key, so a requester
	 * had to invent a value for the credential they were about to ask for.
	 *
	 * Mirrors `ApplicationSecretRequestService::createForApplicationVault()`,
	 * including its rollback: the shell exists only to receive this request, so
	 * it must not survive a failed creation.
	 *
	 * A RE-REQUEST does not come through here. It targets an existing Secret and
	 * keeps using `create()` / `createReRequest()`, which is what keeps the two
	 * flows structurally distinct rather than distinguished by a boolean.
	 *
	 * @param string $userId The Nextcloud user creating the request
	 * @param array<string> $requestedFields Field names to be filled in
	 * @param string|null $name Name for the created Secret
	 * @param string|null $folderId Optional folder to file it under
	 * @param DateTime|null $expiresAt Optional expiry
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When userId or requestedFields are missing
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function createForUserVault(
		string $userId,
		array $requestedFields,
		?string $name = null,
		?string $folderId = null,
		?DateTime $expiresAt = null,
	): SecretRequest;

	/**
	 * Create a re-request for an existing secret.
	 *
	 * A re-request renews the access to a Secret whose recipient lost
	 * the plaintext (rotation, recovery, etc). Guards:
	 *   - the Secret must already exist (lookup via SecretMapper);
	 *   - no other pending request may be open for the same Secret;
	 *   - the caller must own the Secret being re-requested.
	 *
	 * The EncryptionSuite is reused from the Secret's current suite ID
	 * (the recipient encrypts under their existing key material).
	 *
	 * @param string $secretId The Secret ID
	 * @param array<string> $requestedFields Field names to be re-filled
	 * @param DateTime|null $expiresAt Optional expiry
	 * @param string $userId The Nextcloud user creating the re-request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException When the Secret is missing, a pending request exists,
	 *                                  or the caller is not the owner.
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.4
	 */
	public function createReRequest(
		string $secretId,
		array $requestedFields,
		?DateTime $expiresAt,
		string $userId,
	): SecretRequest;

	/**
	 * Decline a pending secret request.
	 *
	 * @param string $requestId The request ID
	 * @param string $userId The Nextcloud user declining the request
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function decline(string $requestId, string $userId): SecretRequest;

	/**
	 * Transition a lapsed request to the terminal `expired` status.
	 *
	 * Called by the sweeper, never by a person, so there is no ownership check to
	 * make here — the caller has already selected rows by `expires_at`. What this
	 * does enforce is that only a PENDING request can lapse: a fulfilled or
	 * declined request has already reached its end state, and re-terminating it
	 * would rewrite history.
	 *
	 * Cleanup matches revoke exactly, because the keyless-placeholder invariant
	 * does not care why the request ended: an unfilled placeholder goes, a
	 * re-request's Secret and its values stay. Reusing the same helper is what
	 * keeps the two paths from drifting.
	 *
	 * @param SecretRequest $request The lapsed request
	 *
	 * @return SecretRequest|null The updated request, or null when it was not pending
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function expire(SecretRequest $request): ?SecretRequest;

	/**
	 * Mark a pending request as fulfilled from the public fill endpoint.
	 *
	 * Validates the lifecycle / expiry / requested-fields invariants, atomically
	 * flips status to fulfilled, and announces it to the original requester.
	 *
	 * `$encryptedFields` IS persisted onto the linked Secret, which the spec
	 * requires ("store them in the linked Secret" — Fill In via Link). It used
	 * not to be: this method validated the blobs, flipped the status to
	 * fulfilled and discarded them, so a recipient's submission was silently
	 * lost and the request was marked fulfilled with nothing to show for it.
	 *
	 * The write goes through SecretService rather than the mapper, so it
	 * inherits that path's guarantees: a version-history snapshot, and
	 * `possibly_compromised_at` clearing, which is bound to an actual `key`
	 * write. Writing via the mapper would silently skip both.
	 *
	 * Order matters. Values are persisted BEFORE the status flip, so a failed
	 * write leaves the request `pending` and retryable rather than fulfilled
	 * and empty.
	 *
	 * @param string $token The access token
	 * @param array<string,mixed> $encryptedFields A map of fieldName => encryptedValue
	 * @param array<string,mixed> $plainFields Plaintext metadata (url), unencrypted by design
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException
	 * @throws \OCA\Keepiq\Exception\ForbiddenException When the secret's holder may not change it, as with a copy shared with them to view only
	 * @throws \OCA\Keepiq\Exception\NotFoundException When the secret is a copy whose source cannot be resolved, or is gone by the write
	 * @throws \OCA\Keepiq\Exception\ShareScanPendingException When the share row naming the secret waits for the share quarantine scan
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.5
	 */
	public function fill(string $token, array $encryptedFields, array $plainFields = []): SecretRequest;

	/**
	 * Look up a pending, non-expired request by its public access token.
	 *
	 * Distinguishes locked / expired / fulfilled / unknown via specific
	 * error codes so the public fill page can render targeted messaging.
	 *
	 * @param string $token The access token
	 *
	 * @return SecretRequest
	 *
	 * @throws InvalidArgumentException With code 404 (unknown), 410 (fulfilled),
	 *                                  423 (locked) or 408 (expired).
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function getByToken(string $token): SecretRequest;

	/**
	 * List all secret requests for a given Secret — visible only to the
	 * Secret owner. Used by the Secret detail sidebar to render the
	 * "Requests" history block.
	 *
	 * @param string $secretId The Secret ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return SecretRequest[]
	 *
	 * @throws InvalidArgumentException When the Secret does not exist or
	 *                                  the caller is not its owner.
	 *
	 * @spec openspec/changes/implement-secret-requests/tasks.md#task-3.8
	 */
	public function listBySecret(string $secretId, string $userId): array;

	/**
	 * List secret requests created by a given user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretRequest[]
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-create-secret-request
	 */
	public function listByUser(string $userId): array;

	/**
	 * Why a token cannot be filled, as a machine-readable reason.
	 *
	 * Pass-through to the policy, which classifies refusals, so the public fill
	 * page can render the refusal in the recipient's language instead of the
	 * English message this server composes. See SecretRequestPolicy::REASONS.
	 *
	 * @param string $token The access token
	 *
	 * @return string One of SecretRequestPolicy::REASONS
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-fill-in-via-link
	 */
	public function refusalReason(string $token): string;
}//end interface
