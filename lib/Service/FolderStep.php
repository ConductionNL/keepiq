<?php

/**
 * Keepiq Folder Step
 *
 * One segment of an imported folder path.
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
 * One folder of an imported path: its name, the folder it sits in and the
 * slash-joined path up to and including it.
 *
 * @spec openspec/specs/secret-import/spec.md#requirement-chunked-batch-commit
 */
final class FolderStep {
	/**
	 * Constructor for FolderStep.
	 *
	 * @param string $name The folder name
	 * @param string|null $parentId The parent folder id (null = root)
	 * @param string $path The slash-joined path up to and including this folder
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $name,
		public readonly ?string $parentId,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
