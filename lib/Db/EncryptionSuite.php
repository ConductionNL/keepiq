<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
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
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 * @method string|null getRevokedReason()
 * @method void setRevokedReason(?string $revokedReason)
 * @method string|null getRevokedBy()
 * @method void setRevokedBy(?string $revokedBy)
 * @method \DateTime|null getReinstatedAt()
 * @method void setReinstatedAt(?\DateTime $reinstatedAt)
 * @method string|null getReinstatedBy()
 * @method void setReinstatedBy(?string $reinstatedBy)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class EncryptionSuite extends Entity implements JsonSerializable
{
    protected string $ownerType = '';
    protected string $ownerId = '';
    protected ?string $certificate = null;
    protected ?string $privateKey = null;
    protected string $status = 'active';
    protected ?\DateTime $revokedAt = null;
    protected ?string $revokedReason = null;
    protected ?string $revokedBy = null;
    protected ?\DateTime $reinstatedAt = null;
    protected ?string $reinstatedBy = null;
    protected ?\DateTime $createdAt = null;

    public function __construct()
    {
        $this->addType('ownerType', 'string');
        $this->addType('ownerId', 'string');
        $this->addType('certificate', 'string');
        $this->addType('privateKey', 'string');
        $this->addType('status', 'string');
        $this->addType('revokedAt', 'datetime');
        $this->addType('revokedReason', 'string');
        $this->addType('revokedBy', 'string');
        $this->addType('reinstatedAt', 'datetime');
        $this->addType('reinstatedBy', 'string');
        $this->addType('createdAt', 'datetime');
    }//end __construct()

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
        ];
    }//end jsonSerialize()
}//end class
