<?php

/**
 * Doriath Secret Delegation Entity
 *
 * Database entity representing a secret ownership delegation.
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
 * Entity representing a delegation of secret ownership to another user.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOriginalOwnerId()
 * @method void setOriginalOwnerId(string $originalOwnerId)
 * @method string getDelegatedTo()
 * @method void setDelegatedTo(string $delegatedTo)
 * @method DateTime|null getDelegatedAt()
 * @method void setDelegatedAt(?DateTime $delegatedAt)
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
     * The ID of the secret being delegated.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The user ID of the original secret owner.
     *
     * @var string
     */
    protected string $originalOwnerId = '';

    /**
     * The user ID of the user to whom ownership is delegated.
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
     * The user ID of the person who initiated the delegation.
     *
     * @var string
     */
    protected string $initiatedBy = '';

    /**
     * Whether the delegation has been made permanent.
     *
     * @var boolean
     */
    protected bool $isPermanent = false;

    /**
     * When the delegation was made permanent, if applicable.
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
     * Serialize the entity to an array for JSON output.
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
