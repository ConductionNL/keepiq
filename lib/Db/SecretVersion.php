<?php

/**
 * Doriath Secret Version Entity
 *
 * One immutable pre-update snapshot of a secret's fields
 * (secret-version-history §1.1). Sensitive fields are the ciphertext
 * exactly as they were at snapshot time, under the suite recorded in
 * `encryption_suite_id` — the server never decrypts to snapshot.
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
 * Entity representing one immutable secret version.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method int getVersionNumber()
 * @method void setVersionNumber(int $versionNumber)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string getKey()
 * @method void setKey(string $key)
 * @method string|null getLogin()
 * @method void setLogin(?string $login)
 * @method string|null getAdditionalFields()
 * @method void setAdditionalFields(?string $additionalFields)
 * @method string|null getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(?string $encryptionSuiteId)
 * @method string getActorType()
 * @method void setActorType(string $actorType)
 * @method string getActorId()
 * @method void setActorId(string $actorId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class SecretVersion extends Entity implements JsonSerializable {

	/**
	 * The secret this version belongs to.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The monotonically increasing version number per secret.
	 *
	 * @var integer
	 */
	protected int $versionNumber = 0;

	/**
	 * The plaintext display name at snapshot time.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The plaintext url at snapshot time.
	 *
	 * @var string|null
	 */
	protected ?string $url = null;

	/**
	 * The ciphertext key blob at snapshot time.
	 *
	 * @var string
	 */
	protected string $key = '';

	/**
	 * The ciphertext login blob at snapshot time.
	 *
	 * @var string|null
	 */
	protected ?string $login = null;

	/**
	 * The ciphertext additional-fields blob at snapshot time.
	 *
	 * @var string|null
	 */
	protected ?string $additionalFields = null;

	/**
	 * The suite that wrapped this version's ciphertext.
	 *
	 * @var string|null
	 */
	protected ?string $encryptionSuiteId = null;

	/**
	 * The actor type (`user`|`application`). Initialized empty on purpose
	 * (NC Entity dirty-tracking: a non-empty default makes a same-value
	 * set a no-op and the INSERT omits the column).
	 *
	 * @var string
	 */
	protected string $actorType = '';

	/**
	 * The actor id.
	 *
	 * @var string
	 */
	protected string $actorId = '';

	/**
	 * When the snapshot was taken.
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
	 * Constructor for SecretVersion.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'versionNumber', type: 'integer');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'key', type: 'string');
		$this->addType(fieldName: 'login', type: 'string');
		$this->addType(fieldName: 'additionalFields', type: 'string');
		$this->addType(fieldName: 'encryptionSuiteId', type: 'string');
		$this->addType(fieldName: 'actorType', type: 'string');
		$this->addType(fieldName: 'actorId', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize metadata only — ciphertext blobs are returned exclusively
	 * by the dedicated show endpoint.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'versionNumber' => $this->versionNumber,
			'name' => $this->name,
			'url' => $this->url,
			'actorType' => $this->actorType,
			'actorId' => $this->actorId,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()

	/**
	 * Serialize INCLUDING the ciphertext blobs (the show endpoint).
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerializeWithBlobs(): array {
		$data = $this->jsonSerialize();

		$data['key'] = $this->key;
		$data['login'] = $this->login;
		$data['additionalFields'] = $this->additionalFields;

		return $data;
	}//end jsonSerializeWithBlobs()
}//end class
