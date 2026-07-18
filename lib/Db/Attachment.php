<?php

/**
 * Doriath Attachment Entity
 *
 * One row per uploaded ciphertext blob (encrypted-attachments §1.1). The
 * blob bytes live in IAppData under `blob_ref`; the filename and content
 * type ride AES-GCM-encrypted under the file key in `encrypted_metadata`.
 * The blob is deduplicated across the owner and all recipients — only the
 * wrapped file key (AttachmentGrant) is per-copy.
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
 * Entity representing an encrypted attachment blob's metadata.
 *
 * @method string getSourceSecretId()
 * @method void setSourceSecretId(string $sourceSecretId)
 * @method string getBlobRef()
 * @method void setBlobRef(string $blobRef)
 * @method string getEncryptedMetadata()
 * @method void setEncryptedMetadata(string $encryptedMetadata)
 * @method int getSizeBytes()
 * @method void setSizeBytes(int $sizeBytes)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror DB columns.
 */
class Attachment extends Entity implements JsonSerializable
{

    /**
     * The owner's canonical Secret this file was uploaded against.
     *
     * @var string
     */
    protected string $sourceSecretId = '';

    /**
     * Locator into the IAppData folder holding the ciphertext.
     *
     * @var string
     */
    protected string $blobRef = '';

    /**
     * AES-GCM ciphertext of { filename, contentType } under the file key.
     *
     * @var string
     */
    protected string $encryptedMetadata = '';

    /**
     * Ciphertext byte length (quota accounting).
     *
     * @var integer
     */
    protected int $sizeBytes = 0;

    /**
     * When the attachment was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the attachment was last updated.
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
     * Constructor for Attachment.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'sourceSecretId', type: 'string');
        $this->addType(fieldName: 'blobRef', type: 'string');
        $this->addType(fieldName: 'encryptedMetadata', type: 'string');
        $this->addType(fieldName: 'sizeBytes', type: 'integer');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for the API. The blob locator is
     * server-internal and never exposed.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                => $this->getId(),
            'sourceSecretId'    => $this->sourceSecretId,
            'encryptedMetadata' => $this->encryptedMetadata,
            'sizeBytes'         => $this->sizeBytes,
            'createdAt'         => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
