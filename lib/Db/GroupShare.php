<?php

/**
 * Keepiq Group Share Entity
 *
 * Database entity representing a group-share record — the parent of N
 * per-recipient ShareTarget rows that fan a secret out to every member
 * of a Nextcloud group.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
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
 * Entity representing a secret shared with a Nextcloud group.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getGroupId()
 * @method void setGroupId(string $groupId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method bool|null getUseOnly()
 * @method void setUseOnly(bool $useOnly)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 */
class GroupShare extends Entity implements JsonSerializable {

	/**
	 * The owner's source Secret ID.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The Nextcloud group ID.
	 *
	 * @var string
	 */
	protected string $groupId = '';

	/**
	 * The Nextcloud user ID that initiated the share.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When the share was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * Whether the recipient may only use the value, not view or copy it
	 * (sharing-use-only-and-expiring-shares D1).
	 *
	 * @var boolean|null
	 */
	protected ?bool $useOnly = false;

	/**
	 * When the access this grant gives ends (nullable = no end).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * The UUID primary key.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Get the UUID primary key.
	 *
	 * @return string
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
	 */
	public function setId($id): void {
		$this->setter(name: 'id', args: [$id]);
	}//end setId()

	/**
	 * Constructor for GroupShare.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'groupId', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'useOnly', type: 'boolean');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'groupId' => $this->groupId,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
			'useOnly' => ($this->useOnly === true),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
