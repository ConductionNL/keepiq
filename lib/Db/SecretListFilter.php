<?php

/**
 * Keepiq Secret List Filter
 *
 * The owner and optional filters of one secrets listing.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The owner and the optional filters a secrets listing and its count share:
 * folder, type, trash/archive state, favourite and tag.
 *
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
 */
final class SecretListFilter {
	/**
	 * Constructor for SecretListFilter.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 * @param string|null $folderId Filter by folder ID (null = no folder filter)
	 * @param string|null $typeId Filter by secret-type ID (null = all types)
	 * @param string|null $state One of the SecretMapper::STATE_* constants; null = every row
	 * @param bool|null $favourite Only the holder's starred rows when true
	 * @param string|null $tag Only rows the holder tagged with this tag
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $ownerType,
		public readonly string $ownerId,
		public readonly ?string $folderId,
		public readonly ?string $typeId,
		public readonly ?string $state,
		public readonly ?bool $favourite,
		public readonly ?string $tag,
	) {
	}//end __construct()

	/**
	 * Narrow a secrets query to the owner, then the folder and type when
	 * given, then the trash/archive state.
	 *
	 * @param IQueryBuilder $qb The query to narrow
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
	 */
	public function narrow(IQueryBuilder $qb): void {
		$qb->where($qb->expr()->eq('owner_type', $qb->createNamedParameter($this->ownerType)))
			->andWhere($qb->expr()->eq('owner_id', $qb->createNamedParameter($this->ownerId)));

		if ($this->folderId !== null) {
			$qb->andWhere($qb->expr()->eq('folder_id', $qb->createNamedParameter($this->folderId)));
		}

		if ($this->typeId !== null) {
			$qb->andWhere($qb->expr()->eq('type_id', $qb->createNamedParameter($this->typeId)));
		}

		(new SecretStateFilter())->apply(qb: $qb, state: $this->state);
	}//end narrow()

	/**
	 * Narrow a secrets query to the holder's favourite and tag, and answer
	 * the organisation that orders the listing.
	 *
	 * @param IQueryBuilder $qb The query to narrow
	 *
	 * @return SecretListOrganisation
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function organise(IQueryBuilder $qb): SecretListOrganisation {
		$organisation = new SecretListOrganisation();
		$organisation->apply(qb: $qb, ownerId: $this->ownerId, favourite: $this->favourite, tag: $this->tag);

		return $organisation;
	}//end organise()
}//end class
