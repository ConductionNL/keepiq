<?php

/**
 * Doriath Folder Entity
 *
 * Database entity representing a folder for organising secrets.
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
 * Entity representing a folder with hierarchical parent-child support.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getParentId()
 * @method void setParentId(?string $parentId)
 * @method string getOwnerType()
 * @method void setOwnerType(string $ownerType)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class Folder extends Entity implements JsonSerializable
{

    /**
     * The folder name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The parent folder ID.
     *
     * @var string|null
     */
    protected ?string $parentId = null;

    /**
     * The owner type (user/application).
     *
     * @var string
     */
    protected string $ownerType = '';

    /**
     * The owner ID.
     *
     * @var string
     */
    protected string $ownerId = '';

    /**
     * When the folder was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the folder was last updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

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
     * Constructor for Folder.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'parentId', type: 'string');
        $this->addType(fieldName: 'ownerType', type: 'string');
        $this->addType(fieldName: 'ownerId', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'        => $this->getId(),
            'name'      => $this->name,
            'parentId'  => $this->parentId,
            'ownerType' => $this->ownerType,
            'ownerId'   => $this->ownerId,
            'createdAt' => $this->createdAt?->format('c'),
            'updatedAt' => $this->updatedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
