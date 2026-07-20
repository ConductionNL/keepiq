<?php

/**
 * Doriath Passkey Credential Entity
 *
 * One WebAuthn PRF unlock envelope (passkey-vault-login §1.2): the
 * authenticator's registered credential plus an AES-256-GCM envelope
 * wrapping the vault unlock key under a KEK derived from the
 * authenticator-held PRF secret. The server stores the wrapped envelope
 * but never the PRF secret, the master password, or the plaintext
 * unlock key (ADR-003 posture preserved).
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
 * Entity representing an enrolled passkey unlock credential.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getCredentialId()
 * @method void setCredentialId(string $credentialId)
 * @method string|null getPublicKey()
 * @method void setPublicKey(?string $publicKey)
 * @method string getPrfSalt()
 * @method void setPrfSalt(string $prfSalt)
 * @method string getWrappedUnlockKey()
 * @method void setWrappedUnlockKey(string $wrappedUnlockKey)
 * @method int getUnlockKeyEpoch()
 * @method void setUnlockKeyEpoch(int $unlockKeyEpoch)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string|null getTransports()
 * @method void setTransports(?string $transports)
 * @method string|null getAaguid()
 * @method void setAaguid(?string $aaguid)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getLastUsedAt()
 * @method void setLastUsedAt(?DateTime $lastUsedAt)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class PasskeyCredential extends Entity implements JsonSerializable
{

    /**
     * Owner NC user id.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * base64url WebAuthn credential id.
     *
     * @var string
     */
    protected string $credentialId = '';

    /**
     * COSE public key (management/anti-confusion; not an E2E gate).
     *
     * @var string|null
     */
    protected ?string $publicKey = null;

    /**
     * base64 32-byte per-credential PRF input salt.
     *
     * @var string
     */
    protected string $prfSalt = '';

    /**
     * AES-256-GCM envelope wrapping the vault unlock key.
     *
     * @var string
     */
    protected string $wrappedUnlockKey = '';

    /**
     * Epoch of the private-key wrap this envelope targets (§D4).
     *
     * @var integer
     */
    protected int $unlockKeyEpoch = 1;

    /**
     * User-facing nickname.
     *
     * @var string|null
     */
    protected ?string $label = null;

    /**
     * Comma-joined WebAuthn transports.
     *
     * @var string|null
     */
    protected ?string $transports = null;

    /**
     * Authenticator model id.
     *
     * @var string|null
     */
    protected ?string $aaguid = null;

    /**
     * active | stale | revoked.
     *
     * @var string
     */
    protected string $status = 'active';

    /**
     * Last successful unlock.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastUsedAt = null;

    /**
     * Enrollment time.
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
     * Constructor: declare column types for QBMapper hydration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'credentialId', type: 'string');
        $this->addType(fieldName: 'publicKey', type: 'string');
        $this->addType(fieldName: 'prfSalt', type: 'string');
        $this->addType(fieldName: 'wrappedUnlockKey', type: 'string');
        $this->addType(fieldName: 'unlockKeyEpoch', type: 'integer');
        $this->addType(fieldName: 'label', type: 'string');
        $this->addType(fieldName: 'transports', type: 'string');
        $this->addType(fieldName: 'aaguid', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'lastUsedAt', type: 'datetime');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * Management serialization — never exposes the wrapped envelope or
     * PRF salt in the list view (only the unlock-options endpoint emits
     * those, to the owner).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'label'      => $this->label,
            'transports' => $this->transports,
            'aaguid'     => $this->aaguid,
            'status'     => $this->status,
            'lastUsedAt' => $this->lastUsedAt?->format('c'),
            'createdAt'  => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
