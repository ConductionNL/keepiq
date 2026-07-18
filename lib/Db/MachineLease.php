<?php

/**
 * Doriath Machine Lease Entity
 *
 * One short-lived access grant of one application to one secret on the
 * machine secret-store API (machine-secret-leases §1.1). Leases govern
 * access-grant LIFETIME only — no key material, no ciphertext, no
 * dynamic credential minting.
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
 * Entity representing one machine lease.
 *
 * @method string getApplicationId()
 * @method void setApplicationId(string $applicationId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method DateTime|null getGrantedAt()
 * @method void setGrantedAt(DateTime $grantedAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(DateTime $expiresAt)
 * @method int getRenewedCount()
 * @method void setRenewedCount(int $renewedCount)
 * @method DateTime|null getLastRenewedAt()
 * @method void setLastRenewedAt(?DateTime $lastRenewedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 * @method string|null getRevokedBy()
 * @method void setRevokedBy(?string $revokedBy)
 */
class MachineLease extends Entity implements JsonSerializable
{

    /**
     * The holding application.
     *
     * @var string
     */
    protected string $applicationId = '';

    /**
     * The leased secret.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The grant scope (`read` today). Empty default (NC Entity
     * dirty-tracking).
     *
     * @var string
     */
    protected string $scope = '';

    /**
     * When the lease was granted.
     *
     * @var DateTime|null
     */
    protected ?DateTime $grantedAt = null;

    /**
     * When the lease expires.
     *
     * @var DateTime|null
     */
    protected ?DateTime $expiresAt = null;

    /**
     * How many times the lease has been renewed.
     *
     * @var integer
     */
    protected int $renewedCount = 0;

    /**
     * The last renewal instant (null = never renewed).
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastRenewedAt = null;

    /**
     * The lease status: `active`, `expired`, or `revoked`. Empty
     * default (NC Entity dirty-tracking).
     *
     * @var string
     */
    protected string $status = '';

    /**
     * When the lease was revoked (null unless revoked).
     *
     * @var DateTime|null
     */
    protected ?DateTime $revokedAt = null;

    /**
     * Who revoked the lease (user id or 'self').
     *
     * @var string|null
     */
    protected ?string $revokedBy = null;

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
     * Constructor: declare column types for QBMapper hydration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'applicationId', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'scope', type: 'string');
        $this->addType(fieldName: 'grantedAt', type: 'datetime');
        $this->addType(fieldName: 'expiresAt', type: 'datetime');
        $this->addType(fieldName: 'renewedCount', type: 'integer');
        $this->addType(fieldName: 'lastRenewedAt', type: 'datetime');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'revokedAt', type: 'datetime');
        $this->addType(fieldName: 'revokedBy', type: 'string');
    }//end __construct()

    /**
     * JSON shape — identifiers, lifetimes, and status only.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'            => $this->getId(),
            'applicationId' => $this->applicationId,
            'secretId'      => $this->secretId,
            'scope'         => $this->scope,
            'grantedAt'     => $this->grantedAt?->format('c'),
            'expiresAt'     => $this->expiresAt?->format('c'),
            'renewedCount'  => $this->renewedCount,
            'lastRenewedAt' => $this->lastRenewedAt?->format('c'),
            'status'        => $this->status,
            'revokedAt'     => $this->revokedAt?->format('c'),
            'revokedBy'     => $this->revokedBy,
        ];
    }//end jsonSerialize()
}//end class
