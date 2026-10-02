<?php

/**
 * Keepiq Team Folder Member Entity
 *
 * Database entity representing one member (a Nextcloud user or group) of
 * a TeamFolder. Group members expand statically to individual user
 * shares at fan-out time (ADR-003 — no live group key).
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

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
 * @method string getGrade()
 * @method void setGrade(string $grade)
 * @method bool|null getUseOnly()
 * @method void setUseOnly(bool $useOnly)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 */
class TeamFolderMember extends Entity implements JsonSerializable {

	/**
	 * The TeamFolder this membership belongs to.
	 *
	 * @var string
	 */
	protected string $teamFolderId = '';

	/**
	 * The member type: `user` or `group`.
	 *
	 * Initialized empty on purpose: a non-empty default would make
	 * setMemberType('user') a no-change set, so the Entity would never
	 * mark the field dirty and QBMapper::insert would omit the column
	 * (NOT NULL violation) — found in live verification.
	 *
	 * @var string
	 */
	protected string $memberType = '';

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
	 * The permission grade: `read` (default) or `write`
	 * (folder-permission-grades §1.2). Empty default (NC Entity
	 * dirty-tracking); readers treat '' as `read`.
	 *
	 * @var string
	 */
	protected string $grade = '';

	/**
	 * Whether the recipient may only use the value, not view or copy it
	 * (sharing-use-only-and-expiring-shares D1).
	 *
	 * @var boolean|null
	 */
	protected ?bool $useOnly = false;

	/**
	 * When the access this grant gives ends (nullable = no end).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

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
	public function getId(): string {
		return (string)$this->id;
	}//end getId()

	/**
	 * Set the UUID primary key.
	 *
	 * @param string $id The UUID
	 *
	 * @return void
	 */
	public function setId($id): void {
		$this->setter(name: 'id', args: [$id]);
	}//end setId()

	/**
	 * Constructor for TeamFolderMember.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'teamFolderId', type: 'string');
		$this->addType(fieldName: 'memberType', type: 'string');
		$this->addType(fieldName: 'memberId', type: 'string');
		$this->addType(fieldName: 'addedBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'grade', type: 'string');
		$this->addType(fieldName: 'useOnly', type: 'boolean');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
	}//end __construct()

	/**
	 * The grades a membership can carry, lowest first
	 * (sharing-team-folder-manager-role D1): Viewer, Editor, Manager.
	 *
	 * @var string[]
	 */
	public const GRADES = ['read', 'write', 'manage'];

	/**
	 * The effective grade — an unset/legacy/unknown row reads as `read`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
	 */
	public function effectiveGrade(): string {
		if (in_array($this->grade, self::GRADES, true) === true) {
			return $this->grade;
		}

		return 'read';
	}//end effectiveGrade()

	/**
	 * The rank of a grade: `read` < `write` < `manage`; anything else is -1.
	 *
	 * @param string|null $grade The grade
	 *
	 * @return int
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-effective-grade-is-the-highest-grade-along-the-ancestor-folder-chain
	 */
	public static function rank(?string $grade): int {
		$rank = array_search($grade, self::GRADES, true);
		if ($rank === false) {
			return -1;
		}

		return $rank;
	}//end rank()

	/**
	 * Whether a grade may update values for the whole team: `write` and
	 * everything above it.
	 *
	 * @param string|null $grade The grade
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sharing-team-folder-manager-role/specs/folder-permission-grades/spec.md#requirement-team-folder-membership-carries-a-read-write-or-manage-grade
	 */
	public static function allowsWrite(?string $grade): bool {
		return self::rank(grade: $grade) >= self::rank(grade: 'write');
	}//end allowsWrite()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'teamFolderId' => $this->teamFolderId,
			'memberType' => $this->memberType,
			'memberId' => $this->memberId,
			'addedBy' => $this->addedBy,
			'createdAt' => $this->createdAt?->format('c'),
			'grade' => $this->effectiveGrade(),
			'useOnly' => ($this->useOnly === true),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
