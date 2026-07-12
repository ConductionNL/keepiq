<?php

/**
 * Doriath Import Service
 *
 * Server side of the client-side import pipeline (secret-import D7). The browser
 * parses, maps, deduplicates, and ENCRYPTS every sensitive field locally, then
 * POSTs ciphertext-only payloads here in bounded chunks. This service:
 *
 *   - ensures the requested folder paths exist (idempotently, owner-scoped),
 *   - delegates each item to SecretService::create (the same create path the
 *     normal UI uses — no duplicated persistence logic),
 *   - returns per-index results so one invalid item never fails its neighbours.
 *
 * The server never receives plaintext secret values: key/login/additionalFields
 * arrive as RSA ciphertext envelopes and are stored verbatim (ADR-003). Ownership
 * is derived exclusively from the caller-supplied session user id — no owner
 * field is accepted from the request (ADR-005, IDOR-safe by construction).
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

use InvalidArgumentException;
use OCA\Doriath\Exception\SuiteBlockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Server side of the chunked encrypted-import commit.
 */
class ImportService
{
    /**
     * Maximum items accepted in a single batch request.
     *
     * @var int
     */
    public const MAX_ITEMS = 100;

    /**
     * Maximum byte length of a single plaintext metadata field (name/url).
     *
     * @var int
     */
    public const MAX_FIELD_LENGTH = 4096;

    /**
     * Maximum byte length of a single ciphertext blob (RSA-expanded).
     *
     * @var int
     */
    public const MAX_BLOB_LENGTH = 65536;

    /**
     * Constructor for ImportService.
     *
     * @param SecretService   $secretService The secret create delegate
     * @param FolderService   $folderService The folder ensure delegate
     * @param LoggerInterface $logger        The logger
     *
     * @return void
     */
    public function __construct(
        private SecretService $secretService,
        private FolderService $folderService,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Commit one chunk of already-encrypted items for the session user.
     *
     * Folders are ensured before items so an item can reference a freshly
     * created folder; a path with zero successful items is still ensured only
     * when at least one of its items is committed (folders are ensured lazily
     * per item, never for all-failed paths).
     *
     * @param array<int,array<string,mixed>> $items  The encrypted items
     * @param string                         $userId The session user id
     *
     * @return array{results: array<int,array<string,mixed>>, foldersCreated: string[]}
     *
     * @throws SuiteBlockedException When the user has no active EncryptionSuite
     * @throws InvalidArgumentException When the chunk exceeds the item cap
     *
     * @spec openspec/changes/secret-import/specs/secret-import/spec.md#requirement-chunked-batch-commit
     * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
     */
    public function commitChunk(array $items, string $userId): array
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new InvalidArgumentException(
                'Chunk exceeds the maximum of '.self::MAX_ITEMS.' items'
            );
        }

        // 412-equivalent guard: a user with no active suite cannot create
        // secrets. Probe once here so the whole chunk fails fast and honestly
        // rather than each item independently throwing the same block.
        $this->secretService->assertActiveSuite($userId);

        // Per-request folder-path cache: "Work/CI" -> folder id.
        $folderCache    = [];
        $foldersCreated = [];

        $results = [];
        foreach ($items as $index => $item) {
            try {
                $this->validateItem(item: $item);
                $folderId = $this->ensureFolderPath(
                    pathSegments: (array) ($item['folderPath'] ?? []),
                    userId: $userId,
                    cache: $folderCache,
                    created: $foldersCreated,
                );

                $secret = $this->secretService->create(
                    [
                        'name'             => $item['name'],
                        'key'              => $item['key'],
                        'url'              => ($item['url'] ?? null),
                        'folderId'         => $folderId,
                        'login'            => ($item['login'] ?? null),
                        'additionalFields' => ($item['additionalFields'] ?? null),
                        // Optional secret-type id (UUID). Carries an imported
                        // TOTP seed into a `totp`-typed secret (add-totp-secrets
                        // D6); null resolves to the `login` default. The type is
                        // a UI hint only — the seed is ciphertext in `key`.
                        'typeId'           => ($item['typeId'] ?? null),
                    ],
                    $userId
                );

                $results[] = [
                    'index'    => $index,
                    'status'   => 'created',
                    'secretId' => $secret->getId(),
                ];
            } catch (SuiteBlockedException $e) {
                // No active suite is a whole-request condition; re-throw so the
                // controller maps it to 412 rather than reporting it per-item.
                throw $e;
            } catch (Throwable $e) {
                $results[] = [
                    'index'  => $index,
                    'status' => 'failed',
                    'error'  => $e->getMessage(),
                ];
            }//end try
        }//end foreach

