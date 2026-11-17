<?php

/**
 * Keepiq Secret Url Entity
 *
 * One extra website address of a login, in plain text like the main address
 * (vault-website-addresses D1).
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
 * An extra address on a secret row.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method int getPosition()
 * @method void setPosition(int $position)
 */
class SecretUrl extends Entity {
	/**
	 * The secret row the address belongs to.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The holder of that row.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * The address, as the user typed it.
	 *
	 * @var string
	 */
	protected string $url = '';

	/**
	 * Its place in the login's list, from 0.
	 *
	 * @var int
	 */
	protected int $position = 0;

	/**
	 * Register the column types.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
	 */
	public function __construct() {
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'position', type: 'integer');
	}//end __construct()
}//end class
