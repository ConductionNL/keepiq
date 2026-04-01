<?php

/**
 * Doriath CA Certificate Entity
 *
 * Database entity representing a CA certificate (root or intermediate).
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
 * Entity representing a CA certificate with key storage and lifecycle tracking.
 *
 * @method string getType()
 * @method void setType(string $type)
 * @method string getCertificate()
 * @method void setCertificate(string $certificate)
 * @method string|null getPrivateKey()
 * @method void setPrivateKey(?string $privateKey)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 * @method string|null getSuccessorId()
 * @method void setSuccessorId(?string $successorId)
 */
class CACertificate extends Entity implements JsonSerializable
{

    /**
     * The certificate type (root or intermediate).
     *
     * @var string
     */
    protected string $type = '';

    /**
     * The PEM-encoded certificate.
     *
     * @var string
     */
    protected string $certificate = '';

    /**
     * The encrypted private key.
     *
     * @var string|null
     */
    protected ?string $privateKey = null;

    /**
     * When the certificate was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the certificate expires.
     *
     * @var DateTime|null
     */
    protected ?DateTime $expiresAt = null;

    /**
     * Whether the certificate is active.
     *
     * @var boolean
     */
    protected bool $isActive = false;

    /**
     * When the certificate was revoked.
     *
     * @var DateTime|null
     */
    protected ?DateTime $revokedAt = null;

    /**
     * The ID of the successor certificate.
     *
     * @var string|null
     */
    protected ?string $successorId = null;

    /**
     * Constructor for CACertificate.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'certificate', type: 'string');
        $this->addType(fieldName: 'privateKey', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'expiresAt', type: 'datetime');
        $this->addType(fieldName: 'isActive', type: 'boolean');
        $this->addType(fieldName: 'revokedAt', type: 'datetime');
        $this->addType(fieldName: 'successorId', type: 'string');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
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
