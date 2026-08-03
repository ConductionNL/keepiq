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
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method int getUnlockKeyEpoch()
 * @method void setUnlockKeyEpoch(int $unlockKeyEpoch)
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
     * The epoch of the current private-key wrap (passkey-vault-login
     * §D4). Incremented on a routine master-password change so stored
     * passkey unlock envelopes can detect they now wrap a dead key.
     *
     * @var integer
     */
    protected int $unlockKeyEpoch = 1;

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
        $this->addType(fieldName: 'unlockKeyEpoch', type: 'integer');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'ownerType'      => $this->ownerType,
            'ownerId'        => $this->ownerId,
            'certificate'    => $this->certificate,
            'privateKey'     => $this->privateKey,
            'status'         => $this->status,
            'revokedAt'      => $this->revokedAt?->format('c'),
            'revokedReason'  => $this->revokedReason,
            'revokedBy'      => $this->revokedBy,
            'reinstatedAt'   => $this->reinstatedAt?->format('c'),
            'reinstatedBy'   => $this->reinstatedBy,
            'createdAt'      => $this->createdAt?->format('c'),
            'unlockKeyEpoch' => $this->unlockKeyEpoch,
        ];
    }//end jsonSerialize()
}//end class
