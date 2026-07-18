<?php

/**
 * Doriath Attachment Grant Entity
 *
 * Per-copy wrapped file key (encrypted-attachments §1.1): the random AES
 * file key of one attachment, RSA-wrapped under one copy's
 * EncryptionSuite public certificate — one grant for the owner's copy and
 * one per recipient copy. A physical blob is removed when its LAST grant
 * is deleted (reference count).
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
 * Entity representing one copy's wrapped attachment file key.
 *
 * @method string getAttachmentId()
 * @method void setAttachmentId(string $attachmentId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getRecipientType()
 * @method void setRecipientType(string $recipientType)
 * @method string getRecipientId()
 * @method void setRecipientId(string $recipientId)
 * @method string getWrappedFileKey()
 * @method void setWrappedFileKey(string $wrappedFileKey)
 * @method string|null getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(?string $encryptionSuiteId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror DB columns.
 */
class AttachmentGrant extends Entity implements JsonSerializable
{

    /**
     * The attachment this grant unlocks.
     *
     * @var string
     */
    protected string $attachmentId = '';

    /**
     * The Secret copy this grant belongs to.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The recipient type: `user` or `application`. Initialized empty on
     * purpose — a non-empty default would make a same-value set a no-op
     * so the Entity never marks the column dirty and INSERT omits it
     * (NOT NULL violation; found live in team-folder-sharing).
     *
     * @var string
     */
    protected string $recipientType = '';

    /**
     * The Nextcloud user id or application id holding this grant.
     *
     * @var string
     */
    protected string $recipientId = '';

    /**
     * The RSA-wrapped AES file key for this copy.
     *
     * @var string
     */
    protected string $wrappedFileKey = '';

    /**
     * The EncryptionSuite that wrapped this grant's key.
     *
     * @var string|null
     */
    protected ?string $encryptionSuiteId = null;

    /**
     * When the grant was created.
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
     * Constructor for AttachmentGrant.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'attachmentId', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'recipientType', type: 'string');
        $this->addType(fieldName: 'recipientId', type: 'string');
        $this->addType(fieldName: 'wrappedFileKey', type: 'string');
        $this->addType(fieldName: 'encryptionSuiteId', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for the API.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'attachmentId'   => $this->attachmentId,
            'secretId'       => $this->secretId,
            'recipientType'  => $this->recipientType,
            'recipientId'    => $this->recipientId,
            'wrappedFileKey' => $this->wrappedFileKey,
            'createdAt'      => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
