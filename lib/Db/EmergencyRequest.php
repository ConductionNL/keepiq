<?php

/**
 * Doriath Emergency Request Entity
 *
 * Database entity representing an emergency-access request raised by a
 * designated contact who believes the owner is unavailable. After the
 * configured wait period elapses without an owner rejection, a background
 * job transitions the request to `granted`.
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
 * Entity representing an emergency-access request.
 *
 * @method string getEmergencyContactId()
 * @method void setEmergencyContactId(string $emergencyContactId)
 * @method string getContactId()
 * @method void setContactId(string $contactId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getRequestedAt()
 * @method void setRequestedAt(DateTime $requestedAt)
 * @method DateTime|null getResolvedAt()
 * @method void setResolvedAt(?DateTime $resolvedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the spec-mandated DB columns.
 */
class EmergencyRequest extends Entity implements JsonSerializable
{

    /**
     * Status: the contact has requested access; the wait-period timer runs.
     *
     * @var string
     */
    public const STATUS_REQUESTED = 'requested';

    /**
     * Status: the owner rejected the request during the wait period.
     *
     * @var string
     */
    public const STATUS_REJECTED = 'rejected';

    /**
     * Status: the wait period elapsed without rejection; access granted.
     *
     * @var string
     */
    public const STATUS_GRANTED = 'granted';

    /**
     * Status: the contact cancelled their own pending request.
     *
     * @var string
     */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * The EmergencyContact relationship this request is bound to.
     *
     * @var string
     */
    protected string $emergencyContactId = '';

    /**
     * The Nextcloud user ID of the requesting contact.
     *
     * @var string
     */
    protected string $contactId = '';

    /**
     * The Nextcloud user ID of the vault owner.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * The request status.
     *
     * @var string
     */
    protected string $status = self::STATUS_REQUESTED;

    /**
     * When the request was raised (starts the wait-period timer).
     *
     * @var DateTime|null
     */
    protected ?DateTime $requestedAt = null;

    /**
     * When the request was resolved (rejected/granted/cancelled), nullable.
     *
     * @var DateTime|null
     */
    protected ?DateTime $resolvedAt = null;

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
     * Constructor for EmergencyRequest.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'emergencyContactId', type: 'string');
        $this->addType(fieldName: 'contactId', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'requestedAt', type: 'datetime');
        $this->addType(fieldName: 'resolvedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity for the management API.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->getId(),
            'emergencyContactId' => $this->emergencyContactId,
            'contactId'          => $this->contactId,
            'ownerId'            => $this->ownerId,
            'status'             => $this->status,
            'requestedAt'        => $this->requestedAt?->format('c'),
            'resolvedAt'         => $this->resolvedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
