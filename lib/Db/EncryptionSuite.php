<?php

/**
 * Doriath Encryption Suite Entity
 *
 * Database entity representing an encryption suite.
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
 * Entity representing an encryption suite with certificate and key storage.
 *
 * @method string getOwnerType()
 * @method void setOwnerType(string $ownerType)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string|null getCertificate()
 * @method void setCertificate(?string $certificate)
 * @method string|null getPrivateKey()
 * @method void setPrivateKey(?string $privateKey)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 * @method string|null getRevokedReason()
 * @method void setRevokedReason(?string $revokedReason)
 * @method string|null getRevokedBy()
 * @method void setRevokedBy(?string $revokedBy)
 * @method DateTime|null getReinstatedAt()
 * @method void setReinstatedAt(?DateTime $reinstatedAt)
 * @method string|null getReinstatedBy()
 * @method void setReinstatedBy(?string $reinstatedBy)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class EncryptionSuite extends Entity implements JsonSerializable
{

    /**
     * The owner type (user or application).
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
     * The PEM-encoded certificate.
     *
     * @var string|null
     */
    protected ?string $certificate = null;

    /**
     * The encrypted private key.
     *
     * @var string|null
     */
    protected ?string $privateKey = null;

    /**
     * The suite status.
     *
     * @var string
     */
    protected string $status = 'active';

    /**
     * When the suite was revoked.
     *
     * @var DateTime|null
     */
    protected ?DateTime $revokedAt = null;

    /**
     * The reason for revocation.
     *
     * @var string|null
     */
    protected ?string $revokedReason = null;

    /**
     * Who revoked the suite.
     *
     * @var string|null
     */
    protected ?string $revokedBy = null;

    /**
     * When the suite was reinstated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $reinstatedAt = null;

    /**
     * Who reinstated the suite.
     *
     * @var string|null
     */
    protected ?string $reinstatedBy = null;

    /**
     * When the suite was created.
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
     * Constructor for EncryptionSuite.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'ownerType', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'certificate', type: 'string');
        $this->addType(fieldName: 'privateKey', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'revokedAt', type: 'datetime');
        $this->addType(fieldName: 'revokedReason', type: 'string');
        $this->addType(fieldName: 'revokedBy', type: 'string');
        $this->addType(fieldName: 'reinstatedAt', type: 'datetime');
        $this->addType(fieldName: 'reinstatedBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        // Extract the SPKI public key PEM from the X.509 certificate
        // so the frontend can use it with WebCrypto's importKey('spki').
        $publicKeyPem = null;
        if ($this->certificate !== null) {
            $pubKey = openssl_pkey_get_public($this->certificate);
            if ($pubKey !== false) {
                $details      = openssl_pkey_get_details($pubKey);
                $publicKeyPem = $details['key'] ?? null;
            }
        }

        return [
            'id'            => $this->getId(),
            'ownerType'     => $this->ownerType,
            'ownerId'       => $this->ownerId,
            'certificate'   => $this->certificate,
            'publicKey'     => $publicKeyPem,
            'privateKey'    => $this->privateKey,
            'status'        => $this->status,
            'revokedAt'     => $this->revokedAt?->format('c'),
            'revokedReason' => $this->revokedReason,
            'revokedBy'     => $this->revokedBy,
            'reinstatedAt'  => $this->reinstatedAt?->format('c'),
            'reinstatedBy'  => $this->reinstatedBy,
            'createdAt'     => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
