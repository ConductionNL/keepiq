<?php

/**
 * Keepiq Source Blob
 *
 * One attachment blob under the old namespace, with its name and size.
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

use OCP\Files\SimpleFS\ISimpleFile;

/**
 * The blob MoveAttachmentBlobs is moving, its name and the size read once
 * before the move, which the copy is verified against.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
 */
final class SourceBlob {
	/**
	 * Constructor for SourceBlob.
	 *
	 * @param ISimpleFile $file The blob
	 * @param string      $name Its file name
	 * @param int|float   $size Its size in bytes, as read before the move
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ISimpleFile $file,
		public readonly string $name,
		public readonly int|float $size,
	) {
	}//end __construct()
}//end class
