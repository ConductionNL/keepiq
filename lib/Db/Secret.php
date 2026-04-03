<?php

/**
 * Doriath Secret Entity
 *
 * Database entity representing a secret.
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
 * Entity representing a secret with encrypted credential storage.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string getTypeId()
 * @method void setTypeId(string $typeId)
 * @method string|null getFolderId()
 * @method void setFolderId(?string $folderId)
 * @method string|null getKey()
 * @method void setKey(?string $key)
 * @method string|null getLogin()
 * @method void setLogin(?string $login)
 * @method string|null getAdditionalFields()
 * @method void setAdditionalFields(?string $additionalFields)
 * @method string getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(string $encryptionSuiteId)
 * @method string getOwnerType()
 * @method void setOwnerType(string $ownerType)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method DateTime|null getPossiblyCompromisedAt()
 * @method void setPossiblyCompromisedAt(?DateTime $possiblyCompromisedAt)
 * @method string|null getMigrationError()
 * @method void setMigrationError(?string $migrationError)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class Secret extends Entity implements JsonSerializable
{

    /**
     * The secret name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The associated URL.
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * The secret type ID (FK to secret_types).
     *
     * @var string
     */
    protected string $typeId = '';

    /**
     * The folder ID (FK to folders).
     *
     * @var string|null
     */
    protected ?string $folderId = null;

    /**
     * The encrypted key blob.
     *
     * @var string|null
     */
    protected ?string $key = null;

    /**
     * The encrypted login blob.
     *
     * @var string|null
     */
    protected ?string $login = null;

    /**
     * The encrypted additional fields blob.
     *
     * @var string|null
     */
    protected ?string $additionalFields = null;

    /**
     * The encryption suite ID (FK to enc_suites).
     *
     * @var string
     */
    protected string $encryptionSuiteId = '';

    /**
     * The owner type.
     *
     * @var string
     */
    protected string $ownerType = '';

    /**
     * The owner ID.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * When the secret was possibly compromised.
     *
     * @var DateTime|null
     */
    protected ?DateTime $possiblyCompromisedAt = null;

    /**
     * The migration error message.
     *
     * @var string|null
     */
    protected ?string $migrationError = null;

    /**
     * When the secret was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the secret was last updated.
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
    public function getId(): string
    {
        return (string) $this->id;
    }//end getId()

    /**
     * Set the UUID primary key.
     *
     * @param string $id The UUID
     *
     * @return void
     */
    public function setId($id): void
    {
        $this->setter(name: 'id', args: [$id]);
    }//end setId()

    /**
     * Constructor for Secret.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'url', type: 'string');
        $this->addType(fieldName: 'typeId', type: 'string');
        $this->addType(fieldName: 'folderId', type: 'string');
        $this->addType(fieldName: 'key', type: 'string');
        $this->addType(fieldName: 'login', type: 'string');
        $this->addType(fieldName: 'additionalFields', type: 'string');
        $this->addType(fieldName: 'encryptionSuiteId', type: 'string');
        $this->addType(fieldName: 'ownerType', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'possiblyCompromisedAt', type: 'datetime');
        $this->addType(fieldName: 'migrationError', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     * Defaults to excluding encrypted fields (list-safe).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray(includeEncrypted: false);
    }//end jsonSerialize()

    /**
     * Serialize the entity with control over encrypted field inclusion.
     *
     * @param bool $includeEncrypted Whether to include key, login, additionalFields
     *
     * @return array<string,mixed>
     */
    public function toArray(bool $includeEncrypted=false): array
    {
        $data = [
            'id'                    => $this->getId(),
            'name'                  => $this->name,
            'url'                   => $this->url,
            'typeId'                => $this->typeId,
            'folderId'              => $this->folderId,
            'encryptionSuiteId'     => $this->encryptionSuiteId,
            'ownerType'             => $this->ownerType,
            'ownerId'               => $this->ownerId,
            'possiblyCompromisedAt' => $this->possiblyCompromisedAt?->format('c'),
            'migrationError'        => $this->migrationError,
            'createdAt'             => $this->createdAt?->format('c'),
            'updatedAt'             => $this->updatedAt?->format('c'),
        ];

        if ($includeEncrypted === true) {
            $data['key']   = $this->key;
            $data['login'] = $this->login;
            $data['additionalFields'] = $this->additionalFields;
        }

        return $data;
    }//end toArray()
}//end class
