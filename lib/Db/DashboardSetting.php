<?php

/**
 * Keepiq Dashboard Setting Entity
 *
 * Per-user dashboard preference row — stores a single JSON-encoded
 * preference value keyed by a setting name (e.g. 'layout', 'visible_widgets',
 * 'default_view').
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
 * Entity representing a per-user dashboard setting row.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSettingKey()
 * @method void setSettingKey(string $settingKey)
 * @method string getSettingValue()
 * @method void setSettingValue(string $settingValue)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class DashboardSetting extends Entity implements JsonSerializable {

	/**
	 * The Nextcloud user this setting belongs to.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The setting key (e.g. layout, visible_widgets, default_view).
	 *
	 * @var string
	 */
	protected string $settingKey = '';

	/**
	 * JSON-encoded setting value (free-form).
	 *
	 * @var string
	 */
	protected string $settingValue = 'null';

	/**
	 * When the row was first created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the row was last updated.
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
	 * Constructor for DashboardSetting.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'settingKey', type: 'string');
		$this->addType(fieldName: 'settingValue', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Decode the JSON-encoded setting value.
	 *
	 * @return mixed The decoded value, or null on decode failure.
	 */
	public function getDecodedValue(): mixed {
		$decoded = json_decode($this->settingValue, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return null;
		}

		return $decoded;
	}//end getDecodedValue()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->userId,
			'settingKey' => $this->settingKey,
			'settingValue' => $this->getDecodedValue(),
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
