<?php

/**
 * Doriath Secret Delegation Entity
 *
 * Database entity representing a delegation record — an `original_owner`
 * temporarily (or permanently) handing the share/revoke authority over a
 * Secret to a `delegated_to` user. Backs the implement-user-sharing §6
 * DelegationService.
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
 * Entity representing a SecretDelegation row.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOriginalOwnerId()
 * @method void setOriginalOwnerId(string $originalOwnerId)
 * @method string getDelegatedTo()
 * @method void setDelegatedTo(string $delegatedTo)
 * @method DateTime|null getDelegatedAt()
 * @method void setDelegatedAt(DateTime $delegatedAt)
 * @method string getInitiatedBy()
 * @method void setInitiatedBy(string $initiatedBy)
 * @method bool getIsPermanent()
 * @method void setIsPermanent(bool $isPermanent)
 * @method DateTime|null getMadePermanentAt()
 * @method void setMadePermanentAt(?DateTime $madePermanentAt)
 */
class SecretDelegation extends Entity implements JsonSerializable
{

    /**
     * The Secret ID under delegation.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The original owner being delegated FROM.
     *
     * @var string
     */
    protected string $originalOwnerId = '';

    /**
     * The user receiving delegation rights.
     *
     * @var string
     */
    protected string $delegatedTo = '';

    /**
     * When the delegation was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $delegatedAt = null;

    /**
     * The Nextcloud user that initiated the delegation
     * (owner self-delegation or admin handover).
     *
     * @var string
     */
    protected string $initiatedBy = '';

    /**
     * Whether the delegation has been promoted to permanent (owner change).
     *
     * @var boolean
     */
    protected bool $isPermanent = false;

    /**
     * When the delegation was made permanent — null while temporary.
     *
     * @var DateTime|null
     */
    protected ?DateTime $madePermanentAt = null;

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
     * Constructor for SecretDelegation.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'originalOwnerId', type: 'string');
        $this->addType(fieldName: 'delegatedTo', type: 'string');
        $this->addType(fieldName: 'delegatedAt', type: 'datetime');
        $this->addType(fieldName: 'initiatedBy', type: 'string');
        $this->addType(fieldName: 'isPermanent', type: 'boolean');
        $this->addType(fieldName: 'madePermanentAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for the API.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'              => $this->getId(),
            'secretId'        => $this->secretId,
            'originalOwnerId' => $this->originalOwnerId,
            'delegatedTo'     => $this->delegatedTo,
            'delegatedAt'     => $this->delegatedAt?->format('c'),
            'initiatedBy'     => $this->initiatedBy,
            'isPermanent'     => $this->isPermanent,
            'madePermanentAt' => $this->madePermanentAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
