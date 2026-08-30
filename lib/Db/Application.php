<?php

/**
 * Keepiq Application Entity
 *
 * Database entity representing a registered application that may own
 * Keepiq secrets. The full JWT-Bearer + admin-approval-queue flow
 * ships with the dedicated implement-application-mgmt build cycle;
 * this entity is the smallest backing row that supports register /
 * approve / reject / delete + cascade.
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
 * Entity representing a registered application.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getCsr()
 * @method void setCsr(?string $csr)
 * @method string|null getRegisteredBy()
 * @method void setRegisteredBy(?string $registeredBy)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 */
class Application extends Entity implements JsonSerializable {
	public const STATUS_PENDING = 'pending';
	public const STATUS_ACTIVE = 'active';

	public const TYPE_INTERNAL = 'internal';
	public const TYPE_EXTERNAL = 'external';

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * Optional free-form description.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Application type: internal or external.
	 *
	 * @var string
	 */
	protected string $type = self::TYPE_EXTERNAL;

	/**
	 * Lifecycle: pending (awaiting admin approval) or active.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_PENDING;

	/**
	 * Optional PKCS#10 CSR — stored while the application is pending,
	 * cleared once an EncryptionSuite has been provisioned for it.
	 *
	 * @var string|null
	 */
	protected ?string $csr = null;

	/**
	 * The Nextcloud user that registered the application (nullable
	 * because the public registration endpoint allows anonymous
	 * registration in the full build).
	 *
	 * @var string|null
	 */
	protected ?string $registeredBy = null;

	/**
	 * The admin user that approved the application.
	 *
	 * @var string|null
	 */
	protected ?string $approvedBy = null;

	/**
	 * When the row was first created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the application was approved (nullable until then).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $approvedAt = null;

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
	 * Constructor for Application.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'type', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'csr', type: 'string');
		$this->addType(fieldName: 'registeredBy', type: 'string');
		$this->addType(fieldName: 'approvedBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'approvedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Check whether the application is active.
	 *
	 * @return bool
	 */
	public function isActive(): bool {
		return $this->status === self::STATUS_ACTIVE;
	}//end isActive()

	/**
	 * Check whether the application is pending approval.
	 *
	 * @return bool
	 */
	public function isPending(): bool {
		return $this->status === self::STATUS_PENDING;
	}//end isPending()

	/**
	 * Serialize the entity to an array for the API. The CSR field is
	 * intentionally not serialized — it is server-internal state and
	 * must never leak to the client.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'description' => $this->description,
			'type' => $this->type,
			'status' => $this->status,
			'registeredBy' => $this->registeredBy,
			'approvedBy' => $this->approvedBy,
			'createdAt' => $this->createdAt?->format('c'),
			'approvedAt' => $this->approvedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