        $createdCount = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'created'));
        $this->logger->debug(
            'Doriath: import chunk committed '.$createdCount.' of '.count($items).' items for user '.$userId
        );

        return [
            'results'        => $results,
            'foldersCreated' => array_values(array_unique($foldersCreated)),
        ];
    }//end commitChunk()

    /**
     * Validate one encrypted item's metadata + ciphertext envelope shape.
     *
     * Rejects plaintext-shaped sensitive fields: key/login/additionalFields MUST
     * look like an envelope, never plaintext. A missing/oversized name, an
     * oversized url, or an oversized blob is rejected.
     *
     * @param array<string,mixed> $item The encrypted item
     *
     * @return void
     *
     * @throws InvalidArgumentException When the item is invalid
     */
    private function validateItem(array $item): void
    {
        $name = trim((string) ($item['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Missing name');
        }

        if (strlen($name) > self::MAX_FIELD_LENGTH) {
            throw new InvalidArgumentException('Name exceeds the maximum length');
        }

        if (isset($item['url']) === true && strlen((string) $item['url']) > self::MAX_FIELD_LENGTH) {
            throw new InvalidArgumentException('URL exceeds the maximum length');
        }

        $key = (string) ($item['key'] ?? '');
        if ($key === '') {
            throw new InvalidArgumentException('Missing encrypted key');
        }

        foreach (['key', 'login', 'additionalFields'] as $field) {
            // The isset() check is already false for a null value, so a
            // separate null comparison is redundant.
            if (isset($item[$field]) === false) {
                continue;
            }

            $this->assertCiphertext(field: $field, value: (string) $item[$field]);
        }
    }//end validateItem()

    /**
     * Assert a sensitive field is an encrypted envelope, not plaintext.
     *
     * The crypto envelope is base64 ciphertext; it is never human-readable
     * plaintext. We bound the length and require a base64-ish charset so an
     * obvious plaintext value (e.g. "hunter2") is refused — a defence-in-depth
     * check that the client encrypted before POSTing (ADR-003).
     *
     * @param string $field The field name (for the error message)
     * @param string $value The candidate ciphertext blob
     *
     * @return void
     *
     * @throws InvalidArgumentException When the value is not a ciphertext envelope
     */
    private function assertCiphertext(string $field, string $value): void
    {
        if (strlen($value) > self::MAX_BLOB_LENGTH) {
            throw new InvalidArgumentException($field.' ciphertext exceeds the maximum length');
        }

        // A real envelope is non-trivial base64; reject short or non-base64 input
        // that betrays plaintext slipping past the client encryption step.
        if (strlen($value) < 16 || preg_match('#^[A-Za-z0-9+/=._:{}",\s-]+$#', $value) !== 1) {
            throw new InvalidArgumentException($field.' is not a valid ciphertext envelope');
        }
    }//end assertCiphertext()

    /**
     * Ensure a slash-path of folders exists for the user, returning the leaf id.
     *
     * Resolves each segment against the user's owned folders; creates missing
     * segments via FolderService (owner-scoped). Results are cached per request
     * so sibling items reuse the same ids and folders are created at most once.
     *
     * @param string[]              $pathSegments The folder path segments
     * @param string                $userId       The session user id
     * @param array<string,?string> $cache        Per-request path -> id cache
     * @param string[]              $created      Accumulator of created paths
     *
     * @return string|null The leaf folder id, or null for a root-level item
     */
    private function ensureFolderPath(array $pathSegments, string $userId, array &$cache, array &$created): ?string
    {
        $segments = [];
        foreach ($pathSegments as $segment) {
            $name = trim((string) $segment);
            if ($name !== '') {
                $segments[] = $name;
            }
        }

        if (count($segments) === 0) {
            return null;
        }

        $parentId    = null;
        $accumulated = '';
        foreach ($segments as $segment) {
            if ($accumulated === '') {
                $accumulated = $segment;
            } else {
                $accumulated = $accumulated.'/'.$segment;
            }

            if (array_key_exists($accumulated, $cache) === true) {
                $parentId = $cache[$accumulated];
                continue;
            }

            $existing = $this->findOwnedFolder(name: $segment, parentId: $parentId, userId: $userId);
            if ($existing !== null) {
                $parentId            = $existing;
                $cache[$accumulated] = $existing;
                continue;
            }

            $folder   = $this->folderService->create(name: $segment, parentId: $parentId, userId: $userId);
            $parentId = $folder->getId();
            $cache[$accumulated] = $parentId;
            $created[]           = $accumulated;
        }//end foreach

        return $parentId;
    }//end ensureFolderPath()

    /**
     * Find a user-owned folder by name under a given parent, or null.
     *
     * @param string      $name     The folder name
     * @param string|null $parentId The parent folder id (null = root)
     * @param string      $userId   The session user id
     *
     * @return string|null The matching folder id, or null when absent
     */
    private function findOwnedFolder(string $name, ?string $parentId, string $userId): ?string
    {
        foreach ($this->folderService->listForUser(userId: $userId) as $folder) {
            if ($folder->getName() === $name && $folder->getParentId() === $parentId) {
                return $folder->getId();
            }
        }

        return null;
    }//end findOwnedFolder()
}//end class
