<?php

/**
 * Doriath Link Share Entity
 *
 * Database entity representing a password-protected link share of a
 * secret. Stores an AES-256-GCM encrypted point-in-time snapshot of the
 * secret together with the Argon2id salt used to derive the snapshot key
 * client-side, usage/brute-force counters and an optional expiry.
 *
 * The `jsonSerialize()` method intentionally omits the encrypted snapshot
 * and the Argon2id salt from the default serialization used by the
 * owner's management API; those two fields are only ever returned by the
 * public access endpoint via `jsonSerializeForAccess()`.
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
 * Entity representing a password-protected link share of a secret.
 *
 * @SuppressWarnings(PHPMD.LongVariable)  The `encryptedSecretSnapshot` property mirrors the
 *   schema-mandated `encrypted_secret_snapshot` column name; renaming it for the length rule
 *   would break the entity-to-column mapping.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getEncryptedSecretSnapshot()
 * @method void setEncryptedSecretSnapshot(string $encryptedSecretSnapshot)
 * @method string getArgon2idSalt()
 * @method void setArgon2idSalt(string $argon2idSalt)
 * @method string getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(string $encryptionSuiteId)
 * @method int getUsageLimit()
 * @method void setUsageLimit(int $usageLimit)
 * @method int getUsageCount()
 * @method void setUsageCount(int $usageCount)
 * @method int getFailedAttempts()
 * @method void setFailedAttempts(int $failedAttempts)
 * @method bool getBlobFetched()
 * @method void setBlobFetched(bool $blobFetched)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 */
class LinkShare extends Entity implements JsonSerializable
{

    /**
     * The ID of the secret this link share points at.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The URL-safe access token (128 bits of entropy, hex-encoded).
     *
     * @var string
     */
    protected string $token = '';

    /**
     * The AES-256-GCM encrypted snapshot blob (base64).
     *
     * @var string
     */
    protected string $encryptedSecretSnapshot = '';

    /**
     * The base64-encoded 16-byte salt used for the Argon2id KDF.
     *
     * @var string
     */
    protected string $argon2idSalt = '';

    /**
     * The encryption suite active at creation time.
     *
     * @var string
     */
    protected string $encryptionSuiteId = '';

    /**
     * The maximum number of successful accesses (1-10).
     *
     * @var integer
     */
    protected int $usageLimit = 1;

    /**
     * The number of successful accesses so far.
     *
     * @var integer
     */
    protected int $usageCount = 0;

    /**
     * The number of consecutive failed access attempts.
     *
     * @var integer
     */
    protected int $failedAttempts = 0;

    /**
     * Whether the blob has been fetched (Phase 1) without a following
     * successful confirmation (Phase 2). Used for brute-force tracking.
     *
     * @var boolean
     */
    protected bool $blobFetched = false;

    /**
     * The Nextcloud user ID of the secret owner who created the share.
     *
     * @var string
     */
    protected string $createdBy = '';

    /**
     * When the link share was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the link share expires (null = never).
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
     * Constructor for LinkShare.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'token', type: 'string');
        $this->addType(fieldName: 'encryptedSecretSnapshot', type: 'string');
        $this->addType(fieldName: 'argon2idSalt', type: 'string');
        $this->addType(fieldName: 'encryptionSuiteId', type: 'string');
        $this->addType(fieldName: 'usageLimit', type: 'integer');
        $this->addType(fieldName: 'usageCount', type: 'integer');
        $this->addType(fieldName: 'failedAttempts', type: 'integer');
        $this->addType(fieldName: 'blobFetched', type: 'boolean');
        $this->addType(fieldName: 'createdBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'expiresAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity for the owner's management API.
     *
     * Intentionally omits `encryptedSecretSnapshot` and `argon2idSalt` so
     * the management list never transfers the encrypted blob or its salt.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->getId(),
            'secretId'          => $this->secretId,
            'token'             => $this->token,
            'encryptionSuiteId' => $this->encryptionSuiteId,
            'usageLimit'        => $this->usageLimit,
            'usageCount'        => $this->usageCount,
            'createdBy'         => $this->createdBy,
            'createdAt'         => $this->createdAt?->format('c'),
            'expiresAt'         => $this->expiresAt?->format('c'),
        ];
    }//end jsonSerialize()

    /**
     * Serialize the entity for the public access endpoint (Phase 1).
     *
     * Returns the encrypted blob and the Argon2id salt that the browser
     * needs to derive the key and decrypt the snapshot. Never includes
     * `createdBy` or any owner-identifying metadata.
     *
     * @return array<string,mixed>
     */
    public function jsonSerializeForAccess(): array
    {
        return [
            'encryptedSecretSnapshot' => $this->encryptedSecretSnapshot,
            'argon2idSalt'            => $this->argon2idSalt,
            'usageLimit'              => $this->usageLimit,
            'usageCount'              => $this->usageCount,
        ];
    }//end jsonSerializeForAccess()
}//end class
