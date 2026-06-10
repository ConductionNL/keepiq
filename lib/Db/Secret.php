<?php

/**
 * Doriath Secret Entity
 *
 * Database entity representing a secret. The key, login, and
 * additional_fields columns hold RSA-encrypted ciphertext blobs produced
 * in the browser — the server never decrypts them. The name, url, and
 * folder placement are stored in plaintext to enable search.
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
 * Entity representing a secret.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string getTypeId()
 * @method void setTypeId(string $typeId)
 * @method string|null getFolderId()
 * @method void setFolderId(?string $folderId)
 * @method string getKey()
 * @method void setKey(string $key)
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
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the spec-mandated DB columns.
 */
class Secret extends Entity implements JsonSerializable
{

    /**
     * The plaintext secret name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The plaintext URL (nullable).
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * The SecretType ID.
     *
     * @var string
     */
    protected string $typeId = '';

    /**
     * The Folder ID (null for root-level secrets).
     *
     * @var string|null
     */
    protected ?string $folderId = null;

    /**
     * The RSA-encrypted key/password blob (base64).
     *
     * @var string
     */
    protected string $key = '';

    /**
     * The RSA-encrypted login blob (base64, nullable).
     *
     * @var string|null
     */
    protected ?string $login = null;

    /**
     * The RSA-encrypted additional_fields JSON blob (base64, nullable).
     *
     * @var string|null
     */
    protected ?string $additionalFields = null;

    /**
     * The EncryptionSuite used to encrypt this secret.
     *
     * @var string
     */
    protected string $encryptionSuiteId = '';

    /**
     * The owner type: user or application.
     *
     * Defaults to an empty string (not 'user') so that an explicit
     * setOwnerType('user') call marks the column dirty in NC's QBMapper and
     * is written on INSERT — the column is NOT NULL.
     *
     * @var string
     */
    protected string $ownerType = '';

    /**
     * The owner ID (Nextcloud user ID or application ID).
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * When the secret was flagged as possibly compromised (nullable).
     *
     * @var DateTime|null
     */
    protected ?DateTime $possiblyCompromisedAt = null;

    /**
     * A migration error message, if re-encryption failed (nullable).
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
     * Serialize the entity to an array for the API, including encrypted blobs.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->getId(),
            'name'              => $this->name,
            'url'               => $this->url,
            'typeId'            => $this->typeId,
            'folderId'          => $this->folderId,
            'key'               => $this->key,
            'login'             => $this->login,
            'additionalFields'  => $this->additionalFields,
            'encryptionSuiteId' => $this->encryptionSuiteId,
            'ownerType'         => $this->ownerType,
            'ownerId'           => $this->ownerId,
            'blocked'           => false,
            'createdAt'         => $this->createdAt?->format('c'),
            'updatedAt'         => $this->updatedAt?->format('c'),
        ];
    }//end jsonSerialize()

    /**
     * Serialize only plaintext metadata, omitting encrypted blobs.
     *
     * Used in list responses for secrets whose encryption suite is revoked
     * or compromised — the metadata is shown but the ciphertext withheld.
     *
     * @param string $blockedReason The reason the encrypted fields are withheld
     *
     * @return array<string,mixed>
     */
    public function jsonSerializeBlocked(string $blockedReason): array
    {
        return [
            'id'                => $this->getId(),
            'name'              => $this->name,
            'url'               => $this->url,
            'typeId'            => $this->typeId,
            'folderId'          => $this->folderId,
            'encryptionSuiteId' => $this->encryptionSuiteId,
            'ownerType'         => $this->ownerType,
            'ownerId'           => $this->ownerId,
            'blocked'           => true,
            'blockedReason'     => $blockedReason,
            'createdAt'         => $this->createdAt?->format('c'),
            'updatedAt'         => $this->updatedAt?->format('c'),
        ];
    }//end jsonSerializeBlocked()
}//end class
