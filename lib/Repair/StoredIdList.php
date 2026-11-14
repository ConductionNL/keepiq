<?php

/**
 * Keepiq Stored Id List
 *
 * Reads the JSON id lists OpenRegister stores on registers and configurations.
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

/**
 * Decodes a stored id list and picks or removes its numeric ids, leaving
 * every other entry exactly as stored.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
 */
final class StoredIdList {
	/**
	 * Decode a stored JSON id list.
	 *
	 * @param mixed $stored The stored value, or null
	 *
	 * @return array<int,mixed> The list, as stored
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function decode(mixed $stored): array {
		$list = json_decode((string)($stored ?? '[]'), true);
		if (is_array($list) === false) {
			return [];
		}

		return array_values($list);
	}//end decode()

	/**
	 * The numeric ids in a list.
	 *
	 * @param array<int,mixed> $list A stored id list
	 *
	 * @return int[] The numeric entries, as ints
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function numericIds(array $list): array {
		$ids = [];
		foreach ($list as $entry) {
			if ($this->isNumericId(entry: $entry) === true) {
				$ids[] = (int)$entry;
			}
		}

		return $ids;
	}//end numericIds()

	/**
	 * Whether a list entry is a numeric id.
	 *
	 * @param mixed $entry A list entry
	 *
	 * @return bool True for an int or a digit string
	 */
	private function isNumericId(mixed $entry): bool {
		return is_int($entry) === true || (is_string($entry) === true && ctype_digit($entry) === true);
	}//end isNumericId()

	/**
	 * A stored id list without the given numeric ids; other entries untouched.
	 *
	 * @param array<int,mixed> $list The stored list
	 * @param int[]            $ids  The ids to remove
	 *
	 * @return array<int,mixed> The remaining entries, re-indexed
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-legacy-openregister-rows-are-removed-when-empty
	 */
	public function without(array $list, array $ids): array {
		return array_values(
			array_filter(
				$list,
				fn (mixed $entry): bool => $this->isNumericId(entry: $entry) === false
					|| in_array((int)$entry, $ids, true) === false
			)
		);
	}//end without()
}//end class
