<?php

/**
 * Keepiq StringPrimaryKey
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

/**
 * The UUID primary key of an entity whose `id` column is a string.
 *
 * Used by an Entity subclass: the class declares `public $id` and registers
 * `id` as a string type in its constructor.
 *
 * @spec exclude Persistence accessor shared by the UUID-keyed entities
 */
trait StringPrimaryKey {

	/**
	 * Get the UUID primary key.
	 *
	 * @return string
	 *
	 * @spec exclude Persistence accessor shared by the UUID-keyed entities
	 */
	public function getId(): string {
		return (string)$this->id;
	}//end getId()

	/**
	 * Set the UUID primary key.
	 *
	 * @param string $id The UUID
	 *
	 * @return void
	 *
	 * @spec exclude Persistence accessor shared by the UUID-keyed entities
	 */
	public function setId($id): void {
		$this->setter(name: 'id', args: [$id]);
	}//end setId()
}//end trait
