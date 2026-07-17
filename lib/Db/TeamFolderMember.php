<?php

/**
 * Doriath Team Folder Member Entity
 *
 * Database entity representing one member (a Nextcloud user or group) of
 * a TeamFolder. Group members expand statically to individual user
 * shares at fan-out time (ADR-003 — no live group key).
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
 * Entity representing a user/group membership on a TeamFolder.
 *
 * @method string getTeamFolderId()
 * @method void setTeamFolderId(string $teamFolderId)
 * @method string getMemberType()
 * @method void setMemberType(string $memberType)
 * @method string getMemberId()
 * @method void setMemberId(string $memberId)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class TeamFolderMember extends Entity implements JsonSerializable
{

    /**
     * The TeamFolder this membership belongs to.
     *
     * @var string
     */
    protected string $teamFolderId = '';

    /**
     * The member type: `user` or `group`.
     *
     * @var string
     */
    protected string $memberType = 'user';

    /**
     * The Nextcloud user ID or group ID.
     *
     * @var string
     */
    protected string $memberId = '';

    /**
     * The owner user ID that added this member.
     *
     * @var string
     */
    protected string $addedBy = '';

    /**
     * When the membership was created.
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
     * Constructor for TeamFolderMember.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'teamFolderId', type: 'string');
        $this->addType(fieldName: 'memberType', type: 'string');
        $this->addType(fieldName: 'memberId', type: 'string');
        $this->addType(fieldName: 'addedBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for the API.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->getId(),
            'teamFolderId' => $this->teamFolderId,
            'memberType'   => $this->memberType,
            'memberId'     => $this->memberId,
            'addedBy'      => $this->addedBy,
            'createdAt'    => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
