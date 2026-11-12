<?php

/**
 * Keepiq Import Service
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
 * The server never receives plaintext secret values: key/login/additionalFields/
 * totp/passkeys arrive as RSA ciphertext envelopes and are stored verbatim (ADR-003). Ownership
 * is derived exclusively from the caller-supplied session user id — no owner
 * field is accepted from the request (ADR-005, IDOR-safe by construction).
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\SuiteBlockedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Server side of the chunked encrypted-import commit.
 */
class ImportService {
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
	 * Maximum character length of a name: the width of the `name` column.
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 255;

	/**
	 * Maximum byte length of a single ciphertext blob (RSA-expanded).
	 *
	 * @var int
	 */
	public const MAX_BLOB_LENGTH = 65536;

	/**
	 * The smallest RSA block a ciphertext chunk can be, in bytes (RSA-2048).
	 *
	 * @var int
	 */
	public const MIN_RSA_BLOCK = 256;

	/**
	 * Constructor for ImportService.
	 *
	 * @param SecretService $secretService The secret create delegate
	 * @param FolderService $folderService The folder ensure delegate
	 * @param LoggerInterface $logger The logger
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
	 * @param array<int,array<string,mixed>> $items The encrypted items
	 * @param string $userId The session user id
	 *
	 * @return array{results: array<int,array<string,mixed>>, foldersCreated: string[]}
	 *
	 * @throws SuiteBlockedException When the user has no active EncryptionSuite
	 * @throws InvalidArgumentException When the chunk exceeds the item cap
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-secret-types
	 */
	public function commitChunk(array $items, string $userId): array {
		if (count($items) > self::MAX_ITEMS) {
			throw new InvalidArgumentException(
				'Chunk exceeds the maximum of ' . self::MAX_ITEMS . ' items'
			);
		}

		// 412-equivalent guard: a user with no active suite cannot create
		// secrets. Probe once here so the whole chunk fails fast and honestly
		// rather than each item independently throwing the same block.
		$this->secretService->assertActiveSuite($userId);

		$run = new ImportChunkRun(userId: $userId);
		$results = [];
		foreach ($items as $index => $item) {
			$results[] = ['index' => $index] + $this->commitItem(run: $run, item: $item);
		}

		$createdCount = count(array_filter($results, static fn (array $r): bool => $r['status'] === 'created'));
		$this->logger->debug(
			'Keepiq: import chunk committed ' . $createdCount . ' of ' . count($items) . ' items for user ' . $userId
		);

		return [
			'results' => $results,
			'foldersCreated' => $run->createdPaths(),
		];
	}//end commitChunk()

	/**
	 * Commit one item, or report why it failed.
	 *
	 * @param ImportChunkRun $run The chunk being committed
	 * @param array<string,mixed> $item The encrypted item
	 *
	 * @return array<string,mixed> The item's status and secret id, or its error
	 *
	 * @throws SuiteBlockedException When the user has no active EncryptionSuite
	 */
	private function commitItem(ImportChunkRun $run, array $item): array {
		try {
			return [
				'status' => 'created',
				'secretId' => $this->createSecret(run: $run, item: $item)->getId(),
			];
		} catch (SuiteBlockedException $e) {
			// No active suite is a whole-request condition; re-throw so the
			// controller maps it to 412 rather than reporting it per-item.
			throw $e;
		} catch (Throwable $e) {
			return [
				'status' => 'failed',
				'error' => $e->getMessage(),
			];
		}
	}//end commitItem()

	/**
	 * Validate one item, ensure its folder path and create its secret.
	 *
	 * @param ImportChunkRun $run The chunk being committed
	 * @param array<string,mixed> $item The encrypted item
	 *
	 * @return Secret The created secret
	 */
	private function createSecret(ImportChunkRun $run, array $item): Secret {
		$this->validateItem(item: $item);
		$folderId = $this->ensureFolderPath(run: $run, pathSegments: (array)($item['folderPath'] ?? []));

		return $this->secretService->create($this->secretData(item: $item, folderId: $folderId), $run->userId);
	}//end createSecret()

