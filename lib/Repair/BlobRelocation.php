<?php

/**
 * Keepiq Blob Relocation
 *
 * The target folder, the output and the tally of one attachment blob move.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Repair;

use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;

/**
 * Built by MoveAttachmentBlobs once it has a target folder and never kept.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
 */
final class BlobRelocation {
	/**
	 * Blobs that now live only in the target.
	 *
	 * @var int
	 */
	public int $moved = 0;

	/**
	 * Blobs left in place.
	 *
	 * @var int
	 */
	public int $skipped = 0;

	/**
	 * Constructor for BlobRelocation.
	 *
	 * @param ISimpleFolder $target The destination folder
	 * @param IOutput       $output Repair output
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ISimpleFolder $target,
		public readonly IOutput $output,
	) {
	}//end __construct()

	/**
	 * Count one blob as moved or skipped.
	 *
	 * @param bool $moved True when the blob now lives only in the target
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
	 */
	public function count(bool $moved): void {
		if ($moved === true) {
			$this->moved++;
			return;
		}

		$this->skipped++;
	}//end count()
}//end class
