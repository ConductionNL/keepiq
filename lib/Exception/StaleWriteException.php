<?php

/**
 * Keepiq StaleWriteException
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use DateTime;
use OCA\Keepiq\Db\Secret;
use RuntimeException;

/**
 * Thrown when a write names the version it was based on (`baseUpdatedAt`) and
 * the secret changed since, or when the secret changed between the write's
 * read and its write. Nothing was written; the caller answers 409 with
 * the current row so the client can let the user choose (offline-edit-queue).
 *
 * @spec openspec/specs/offline-edit-queue/spec.md#requirement-concurrent-server-changes-are-never-overwritten-silently
 */
class StaleWriteException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param Secret $current The secret as it is now
	 * @param DateTime|null $sourceUpdatedAt The source version the conflict stands on, when the secret is a copy saved through a share-save
	 *
	 * @return void
	 */
	public function __construct(private Secret $current, private ?DateTime $sourceUpdatedAt = null) {
		parent::__construct(message: 'This secret changed since your copy was made');
	}//end __construct()

	/**
	 * The secret as it is now.
	 *
	 * @return Secret
	 */
	public function getCurrent(): Secret {
		return $this->current;
	}//end getCurrent()

	/**
	 * The source version a copy-editor's conflict stands on: the source as the
	 * copy was written with it, or else the edit's own source base, so the
	 * client can keep it with the conflict; null on every other write.
	 *
	 * @return DateTime|null
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
	 */
	public function getSourceUpdatedAt(): ?DateTime {
		return $this->sourceUpdatedAt;
	}//end getSourceUpdatedAt()
}//end class
