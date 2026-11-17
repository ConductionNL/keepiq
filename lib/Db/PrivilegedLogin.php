<?php

/**
 * Keepiq Privileged Login
 *
 * The owner's mark that a shared login needs an approved time window.
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

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A login its owner marked as privileged: its recipients need an approved
 * access window before the server serves their copy.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class PrivilegedLogin extends Entity implements JsonSerializable {

	use StringPrimaryKey;

	/**
	 * The owner's source secret.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The secret owner (NC user id).
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * When the login was marked.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the owner.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'secretId' => $this->secretId,
			'privileged' => true,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
