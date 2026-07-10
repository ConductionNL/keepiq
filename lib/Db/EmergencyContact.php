<?php

/**
 * Doriath Emergency Contact Entity
 *
 * Database entity representing a trusted emergency contact designated by a
 * vault owner. Designation stores, at confirmation time, the owner's vault
 * key material re-wrapped under the contact's own active suite public key —
 * the server never sees plaintext key material at any step (zero-knowledge,
 * ADR-003).
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
 * Entity representing a designated emergency contact.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getContactId()
 * @method void setContactId(string $contactId)
 * @method int getWaitPeriodHours()
 * @method void setWaitPeriodHours(int $waitPeriodHours)
 * @method string getLevel()
 * @method void setLevel(string $level)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getWrappedKeyMaterial()
 * @method void setWrappedKeyMaterial(?string $wrappedKeyMaterial)
 * @method string|null getContactSuiteFingerprint()
 * @method void setContactSuiteFingerprint(?string $contactSuiteFingerprint)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getConfirmedAt()
 * @method void setConfirmedAt(?DateTime $confirmedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the spec-mandated DB columns.
 */
class EmergencyContact extends Entity implements JsonSerializable
{

    /**
     * Access level: the contact may read the owner's secrets after grant.
     *
     * @var string
     */
    public const LEVEL_VIEW = 'view';

    /**
     * Access level: the contact effectively becomes a co-owner after grant.
     *
     * @var string
     */
    public const LEVEL_TAKEOVER = 'takeover';

    /**
     * Status: designated but the owner has not yet confirmed the grant, so
     * no wrapped key material exists yet.
     *
     * @var string
     */
    public const STATUS_PENDING_CONFIRMATION = 'pending-confirmation';

    /**
     * Status: confirmed — the wrapped key material is stored and the contact
     * may initiate an access request.
     *
     * @var string
     */
    public const STATUS_ACTIVE = 'active';

    /**
     * Status: revoked by the owner — the relationship is inert.
     *
     * @var string
     */
    public const STATUS_REVOKED = 'revoked';

    /**
     * The Nextcloud user ID of the vault owner.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * The Nextcloud user ID of the designated emergency contact.
     *
     * @var string
     */
    protected string $contactId = '';

    /**
     * The configured wait period, in hours, before an access request
     * auto-grants.
     *
     * @var integer
     */
    protected int $waitPeriodHours = 24;

    /**
     * The access level (view|takeover).
     *
     * @var string
     */
    protected string $level = self::LEVEL_VIEW;

    /**
     * The relationship status.
     *
     * @var string
     */
    protected string $status = self::STATUS_PENDING_CONFIRMATION;

    /**
     * The owner's vault key material re-wrapped under the contact's suite
     * public key (base64 ciphertext). Null until the owner confirms.
     *
     * @var string|null
     */
    protected ?string $wrappedKeyMaterial = null;

    /**
     * The fingerprint of the contact's active suite at confirmation time,
     * so staleness (contact rotated their suite) is detectable.
     *
     * @var string|null
     */
    protected ?string $contactSuiteFingerprint = null;

    /**
     * When the contact was designated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the owner confirmed the grant (nullable).
     *
     * @var DateTime|null
     */
    protected ?DateTime $confirmedAt = null;

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
     * Constructor for EmergencyContact.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'contactId', type: 'string');
        $this->addType(fieldName: 'waitPeriodHours', type: 'integer');
        $this->addType(fieldName: 'level', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'wrappedKeyMaterial', type: 'string');
        $this->addType(fieldName: 'contactSuiteFingerprint', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'confirmedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity for the owner/contact management API. The wrapped
     * key material is OMITTED — it is only ever delivered to the granted
     * contact's browser via the dedicated grant endpoint, never listed.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->getId(),
            'ownerId'         => $this->ownerId,
            'contactId'       => $this->contactId,
            'waitPeriodHours' => $this->waitPeriodHours,
            'level'           => $this->level,
            'status'          => $this->status,
            'hasKeyMaterial'  => ($this->wrappedKeyMaterial !== null),
            'createdAt'       => $this->createdAt?->format('c'),
            'confirmedAt'     => $this->confirmedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
