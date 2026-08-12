<?php

/**
 * Doriath Expiry Policy Entity
 *
 * One expiry rule (rotation-expiry-policies §1.1): scoped to a secret
 * TYPE or a FOLDER, owned by a user (or instance-wide when owner_id is
 * null), expressing a max credential age in days plus optional reminder
 * thresholds. Metadata only — never ciphertext.
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
 * Entity representing one expiry policy.
 *
 * @method string|null getOwnerId()
 * @method void setOwnerId(?string $ownerId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getScopeId()
 * @method void setScopeId(string $scopeId)
 * @method int|null getMaxAgeDays()
 * @method void setMaxAgeDays(?int $maxAgeDays)
 * @method string|null getReminderDays()
 * @method void setReminderDays(?string $reminderDays)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class ExpiryPolicy extends Entity implements JsonSerializable {

	/**
	 * The owning user (null = instance-wide admin policy).
	 *
	 * @var string|null
	 */
	protected ?string $ownerId = null;

	/**
	 * The scope kind: `type` or `folder`. Empty default on purpose (NC
	 * Entity dirty-tracking).
	 *
	 * @var string
	 */
	protected string $scope = '';

	/**
	 * The scoped type id or folder id.
	 *
	 * @var string
	 */
	protected string $scopeId = '';

	/**
	 * Maximum credential age in days (null = reminder-only policy).
	 *
	 * @var integer|null
	 */
	protected ?int $maxAgeDays = null;

	/**
	 * JSON array of reminder thresholds in days (e.g. "[30,7,1]").
	 *
	 * @var string|null
	 */
	protected ?string $reminderDays = null;

	/**
	 * The user that created the policy.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When the policy was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the policy was last updated.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updatedAt = null;

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
	 * Constructor for ExpiryPolicy.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'scope', type: 'string');
		$this->addType(fieldName: 'scopeId', type: 'string');
		$this->addType(fieldName: 'maxAgeDays', type: 'integer');
		$this->addType(fieldName: 'reminderDays', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ownerId' => $this->ownerId,
			'scope' => $this->scope,
			'scopeId' => $this->scopeId,
			'maxAgeDays' => $this->maxAgeDays,
			'reminderDays' => $this->decodedReminderDays(),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()

	/**
	 * The reminder thresholds as a decoded array (null when unset).
	 *
	 * @return int[]|null
	 */
	private function decodedReminderDays(): ?array {
		if ($this->reminderDays === null) {
			return null;
		}

		return json_decode($this->reminderDays, true);
	}//end decodedReminderDays()
}//end class