	/**
	 * The secret-create input of one item.
	 *
	 * @param array<string,mixed> $item The encrypted item
	 * @param string|null $folderId The item's folder id (null = root)
	 *
	 * @return array<string,mixed>
	 */
	private function secretData(array $item, ?string $folderId): array {
		return [
			'name' => $item['name'],
			'key' => $item['key'],
			'url' => ($item['url'] ?? null),
			'folderId' => $folderId,
			'login' => ($item['login'] ?? null),
			'additionalFields' => ($item['additionalFields'] ?? null),
			// Optional secret-type id (UUID). Carries an imported
			// TOTP seed into a `totp`-typed secret (add-totp-secrets
			// D6); null resolves to the `login` default. The type is
			// a UI hint only — the seed is ciphertext in `key`.
			'typeId' => ($item['typeId'] ?? null),
			'totp' => ($item['totp'] ?? null),
			'passkeys' => ($item['passkeys'] ?? null),
		];
	}//end secretData()

	/**
	 * Validate one encrypted item's metadata + ciphertext envelope shape.
	 *
	 * Rejects plaintext-shaped sensitive fields: key/login/additionalFields/totp/
	 * passkeys MUST look like an envelope, never plaintext. A missing/oversized name, an
	 * oversized url, or an oversized blob is rejected.
	 *
	 * @param array<string,mixed> $item The encrypted item
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the item is invalid
	 *
	 * @spec openspec/changes/clients-extension-gaps/specs/item-name-limit/spec.md#requirement-a-name-has-at-most-255-characters
	 */
	private function validateItem(array $item): void {
		$name = trim((string)($item['name'] ?? ''));
		if ($name === '') {
			throw new InvalidArgumentException('Missing name');
		}

		// The name column holds 255 characters; a longer name would fail at
		// the database instead of here.
		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('Name exceeds the maximum length of 255 characters');
		}

		if (isset($item['url']) === true && strlen((string)$item['url']) > self::MAX_FIELD_LENGTH) {
			throw new InvalidArgumentException('URL exceeds the maximum length');
		}

		$key = (string)($item['key'] ?? '');
		if ($key === '') {
			throw new InvalidArgumentException('Missing encrypted key');
		}

