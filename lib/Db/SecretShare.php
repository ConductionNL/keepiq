<?php

/**
 * Doriath Secret Share Entity
 *
 * Database entity representing a user-to-user secret share. Links an
 * original (source) secret to the encrypted copy in a recipient's vault.
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
 * Entity representing a SecretShare.
 *
 * @method string getSourceSecretId()
 * @method void setSourceSecretId(string $sourceSecretId)
 * @method string getTargetUserId()
 * @method void setTargetUserId(string $targetUserId)
 * @method string|null getSecretId()
 * @method void setSecretId(?string $secretId)
 * @method string|null getGroupShareId()
 * @method void setGroupShareId(?string $groupShareId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class SecretShare extends Entity implements JsonSerializable
{

    /**
     * The original (source) secret ID.
     *
     * @var string
     */
    protected string $sourceSecretId = '';

    /**
     * The recipient's Nextcloud user ID.
     *
     * @var string
     */
    protected string $targetUserId = '';

    /**
     * The encrypted copy's secret ID in the recipient's vault.
     *
     * @var string|null
     */
    protected ?string $secretId = null;

    /**
     * The owning GroupShare ID, when derived from a group share.
     *
     * @var string|null
     */
    protected ?string $groupShareId = null;

    /**
     * When the share was created.
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
     * Constructor for SecretShare.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'sourceSecretId', type: 'string');
        $this->addType(fieldName: 'targetUserId', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'groupShareId', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'sourceSecretId' => $this->sourceSecretId,
            'targetUserId'   => $this->targetUserId,
            'secretId'       => $this->secretId,
            'groupShareId'   => $this->groupShareId,
            'createdAt'      => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
