<?php

/**
 * Doriath Secret Service
 *
 * Business logic for the Secret entity: create (with encryption-suite link
 * and write-lock check), read (with revoked-suite blocking), update,
 * delete (cascading to link shares), list (paginated/filtered/sorted with
 * blocked-secret metadata), and two-stage fuzzy search.
 *
 * The service never decrypts the key/login/additional_fields blobs; it
 * stores and returns the ciphertext produced in the browser. Encryption and
 * decryption happen client-side using the owner's RSA key (ADR-003).
 *
 * @category Service
 * @package  OCA\Doriath\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\GroupShareMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Event\Audit\AuditEvent;
use OCA\Doriath\Event\Audit\AuditEventTypes;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Exception\WriteLockedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the Secret entity.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The service owns the full
 *   secret lifecycle (CRUD + revoked-suite blocking + write-lock + two-stage
 *   fuzzy search); each concern is a small, cohesive method.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The service legitimately
 *   coordinates the secret mapper, type/migration/link-share services, the
 *   suite mapper, and the typed domain exceptions.
 */
class SecretService
{
    /**
     * The default page size.
     *
     * @var int
     */
    public const DEFAULT_LIMIT = 50;

    /**
     * The maximum page size.
     *
     * @var int
     */
    public const MAX_LIMIT = 100;

    /**
     * The suite statuses that block access to encrypted fields.
     *
     * @var string[]
     */
    private const BLOCKING_STATUSES = ['revoked', 'compromised'];

    /**
     * Constructor for SecretService.
     *
     * @param SecretMapper          $mapper           The secret mapper
     * @param SecretTypeService     $typeService      The secret type service
     * @param EncryptionSuiteMapper $suiteMapper      The encryption suite mapper
     * @param MigrationService      $migrationService The migration (write-lock) service
     * @param LinkShareService      $linkShareService The link share service (cascade delete)
     * @param LoggerInterface       $logger           The logger interface
     *
     * @return void
     */
    public function __construct(
        private SecretMapper $mapper,
        private SecretTypeService $typeService,
        private EncryptionSuiteMapper $suiteMapper,
        private MigrationService $migrationService,
        private LinkShareService $linkShareService,
        private LoggerInterface $logger,
        private ?SecretRequestService $secretRequestService=null,
        private ?ShareService $shareService=null,
        private ?GroupShareMapper $groupShareMapper=null,
        private ?SecretDelegationMapper $secretDelegationMapper=null,
        private ?IEventDispatcher $eventDispatcher=null,
    ) {
    }//end __construct()

    /**
     * Dispatch a typed audit event, fail-soft.
     *
     * The optional dispatcher keeps existing call sites that construct the
     * service without it compiling; when wired, every audited secret operation
     * emits an AuditEvent the AuditListener turns into an append-only row. The
     * dispatch is the only audit coupling in the service — a listener failure is
     * swallowed by the listener, never here.
     *
     * @param AuditEvent $event The audit event
     *
     * @return void
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
     */
    private function dispatchAudit(AuditEvent $event): void
    {
        $this->eventDispatcher?->dispatchTyped($event);
    }//end dispatchAudit()

