<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getType()
 * @method void setType(string $type)
 * @method string getCertificate()
 * @method void setCertificate(string $certificate)
 * @method string|null getPrivateKey()
 * @method void setPrivateKey(?string $privateKey)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime getExpiresAt()
 * @method void setExpiresAt(\DateTime $expiresAt)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 * @method string|null getSuccessorId()
 * @method void setSuccessorId(?string $successorId)
 */
class CACertificate extends Entity implements JsonSerializable
{
    protected string $type = '';
    protected string $certificate = '';
    protected ?string $privateKey = null;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $expiresAt = null;
    protected bool $isActive = false;
    protected ?\DateTime $revokedAt = null;
    protected ?string $successorId = null;

    public function __construct()
    {
        $this->addType('type', 'string');
        $this->addType('certificate', 'string');
        $this->addType('privateKey', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('expiresAt', 'datetime');
        $this->addType('isActive', 'boolean');
        $this->addType('revokedAt', 'datetime');
        $this->addType('successorId', 'string');
    }//end __construct()

    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->getId(),
            'type'        => $this->type,
            'certificate' => $this->certificate,
            'createdAt'   => $this->createdAt?->format('c'),
            'expiresAt'   => $this->expiresAt?->format('c'),
            'isActive'    => $this->isActive,
            'revokedAt'   => $this->revokedAt?->format('c'),
            'successorId' => $this->successorId,
        ];
    }//end jsonSerialize()
}//end class
