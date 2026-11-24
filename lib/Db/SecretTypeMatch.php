<?php

/**
 * Keepiq Secret Type Match
 *
 * The type condition of a secrets listing.
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
use OCP\IDBConnection;

/**
 * Narrows a secrets listing to one type. On the built-in Passkey type, rows
 * whose passkeys field holds ciphertext match too: the presence of
 * ciphertext is all the server reads, as for the vault list marker.
 *
 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-the-passkey-type-filter-also-finds-logins-with-passkeys
 */
final class SecretTypeMatch {
	/**
	 * The name of the built-in type whose filter also lists logins with passkeys.
	 */
	public const PASSKEY_TYPE_NAME = 'passkey';

	/**
	 * Constructor for SecretTypeMatch.
	 *
	 * @param IDBConnection $db The connection the type name is looked up on
	 *
	 * @return void
	 */
	public function __construct(private readonly IDBConnection $db) {
	}//end __construct()

	/**
	 * Narrow a secrets query to one type.
	 *
	 * @param IQueryBuilder $qb     The query to narrow
	 * @param string        $typeId The secret-type ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-the-passkey-type-filter-also-finds-logins-with-passkeys
	 */
	public function narrow(IQueryBuilder $qb, string $typeId): void {
		$ofType = $qb->expr()->eq('type_id', $qb->createNamedParameter($typeId));
		if ($this->typeName(typeId: $typeId) !== self::PASSKEY_TYPE_NAME) {
			$qb->andWhere($ofType);
			return;
		}

		$qb->andWhere(
			$qb->expr()->orX(
				$ofType,
				$qb->expr()->andX(
					$qb->expr()->isNotNull('passkeys'),
					$qb->expr()->neq('passkeys', $qb->createNamedParameter(''))
				)
			)
		);
	}//end narrow()

	/**
	 * The name of a type, false when there is no such type.
	 *
	 * @param string $typeId The secret-type ID
	 *
	 * @return string|false
	 *
	 * @spec openspec/specs/login-one-time-codes/spec.md#requirement-the-passkey-type-filter-also-finds-logins-with-passkeys
	 */
	private function typeName(string $typeId): string|false {
		$qb = $this->db->getQueryBuilder();
		$qb->select('name')
			->from('keepiq_secret_types')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($typeId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$name   = $result->fetchOne();
		$result->closeCursor();

		return $name;
	}//end typeName()
}//end class
