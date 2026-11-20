<?php

/**
 * Keepiq Share Source Version
 *
 * The source version a share row says its ciphertext was encrypted from, and whether that is still the source's
 * current version (keepiq#1255).
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;

/**
 * Reads and compares the `sourceUpdatedAt` of a register-batch or team-folder share row.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
 */
final class ShareSourceVersion {
	/**
	 * The version a row names: null when it names none (an older client), '' when it names one that is not
	 * a moment.
	 *
	 * @param array<string,mixed> $row The submitted row
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public static function sent(array $row): ?string {
		$sent = ($row['sourceUpdatedAt'] ?? null);
		if ($sent === null) {
			return null;
		}

		if (is_string($sent) === false || strtotime($sent) === false) {
			return '';
		}

		return $sent;
	}//end sent()

	/**
	 * Whether a sent version is the source's current one, compared in whole seconds as the column stores it.
	 *
	 * @param string $sent A version sent() accepted
	 * @param DateTime|null $current The source's version under its row lock, null when the source is gone
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-is-made-from-the-current-version
	 */
	public static function isCurrent(string $sent, ?DateTime $current): bool {
		return $current !== null && $current->getTimestamp() === (new DateTime($sent))->getTimestamp();
	}//end isCurrent()
}//end class
