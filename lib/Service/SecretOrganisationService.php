<?php

/**
 * Keepiq Secret Organisation Service
 *
 * Favourites, tags and last used on a holder's own secret rows
 * (vault-favourites-tags-and-last-used). Every action is the holder's
 * alone: a row that does not exist, belongs to someone else or to an
 * application answers the same not-found, so the endpoints cannot be used
 * to probe for other users' secrets.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * The holder's star, tags and last-used time on their own rows.
 */
class SecretOrganisationService {
	/**
	 * Constructor for SecretOrganisationService.
	 *
	 * @param SecretMapper        $mapper     The secret mapper
	 * @param SecretTagMapper     $tagMapper  The tag mapper
	 * @param SecretTagNormaliser $normaliser The tag normaliser
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $mapper,
		private SecretTagMapper $tagMapper,
		private SecretTagNormaliser $normaliser = new SecretTagNormaliser(),
	) {
	}//end __construct()

	/**
	 * Star or unstar the holder's row.
	 *
	 * @param string $id        The row
	 * @param string $userId    The holder
	 * @param bool   $favourite The new star
	 *
	 * @return array{id: string, favourite: bool}
	 *
	 * @throws NotFoundException When the user does not hold the row
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-favourite-items-per-holder
	 */
	public function setFavourite(string $id, string $userId, bool $favourite): array {
		$this->held(id: $id, userId: $userId);
		$this->mapper->setFavourite($id, $userId, $favourite);

		return ['id' => $id, 'favourite' => $favourite];
	}//end setFavourite()

	/**
	 * Replace the tags on the holder's row.
	 *
	 * @param string       $id     The row
	 * @param string       $userId The holder
	 * @param array<mixed> $tags   The tags as sent
	 *
	 * @return list<string> The stored tags
	 *
	 * @throws NotFoundException When the user does not hold the row
	 * @throws InvalidArgumentException When a tag is too long or there are too many
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function setTags(string $id, string $userId, array $tags): array {
		$this->held(id: $id, userId: $userId);
		$normalised = $this->normaliser->normalise(tags: $tags);
		$this->tagMapper->replaceForSecret($id, $userId, $normalised);

		return $normalised;
	}//end setTags()

	/**
	 * The holder's tags with how many live secrets carry each.
	 *
	 * @param string $userId The holder
	 *
	 * @return list<array{tag: string, count: int}>
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function listTags(string $userId): array {
		return $this->tagMapper->countByOwner($userId);
	}//end listTags()

	/**
	 * Record that the holder used the row now (a fill from the extension).
	 *
	 * @param string $id     The row
	 * @param string $userId The holder
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the user does not hold the row
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	public function markUsed(string $id, string $userId): void {
		$this->held(id: $id, userId: $userId);
		$this->mapper->markUsed($id, $userId, new DateTime());
	}//end markUsed()

	/**
	 * Load a row the user holds; anything else is not found.
	 *
	 * @param string $id     The row
	 * @param string $userId The user
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the row is missing or not the user's
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
	 */
	private function held(string $id, string $userId): Secret {
		try {
			$secret = $this->mapper->findById($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new NotFoundException(message: 'Secret not found');
		}

		return $secret;
	}//end held()
}//end class
