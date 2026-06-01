<?php

/**
 * Doriath Secret Entity
 *
 * Database entity representing a stored secret with RSA-encrypted fields.
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
 * Entity representing a stored secret.
 *
 * The secretKey, login and additionalFields columns hold RSA-encrypted
 * ciphertext blobs produced client-side; the server never decrypts them.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string getTypeId()
 * @method void setTypeId(string $typeId)
 * @method string|null getFolderId()
 * @method void setFolderId(?string $folderId)
 * @method string|null getSecretKey()
 * @method void setSecretKey(?string $secretKey)
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
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) The $possiblyCompromisedAt property
 *   mirrors the possibly_compromised_at column name (ADR-001 schema mapping).
 */
class Secret extends Entity implements JsonSerializable
{

    /**
     * The secret name (plaintext).
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The secret URL (plaintext, nullable).
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * The secret type ID.
     *
     * @var string
     */
    protected string $typeId = '';

    /**
     * The containing folder ID (null for root).
     *
     * @var string|null
     */
    protected ?string $folderId = null;

    /**
     * The encrypted key/password blob (maps to the secret_key column —
     * "key" is a reserved SQL word).
     *
     * @var string|null
     */
    protected ?string $secretKey = null;

    /**
     * The encrypted login blob.
     *
     * @var string|null
     */
    protected ?string $login = null;

    /**
     * The encrypted additional-fields JSON blob.
     *
     * @var string|null
     */
    protected ?string $additionalFields = null;

    /**
     * The encryption suite used to encrypt this secret.
     *
     * @var string
     */
    protected string $encryptionSuiteId = '';

    /**
     * The owner type (user or application).
     *
     * @var string
     */
    protected string $ownerType = 'user';

    /**
     * The owner ID.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * When the secret was flagged possibly compromised.
     *
     * @var DateTime|null
     */
    protected ?DateTime $possiblyCompromisedAt = null;

    /**
     * Any migration error recorded for this secret.
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
        $this->addType(fieldName: 'secretKey', type: 'string');
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
     *
     * Exposes the encrypted blob as `key` in the API even though the
     * column is `secret_key`. Encrypted fields are returned verbatim —
     * the server never decrypts them (ADR-005 / always-E2E).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                    => $this->getId(),
            'name'                  => $this->name,
            'url'                   => $this->url,
            'typeId'                => $this->typeId,
            'folderId'              => $this->folderId,
            'key'                   => $this->secretKey,
            'login'                 => $this->login,
            'additionalFields'      => $this->additionalFields,
            'encryptionSuiteId'     => $this->encryptionSuiteId,
            'ownerType'             => $this->ownerType,
            'ownerId'               => $this->ownerId,
            'possiblyCompromisedAt' => $this->possiblyCompromisedAt?->format('c'),
            'migrationError'        => $this->migrationError,
            'createdAt'             => $this->createdAt?->format('c'),
            'updatedAt'             => $this->updatedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