    /**
     * Create a secret for a user.
     *
     * @param array<string,mixed> $data   The submitted fields (encrypted blobs + metadata)
     * @param string              $userId The owning Nextcloud user ID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException When required fields are missing or invalid
     * @throws SuiteBlockedException When the user has no active suite
     * @throws WriteLockedException When a compromise-recovery migration is in progress
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
     */
    public function create(array $data, string $userId): Secret
    {
        $this->assertNotWriteLocked(userId: $userId);

        $name = trim((string) ($data['name'] ?? ''));
        $key  = (string) ($data['key'] ?? '');
        if ($name === '' || $key === '') {
            throw new InvalidArgumentException('A secret requires a name and a key');
        }

        $suite = $this->getActiveSuiteOrBlock(userId: $userId);

        $typeId = $this->typeService->resolveTypeForSecret(
            $data['typeId'] ?? null,
            $userId
        );

        $now    = new DateTime();
        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($name);
        $secret->setUrl($this->nullableString(value: $data['url'] ?? null));
        $secret->setTypeId($typeId);
        $secret->setFolderId($this->nullableString(value: $data['folderId'] ?? null));
        $secret->setKey($key);
        $secret->setLogin($this->nullableString(value: $data['login'] ?? null));
        $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields'] ?? null));
        $secret->setEncryptionSuiteId($suite->getId());
        $secret->setOwnerType('user');
        $secret->setOwnerId($userId);
        $secret->setCreatedAt($now);
        $secret->setUpdatedAt($now);
        // Ciphertext age starts at creation; never decrypts (password-health D4).
        $secret->setKeyUpdatedAt($now);

        $this->mapper->insert($secret);
        $this->logger->info("Doriath: secret {$secret->getId()} created by {$userId}");

        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SECRET_CREATED,
                objectType: 'secret',
                objectId: $secret->getId(),
                objectName: $secret->getName(),
                metadata: ['typeId' => $typeId, 'folderId' => $secret->getFolderId()],
            )
        );

        return $secret;
    }//end create()

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
     * @param array<string,mixed> $data          The submitted fields (ciphertext + metadata)
     * @param string              $applicationId The owning application ID
     * @param string              $writingUserId The NC user performing the write (audit only)
     *
     * @return Secret
     *
     * @throws InvalidArgumentException When required fields are missing
     * @throws SuiteBlockedException When the application has no active suite
     *
     * @spec openspec/changes/implement-application-mgmt/tasks.md#task-9.4
     */
    public function createForApplication(array $data, string $applicationId, string $writingUserId): Secret
    {
        if ($applicationId === '') {
            throw new InvalidArgumentException('applicationId is required');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $key  = (string) ($data['key'] ?? '');
        if ($name === '' || $key === '') {
            throw new InvalidArgumentException('A secret requires a name and a key');
        }

        try {
            $suite = $this->suiteMapper->findActiveByOwner('application', $applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new SuiteBlockedException(
                message: 'No active EncryptionSuite for application '.$applicationId
            );
        }

        // Resolve the type under the writing user's namespace so the
        // SecretType resolver still finds a default; application secrets
        // do not own a type-namespace yet.
        $typeId = $this->typeService->resolveTypeForSecret(
            $data['typeId'] ?? null,
            $writingUserId
        );

        $now    = new DateTime();
        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($name);
        $secret->setUrl($this->nullableString(value: $data['url'] ?? null));
        $secret->setTypeId($typeId);
        $secret->setFolderId($this->nullableString(value: $data['folderId'] ?? null));
        $secret->setKey($key);
        $secret->setLogin($this->nullableString(value: $data['login'] ?? null));
        $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields'] ?? null));
        $secret->setEncryptionSuiteId($suite->getId());
        $secret->setOwnerType('application');
        $secret->setOwnerId($applicationId);
        $secret->setCreatedAt($now);
        $secret->setUpdatedAt($now);
        // Ciphertext age starts at creation; never decrypts (password-health D4).
        $secret->setKeyUpdatedAt($now);

        $this->mapper->insert($secret);
        $this->logger->info(
            "Doriath: application-secret {$secret->getId()} created for app {$applicationId} by {$writingUserId}"
        );

        return $secret;
    }//end createForApplication()

    /**
     * Create a secret written by the application itself (machine write-back).
     *
     * The application is the principal — it submits metadata plus fields it
     * already encrypted with its own public certificate (which it trivially
     * holds). The server validates shape only and can never inspect the
     * plaintext. The audit actor is the application, not a user. Used by the
     * machine secret-store API `POST /api/v1/app/secrets`.
     *
     * @param array<string,mixed> $data          The submitted fields (ciphertext + metadata)
     * @param string              $applicationId The owning application ID
     *
     * @return Secret
     *
     * @throws InvalidArgumentException When required fields are missing
     * @throws SuiteBlockedException When the application has no active suite
     *
     * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
     */
    public function createByApplication(array $data, string $applicationId): Secret
    {
        if ($applicationId === '') {
            throw new InvalidArgumentException('applicationId is required');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $key  = (string) ($data['key'] ?? '');
        if ($name === '' || $key === '') {
            throw new InvalidArgumentException('A secret requires a name and a key');
        }

        try {
            $suite = $this->suiteMapper->findActiveByOwner('application', $applicationId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new SuiteBlockedException(
                message: 'No active EncryptionSuite for application '.$applicationId
            );
        }

        $typeId = $this->typeService->resolveTypeForSecret(
            $data['typeId'] ?? null,
            $applicationId
        );

        $now    = new DateTime();
        $secret = new Secret();
        $secret->setId(Uuid::uuid4()->toString());
        $secret->setName($name);
        $secret->setUrl($this->nullableString(value: $data['url'] ?? null));
        $secret->setTypeId($typeId);
        $secret->setFolderId($this->nullableString(value: $data['folderId'] ?? null));
        $secret->setKey($key);
        $secret->setLogin($this->nullableString(value: $data['login'] ?? null));
        $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields'] ?? null));
        $secret->setEncryptionSuiteId($suite->getId());
        $secret->setOwnerType('application');
        $secret->setOwnerId($applicationId);
        $secret->setCreatedAt($now);
        $secret->setUpdatedAt($now);
        $secret->setKeyUpdatedAt($now);

        $this->mapper->insert($secret);

        $this->dispatchAudit(
            AuditEvent::forApplication(
                actorId: $applicationId,
                eventType: AuditEventTypes::SECRET_CREATED,
                objectType: 'secret',
                objectId: $secret->getId(),
                objectName: $secret->getName(),
                metadata: ['typeId' => $typeId, 'folderId' => $secret->getFolderId()],
            )
        );

        return $secret;
    }//end createByApplication()

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
     * @param string              $id            The secret ID
     * @param array<string,mixed> $data          The submitted fields (ciphertext + metadata)
     * @param string              $applicationId The owning application ID
     *
     * @return Secret
     *
     * @throws NotFoundException When the secret does not exist in this vault
     * @throws InvalidArgumentException When a submitted field is invalid
     *
     * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
     */
    public function updateByApplication(string $id, array $data, string $applicationId): Secret
    {
        try {
            $secret = $this->mapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new NotFoundException(message: 'Secret not found');
        }

        // Own-vault scoping: a row owned by another vault is indistinguishable
        // from a nonexistent one — same NotFoundException, no existence oracle.
        if ($secret->getOwnerType() !== 'application'
            || $secret->getOwnerId() !== $applicationId
        ) {
            throw new NotFoundException(message: 'Secret not found');
        }

        $now        = new DateTime();
        $keyChanged = false;

        if (array_key_exists('name', $data) === true) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Secret name cannot be empty');
            }

            $secret->setName($name);
        }

        if (array_key_exists('url', $data) === true) {
            $secret->setUrl($this->nullableString(value: $data['url']));
        }

        if (array_key_exists('folderId', $data) === true) {
            $secret->setFolderId($this->nullableString(value: $data['folderId']));
        }

        if (array_key_exists('login', $data) === true) {
            $secret->setLogin($this->nullableString(value: $data['login']));
        }

        if (array_key_exists('additionalFields', $data) === true) {
            $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields']));
        }

        if (array_key_exists('key', $data) === true) {
            $key = (string) $data['key'];
            if ($key === '') {
                throw new InvalidArgumentException('Secret key cannot be empty');
            }

            if ($key !== $secret->getKey()) {
                $secret->setKey($key);
                $secret->setKeyUpdatedAt($now);
                $keyChanged = true;
            }
        }

        $secret->setUpdatedAt($now);
        $this->mapper->update($secret);

        $this->dispatchAudit(
            AuditEvent::forApplication(
                actorId: $applicationId,
                eventType: AuditEventTypes::SECRET_UPDATED,
                objectType: 'secret',
                objectId: $secret->getId(),
                objectName: $secret->getName(),
                metadata: ['changedFields' => $keyChanged === true ? ['key'] : array_keys($data)],
            )
        );

        return $secret;
    }//end updateByApplication()

    /**
     * Get a secret owned by the user, enforcing revoked-suite blocking.
     *
     * @param string $id     The secret ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return Secret
     *
     * @throws NotFoundException When the secret does not exist
     * @throws ForbiddenException When the secret belongs to another user
     * @throws SuiteBlockedException When the encryption suite is revoked/compromised
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
     */
    public function get(string $id, string $userId): Secret
    {
        $secret = $this->loadOwned(id: $id, userId: $userId);

        $blockReason = $this->suiteBlockReason(secret: $secret);
        if ($blockReason !== null) {
            throw new SuiteBlockedException(message: $blockReason);
        }

        // secret.read fires on an individual encrypted-blob fetch only — never
        // on list/search (those do not call get()). Audit-trail §3.1.
        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SECRET_READ,
                objectType: 'secret',
                objectId: $secret->getId(),
                objectName: $secret->getName(),
            )
        );

        return $secret;
    }//end get()

    /**
     * Update a secret owned by the user.
     *
     * @param string              $id     The secret ID
     * @param array<string,mixed> $data   The fields to update
     * @param string              $userId The requesting Nextcloud user ID
     *
     * @return Secret
     *
     * @throws NotFoundException When the secret does not exist
     * @throws ForbiddenException When the secret belongs to another user
     * @throws WriteLockedException When a compromise-recovery migration is in progress
     * @throws InvalidArgumentException When a provided field is invalid
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each updatable field is an
     *   independent, flat partial-update branch.
     * @SuppressWarnings(PHPMD.NPathComplexity)      Same: the branches are
     *   independent partial-update guards, not nested logic.
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
     */
    public function update(string $id, array $data, string $userId): Secret
    {
        $this->assertNotWriteLocked(userId: $userId);

        $secret = $this->loadOwned(id: $id, userId: $userId);

        if (array_key_exists('name', $data) === true) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Secret name cannot be empty');
            }

            $secret->setName($name);
        }

        if (array_key_exists('url', $data) === true) {
            $secret->setUrl($this->nullableString(value: $data['url']));
        }

        if (array_key_exists('folderId', $data) === true) {
            $secret->setFolderId($this->nullableString(value: $data['folderId']));
        }

        if (array_key_exists('typeId', $data) === true) {
            $secret->setTypeId($this->typeService->resolveTypeForSecret($data['typeId'], $userId));
        }

        if (array_key_exists('key', $data) === true) {
            $key = (string) $data['key'];
            if ($key === '') {
                throw new InvalidArgumentException('Secret key cannot be empty');
            }

            // Stamp ciphertext age ONLY when the encrypted key blob actually
            // changes — a no-op re-submit of the same ciphertext (or a rename
            // that happens to also resend key) must not un-stale the credential
            // (password-health design D4). Pure string inequality; no decryption.
            if ($key !== $secret->getKey()) {
                $secret->setKey($key);
                $secret->setKeyUpdatedAt(new DateTime());
            }
        }

        if (array_key_exists('login', $data) === true) {
            $secret->setLogin($this->nullableString(value: $data['login']));
        }

        if (array_key_exists('additionalFields', $data) === true) {
            $secret->setAdditionalFields($this->nullableString(value: $data['additionalFields']));
        }

        $secret->setUpdatedAt(new DateTime());
        $this->mapper->update($secret);

        $changedFields = array_values(
            array_intersect(
                ['name', 'url', 'folderId', 'typeId', 'key', 'login', 'additionalFields'],
                array_keys($data)
            )
        );
        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SECRET_UPDATED,
                objectType: 'secret',
                objectId: $secret->getId(),
                objectName: $secret->getName(),
                metadata: ['changedFields' => $changedFields],
            )
        );

        return $secret;
    }//end update()

    /**
     * Delete a secret owned by the user, cascading to its link shares.
     *
     * @param string $id     The secret ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return void
     *
     * @throws NotFoundException When the secret does not exist
     * @throws ForbiddenException When the secret belongs to another user
     *
     * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.1
     */
    public function delete(string $id, string $userId): void
    {
        $secret = $this->loadOwned(id: $id, userId: $userId);

        // Cascade to derived link shares + secret requests + user shares
        // (ShareTargets) + group shares + secret delegations. The mappers
        // for group shares + delegations are optional dependencies so the
        // existing constructors without them keep compiling; when wired,
        // a secret delete leaves no orphan sharing-graph rows behind.
        $this->linkShareService->deleteBySecretId($id);
        if ($this->secretRequestService !== null) {
            $this->secretRequestService->deleteAllForSecret($id);
        }

        if ($this->shareService !== null) {
            $this->shareService->deleteAllForSecret($id);
        }

        if ($this->groupShareMapper !== null) {
            $this->groupShareMapper->deleteBySecret($id);
        }

        if ($this->secretDelegationMapper !== null) {
            $this->secretDelegationMapper->deleteBySecret($id);
        }

        $this->mapper->delete($secret);
        $this->logger->info("Doriath: secret {$id} deleted by {$userId}");

        $this->dispatchAudit(
            AuditEvent::forUser(
                actorId: $userId,
                eventType: AuditEventTypes::SECRET_DELETED,
                objectType: 'secret',
                objectId: $id,
                objectName: $secret->getName(),
            )
        );
    }//end delete()

    /**
     * List a user's secrets, paginated, optionally filtered by folder, sorted.
     *
     * Blocked secrets (revoked/compromised suite) are included with metadata
     * only — their encrypted blobs are omitted.
     *
     * @param string      $userId    The requesting Nextcloud user ID
     * @param string|null $folderId  The folder filter (null = no filter)
     * @param string|null $sort      The sort column
     * @param string      $direction The sort direction
     * @param int         $page      The 1-based page number
     * @param int         $limit     The page size
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, page: int, limit: int}
     */
    public function list(
        string $userId,
        ?string $folderId,
        ?string $sort,
        string $direction,
        int $page,
        int $limit,
    ): array {
        $limit  = $this->clampLimit(limit: $limit);
        $page   = max(1, $page);
        $offset = (($page - 1) * $limit);

        $secrets = $this->mapper->findByOwner('user', $userId, $folderId, $sort, $direction, $limit, $offset);
        $total   = $this->mapper->countByOwner('user', $userId, $folderId);

        return [
            'items' => array_map([$this, 'serialiseWithBlocking'], $secrets),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }//end list()

    /**
     * Search a user's secrets with two-stage fuzzy matching.
     *
     * @param string $userId The requesting Nextcloud user ID
     * @param string $term   The search term
     * @param int    $page   The 1-based page number
     * @param int    $limit  The page size
     *
     * @return array{items: array<int,array<string,mixed>>, total: int, page: int, limit: int}
     */
    public function search(string $userId, string $term, int $page, int $limit): array
    {
        $limit = $this->clampLimit(limit: $limit);
        $page  = max(1, $page);
        $term  = trim($term);

        if ($term === '') {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $matched = $this->fuzzyMatch(userId: $userId, term: $term);

        $total  = count($matched);
        $offset = (($page - 1) * $limit);
        $window = array_slice($matched, $offset, $limit);

        return [
            'items' => array_map([$this, 'serialiseWithBlocking'], $window),
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }//end search()

    /**
     * Resolve the matching secrets for a fuzzy search term: SQL substring
     * pre-filter merged with a PHP Levenshtein pass over all the user's
     * secrets, deduplicated by ID.
     *
     * @param string $userId The Nextcloud user ID
     * @param string $term   The search term
     *
     * @return Secret[]
     */
    public function fuzzyMatch(string $userId, string $term): array
    {
        $tolerance = 2;
        if (mb_strlen($term) <= 5) {
            $tolerance = 1;
        }

        $termLower = mb_strtolower($term);

        $matched = [];

        // Stage 1: SQL substring pre-filter.
        foreach ($this->mapper->searchByNameOrUrl('user', $userId, $term) as $secret) {
            $matched[$secret->getId()] = $secret;
        }

        // Stage 2: Levenshtein post-filter over the full set.
        $total = $this->mapper->countByOwner('user', $userId, null);
        $all   = $this->mapper->findByOwner('user', $userId, null, 'name', 'asc', max(1, $total), 0);

        foreach ($all as $secret) {
            if (isset($matched[$secret->getId()]) === true) {
                continue;
            }

            if ($this->isFuzzyHit(secret: $secret, termLower: $termLower, tolerance: $tolerance) === true) {
                $matched[$secret->getId()] = $secret;
            }
        }

        return array_values($matched);
    }//end fuzzyMatch()

    /**
     * Decide whether a secret's name or url is within Levenshtein tolerance of
     * the term. Also checks per-word distance so a typo in one token of a
     * longer name still matches.
     *
     * @param Secret $secret    The secret to test
     * @param string $termLower The lowercase search term
     * @param int    $tolerance The maximum Levenshtein distance
     *
     * @return bool
     */
    private function isFuzzyHit(Secret $secret, string $termLower, int $tolerance): bool
    {
        $candidates = [mb_strtolower($secret->getName())];
        if ($secret->getUrl() !== null && $secret->getUrl() !== '') {
            $candidates[] = mb_strtolower((string) $secret->getUrl());
        }

        foreach ($candidates as $candidate) {
            if (levenshtein($termLower, $candidate) <= $tolerance) {
                return true;
            }

            $words = preg_split('/[\s\/\.\-_]+/', $candidate);
            if ($words === false) {
                $words = [];
            }

            foreach ($words as $word) {
                if ($word === '') {
                    continue;
                }

                if (levenshtein($termLower, $word) <= $tolerance) {
                    return true;
                }
            }
        }

        return false;
    }//end isFuzzyHit()

    /**
     * Serialise a secret, withholding encrypted blobs when its suite blocks.
     *
     * @param Secret $secret The secret
     *
     * @return array<string,mixed>
     *
     * @SuppressWarnings(PHPMD.UnusedPrivateMethod) Invoked as a callable via
     *   array_map([$this, 'serialiseWithBlocking'], ...) in list()/search().
     */
    private function serialiseWithBlocking(Secret $secret): array
    {
        $reason = $this->suiteBlockReason(secret: $secret);
        if ($reason !== null) {
            return $secret->jsonSerializeBlocked($reason);
        }

        return $secret->jsonSerialize();
    }//end serialiseWithBlocking()

    /**
     * Determine whether a secret's encryption suite blocks access, returning
     * the human-readable reason or null when accessible.
     *
     * @param Secret $secret The secret
     *
     * @return string|null The block reason, or null if accessible
     */
    private function suiteBlockReason(Secret $secret): ?string
    {
        try {
            $suite = $this->suiteMapper->findById($secret->getEncryptionSuiteId());
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            return 'Encryption suite not found';
        }

        if (in_array($suite->getStatus(), self::BLOCKING_STATUSES, true) === true) {
            return 'Encryption suite is '.$suite->getStatus();
        }

        return null;
    }//end suiteBlockReason()

    /**
     * Load a secret and verify the requester owns it.
     *
     * @param string $id     The secret ID
     * @param string $userId The requesting Nextcloud user ID
     *
     * @return Secret
     *
     * @throws NotFoundException When the secret does not exist
     * @throws ForbiddenException When the secret belongs to another user
     */
    private function loadOwned(string $id, string $userId): Secret
    {
        try {
            $secret = $this->mapper->findById($id);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new NotFoundException(message: 'Secret not found');
        }

        if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
            throw new ForbiddenException(message: 'Secret belongs to another user');
        }

        return $secret;
    }//end loadOwned()

    /**
     * Assert the user has an active encryption suite, or block.
     *
     * Public probe used by the batch-import commit (ImportService) so a whole
     * chunk fails fast with a 412 when the user cannot create secrets, rather
     * than each item throwing the same block independently. Reuses the same
     * suite resolution as create() — no duplicated suite logic.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return void
     *
     * @throws SuiteBlockedException When no active suite exists
     *
     * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
     */
    public function assertActiveSuite(string $userId): void
    {
        $this->getActiveSuiteOrBlock(userId: $userId);
    }//end assertActiveSuite()

    /**
     * Resolve the user's active encryption suite or block with a 403.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return EncryptionSuite
     *
     * @throws SuiteBlockedException When no active suite exists
     */
    private function getActiveSuiteOrBlock(string $userId): EncryptionSuite
    {
        try {
            return $this->suiteMapper->findActiveByOwner('user', $userId);
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            throw new SuiteBlockedException(message: 'No active encryption suite — it may be revoked or not yet created');
        }
    }//end getActiveSuiteOrBlock()

    /**
     * Throw a WriteLockedException when the owner has an in-progress migration.
     *
     * @param string $userId The Nextcloud user ID
     *
     * @return void
     *
     * @throws WriteLockedException When the owner is write-locked
     */
    private function assertNotWriteLocked(string $userId): void
    {
        if ($this->migrationService->isWriteLocked('user', $userId) === true) {
            throw new WriteLockedException(message: 'A compromise-recovery migration is in progress');
        }
    }//end assertNotWriteLocked()

    /**
     * Clamp a requested limit into the allowed range.
     *
     * @param int $limit The requested limit
     *
     * @return int
     */
    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }//end clampLimit()

    /**
     * Normalise a value to a non-empty string or null.
     *
     * @param mixed $value The raw value
     *
     * @return string|null
     */
    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;
        if ($value === '') {
            return null;
        }

        return $value;
    }//end nullableString()
}//end class