		$this->assertEncryptedFields(item: $item);
	}//end validateItem()

	/**
	 * Assert every sensitive field the item carries is a ciphertext envelope.
	 *
	 * @param array<string,mixed> $item The encrypted item
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a field is not a ciphertext envelope
	 */
	private function assertEncryptedFields(array $item): void {
		foreach (['key', 'login', 'additionalFields'] as $field) {
			// The isset() check is already false for a null value, so a
			// separate null comparison is redundant.
			if (isset($item[$field]) === false) {
				continue;
			}

			$this->assertCiphertext(field: $field, value: (string)$item[$field]);
		}

		// `''` means "not set" for these two, as SecretService::create stores it.
		foreach (['totp', 'passkeys'] as $field) {
			if (isset($item[$field]) === false || $item[$field] === '') {
				continue;
			}

			$this->assertCiphertext(field: $field, value: (string)$item[$field]);
			$this->assertRsaChunks(field: $field, value: (string)$item[$field]);
		}
	}//end assertEncryptedFields()

	/**
	 * Assert a value has the shape `rsaEncrypt()` gives every field: base64 of
	 * a big-endian chunk count followed by that many whole RSA blocks. A TOTP
	 * seed is itself base32 and passes the charset check above, so these two
	 * fields need the stricter test.
	 *
	 * @param string $field The field name (for the error message)
	 * @param string $value The candidate ciphertext blob
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value is not chunked RSA ciphertext
	 */
	private function assertRsaChunks(string $field, string $value): void {
		$raw = base64_decode($value, true);
		if ($raw === false || strlen($raw) < 4 + self::MIN_RSA_BLOCK) {
			throw new InvalidArgumentException($field . ' is not a valid ciphertext envelope');
		}

		$chunks = (int)unpack('N', substr($raw, 0, 4))[1];
		$body = strlen($raw) - 4;
		if ($chunks < 1 || $body % $chunks !== 0 || intdiv($body, $chunks) < self::MIN_RSA_BLOCK) {
			throw new InvalidArgumentException($field . ' is not a valid ciphertext envelope');
		}
	}//end assertRsaChunks()

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
	private function assertCiphertext(string $field, string $value): void {
		if (strlen($value) > self::MAX_BLOB_LENGTH) {
			throw new InvalidArgumentException($field . ' ciphertext exceeds the maximum length');
		}

		// A real envelope is non-trivial base64; reject short or non-base64 input
		// that betrays plaintext slipping past the client encryption step.
		if (strlen($value) < 16 || preg_match('#^[A-Za-z0-9+/=._:{}",\s-]+$#', $value) !== 1) {
			throw new InvalidArgumentException($field . ' is not a valid ciphertext envelope');
		}
	}//end assertCiphertext()

	/**
	 * Ensure a slash-path of folders exists for the user, returning the leaf id.
	 *
	 * Resolves each segment against the user's owned folders; creates missing
	 * segments via FolderService (owner-scoped). Results are cached per request
	 * so sibling items reuse the same ids and folders are created at most once.
	 *
	 * @param ImportChunkRun $run The chunk being committed, with its folder cache
	 * @param array<int|string,mixed> $pathSegments The folder path segments
	 *
	 * @return string|null The leaf folder id, or null for a root-level item
	 */
	private function ensureFolderPath(ImportChunkRun $run, array $pathSegments): ?string {
		$parentId = null;
		$accumulated = '';
		foreach ($this->segmentsOf(pathSegments: $pathSegments) as $segment) {
			$accumulated = $this->joinedPath(accumulated: $accumulated, segment: $segment);

			if ($run->hasFolder(path: $accumulated) === true) {
				$parentId = $run->folderAt(path: $accumulated);
				continue;
			}

			$parentId = $this->resolveFolder(
				run: $run,
				step: new FolderStep(name: $segment, parentId: $parentId, path: $accumulated)
			);
		}

		return $parentId;
	}//end ensureFolderPath()

	/**
	 * The trimmed, non-empty segments of a folder path.
	 *
	 * @param array<int|string,mixed> $pathSegments The raw path segments
	 *
	 * @return string[]
	 */
	private function segmentsOf(array $pathSegments): array {
		$segments = [];
		foreach ($pathSegments as $segment) {
			$name = trim((string)$segment);
			if ($name !== '') {
				$segments[] = $name;
			}
		}

		return $segments;
	}//end segmentsOf()

	/**
	 * A path with one more segment.
	 *
	 * @param string $accumulated The path so far ('' at the root)
	 * @param string $segment The next segment
	 *
	 * @return string
	 */
	private function joinedPath(string $accumulated, string $segment): string {
		if ($accumulated === '') {
			return $segment;
		}

		return $accumulated . '/' . $segment;
	}//end joinedPath()

	/**
	 * The id of the folder for one path step: the user's existing folder, or
	 * one created for it. Either is remembered for the rest of the chunk.
	 *
	 * @param ImportChunkRun $run The chunk being committed
	 * @param FolderStep $step The folder to find or create
	 *
	 * @return string The folder id
	 */
	private function resolveFolder(ImportChunkRun $run, FolderStep $step): string {
		$existing = $this->findOwnedFolder(step: $step, userId: $run->userId);
		if ($existing !== null) {
			$run->remember(path: $step->path, folderId: $existing);
			return $existing;
		}

		$folder = $this->folderService->create(name: $step->name, parentId: $step->parentId, userId: $run->userId);
		$run->remember(path: $step->path, folderId: $folder->getId());
		$run->markCreated(path: $step->path);

		return $folder->getId();
	}//end resolveFolder()

	/**
	 * Find a user-owned folder by name under a given parent, or null.
	 *
	 * @param FolderStep $step The folder name and the parent it sits in
	 * @param string $userId The session user id
	 *
	 * @return string|null The matching folder id, or null when absent
	 */
	private function findOwnedFolder(FolderStep $step, string $userId): ?string {
		foreach ($this->folderService->listForUser(userId: $userId) as $folder) {
			if ($folder->getName() === $step->name && $folder->getParentId() === $step->parentId) {
				return $folder->getId();
			}
		}

		return null;
	}//end findOwnedFolder()
}//end class
