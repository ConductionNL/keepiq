<?php

/**
 * Keepiq Secret Tag Normaliser
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

use InvalidArgumentException;

/**
 * Turns the tags a user typed into the stored form
 * (vault-favourites-tags-and-last-used D2).
 */
class SecretTagNormaliser {
	/**
	 * The longest tag, in characters.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 32;

	/**
	 * The most tags one secret may carry.
	 *
	 * @var int
	 */
	public const MAX_TAGS = 20;

	/**
	 * Trim, lowercase, drop empty values and duplicates (first one wins).
	 *
	 * @param array<mixed> $tags The tags as sent
	 *
	 * @return list<string>
	 *
	 * @throws InvalidArgumentException When a tag is not text, is too long, or there are too many
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function normalise(array $tags): array {
		$normalised = [];
		foreach ($tags as $tag) {
			if (is_string($tag) === false) {
				throw new InvalidArgumentException('A tag must be text');
			}

			$tag = mb_strtolower(trim($tag));
			if ($tag === '') {
				continue;
			}

			if (mb_strlen($tag) > self::MAX_LENGTH) {
				throw new InvalidArgumentException('A tag can be at most '.self::MAX_LENGTH.' characters');
			}

			$normalised[$tag] = $tag;
		}

		if (count($normalised) > self::MAX_TAGS) {
			throw new InvalidArgumentException('A secret can have at most '.self::MAX_TAGS.' tags');
		}

		return array_values($normalised);
	}//end normalise()
}//end class
