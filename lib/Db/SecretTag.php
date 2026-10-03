<?php

/**
 * Keepiq Secret Tag Entity
 *
 * One plain-text tag on one holder's secret row
 * (vault-favourites-tags-and-last-used D2).
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

use OCP\AppFramework\Db\Entity;

/**
 * A tag on a secret row.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getTag()
 * @method void setTag(string $tag)
 */
class SecretTag extends Entity {
	/**
	 * The tagged secret row.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The holder of that row, who set the tag.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * The normalised tag: trimmed, lowercase, at most 32 characters.
	 *
	 * @var string
	 */
	protected string $tag = '';

	/**
	 * Register the column types.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-tags-per-holder
	 */
	public function __construct() {
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'tag', type: 'string');
	}//end __construct()
}//end class
