<?php

/**
 * Doriath Secret Delegation Entity
 *
 * Database entity representing an ownership delegation: a co-owner grant
 * over a secret to another user who already holds a shared copy.
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
 * Entity representing a SecretDelegation.
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
     * The delegated secret ID.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The original owner's Nextcloud user ID.
     *
     * @var string
     */
    protected string $originalOwnerId = '';

    /**
     * The delegate's Nextcloud user ID.
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
     * Who initiated the delegation (owner or admin).
     *
     * @var string
     */
    protected string $initiatedBy = '';

    /**
     * Whether the delegation is permanent (transfer of ownership).
     *
     * @var bool
     */
    protected bool $isPermanent = false;

    /**
     * When the delegation became permanent.
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
