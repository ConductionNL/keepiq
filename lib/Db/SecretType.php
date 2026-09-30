<?php

/**
 * Keepiq Secret Type Entity
 *
 * Database entity representing a secret type (login, api_key, etc.) that
 * categorises secrets. System types are immutable and seeded on install;
 * user and global scopes allow custom categorisation.
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
 * Entity representing a secret type.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string|null getOwnerId()
 * @method void setOwnerId(?string $ownerId)
 * @method string|null getFields()
 * @method void setFields(?string $fields)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class SecretType extends Entity implements JsonSerializable {

	/**
	 * The unique type name (lowercase machine identifier).
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The human-readable label.
	 *
	 * @var string
	 */
	protected string $label = '';

	/**
	 * The type scope: system, user, or global.
	 *
	 * @var string
	 */
	protected string $scope = 'user';

	/**
	 * The owning Nextcloud user ID (null for system and global scopes).
	 *
	 * @var string|null
	 */
	protected ?string $ownerId = null;

	/**
	 * The fields an item of this type carries, as JSON (admin-18); null for
	 * a type without a field list, such as the built-in types.
	 *
	 * @var string|null
	 */
	protected ?string $fields = null;

	/**
	 * When the type was created.
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
	 * Constructor for SecretType.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'label', type: 'string');
		$this->addType(fieldName: 'scope', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'fields', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * The field list, decoded; an empty list when the type has none.
	 *
	 * @return list<array{key: string, label: string, kind: string, required: bool}>
	 *
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function getFieldList(): array {
		if ($this->fields === null || $this->fields === '') {
			return [];
		}

		$decoded = json_decode($this->fields, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end getFieldList()

	/**
	 * Store a field list; an empty list is stored as null.
	 *
	 * @param list<array{key: string, label: string, kind: string, required: bool}> $fields The fields
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
	 */
	public function setFieldList(array $fields): void {
		$json = null;
		if ($fields !== []) {
			$json = json_encode(array_values($fields), JSON_THROW_ON_ERROR);
		}

		$this->setFields($json);
	}//end setFieldList()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'label' => $this->label,
			'scope' => $this->scope,
			'ownerId' => $this->ownerId,
			'fields' => $this->getFieldList(),
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
