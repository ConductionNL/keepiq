<?php

/**
 * Doriath Share Target Entity
 *
 * Database entity representing a single user-to-user share of a secret —
 * one row per recipient. Each row links a source secret to a recipient's
 * encrypted Secret copy.
 *
 * @category Db
 * @package  OCA\Doriath\Db
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

namespace OCA\Doriath\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing a user-to-user secret share.
 *
 * @method string getSourceSecretId()
 * @method void setSourceSecretId(string $sourceSecretId)
 * @method string getTargetUserId()
 * @method void setTargetUserId(string $targetUserId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string|null getGroupShareId()
 * @method void setGroupShareId(?string $groupShareId)
 * @method string|null getTeamFolderId()
 * @method void setTeamFolderId(?string $teamFolderId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class ShareTarget extends Entity implements JsonSerializable {

	/**
	 * The source secret ID (owner's copy).
	 *
	 * @var string
	 */
	protected string $sourceSecretId = '';

	/**
	 * The Nextcloud user ID of the recipient.
	 *
	 * @var string
	 */
	protected string $targetUserId = '';

	/**
	 * The ID of the recipient's encrypted Secret copy.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * Optional ID of the group share this row was derived from.
	 *
	 * @var string|null
	 */
	protected ?string $groupShareId = null;

	/**
	 * Optional ID of the team folder this row was derived from.
	 *
	 * @var string|null
	 */
	protected ?string $teamFolderId = null;

	/**
	 * The user ID that initiated the share.
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
	 * Constructor for ShareTarget.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'sourceSecretId', type: 'string');
		$this->addType(fieldName: 'targetUserId', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'groupShareId', type: 'string');
		$this->addType(fieldName: 'teamFolderId', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'secretId' => $this->secretId,
			'groupShareId' => $this->groupShareId,
			'teamFolderId' => $this->teamFolderId,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
