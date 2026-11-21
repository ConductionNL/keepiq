<?php

/**
 * Keepiq Import Chunk Run
 *
 * The user and the folder bookkeeping of one import chunk.
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

/**
 * The session user of one import chunk, the folder ids its paths resolved to
 * and the paths it created; built by ImportService per chunk and never kept.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
 */
final class ImportChunkRun {
	/**
	 * Folder path -> folder id, for the paths resolved so far.
	 *
	 * @var array<string,?string>
	 */
	private array $folderIds = [];

	/**
	 * The folder paths this chunk created, in creation order.
	 *
	 * @var string[]
	 */
	private array $createdPaths = [];

	/**
	 * Constructor for ImportChunkRun.
	 *
	 * @param string $userId The session user id
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $userId,
	) {
	}//end __construct()

	/**
	 * Whether a folder path has been resolved in this chunk.
	 *
	 * @param string $path The slash-joined folder path
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	public function hasFolder(string $path): bool {
		return array_key_exists($path, $this->folderIds);
	}//end hasFolder()

	/**
	 * The folder id a path resolved to.
	 *
	 * @param string $path The slash-joined folder path
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	public function folderAt(string $path): ?string {
		return $this->folderIds[$path];
	}//end folderAt()

	/**
	 * Remember the folder a path resolved to.
	 *
	 * @param string $path The slash-joined folder path
	 * @param string $folderId The folder id
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	public function remember(string $path, string $folderId): void {
		$this->folderIds[$path] = $folderId;
	}//end remember()

	/**
	 * Record a path this chunk created a folder for.
	 *
	 * @param string $path The slash-joined folder path
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	public function markCreated(string $path): void {
		$this->createdPaths[] = $path;
	}//end markCreated()

	/**
	 * The distinct paths this chunk created folders for.
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
	 */
	public function createdPaths(): array {
		return array_values(array_unique($this->createdPaths));
	}//end createdPaths()
}//end class
