<?php

/**
 * Keepiq Share Target Entity
 *
 * Database entity representing a single user-to-user share of a secret —
 * one row per recipient. Each row links a source secret to a recipient's
 * encrypted Secret copy.
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
 * Entity representing a user-to-user secret share.
 *
 * @method string getSourceSecretId()
 * @method void setSourceSecretId(string $sourceSecretId)
 * @method string getTargetUserId()
 * @method void setTargetUserId(string $targetUserId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string|null getGroupShareId()
 * @method void setGroupShareId(?string $groupShareId)
 * @method string|null getTeamFolderId()
 * @method void setTeamFolderId(?string $teamFolderId)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method bool|null getUseOnly()
 * @method void setUseOnly(bool $useOnly)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getQuarantinedAt()
 * @method void setQuarantinedAt(?DateTime $quarantinedAt)
 * @method string|null getQuarantineReason()
 * @method void setQuarantineReason(?string $quarantineReason)
 */
class ShareTarget extends Entity implements JsonSerializable {

	/**
	 * The source secret ID (owner's copy).
	 *
	 * @var string
	 */
	protected string $sourceSecretId = '';

	/**
	 * The Nextcloud user ID of the recipient.
	 *
	 * @var string
	 */
	protected string $targetUserId = '';

	/**
	 * The ID of the recipient's encrypted Secret copy.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * Optional ID of the group share this row was derived from.
	 *
	 * @var string|null
	 */
	protected ?string $groupShareId = null;

	/**
	 * Optional ID of the team folder this row was derived from.
	 *
	 * @var string|null
	 */
	protected ?string $teamFolderId = null;

	/**
	 * The user ID that initiated the share.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When the share was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

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
	 * When this row was quarantined, or null when it is active. The mappers'
	 * finders never return a quarantined row (GHSA-m5p9-6pw7-pf3g), so no
	 * runtime path reads, writes or deletes its copy; `occ
	 * keepiq:share-quarantine` lists, releases or drops it.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $quarantinedAt = null;

	/**
	 * Why this row was quarantined (nullable). Kept when an administrator
	 * releases the row, so the scan does not quarantine it again.
	 *
	 * @var string|null
	 */
	protected ?string $quarantineReason = null;

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
	 * Constructor for ShareTarget.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'sourceSecretId', type: 'string');
		$this->addType(fieldName: 'targetUserId', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'groupShareId', type: 'string');
		$this->addType(fieldName: 'teamFolderId', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'useOnly', type: 'boolean');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'quarantinedAt', type: 'datetime');
		$this->addType(fieldName: 'quarantineReason', type: 'string');
	}//end __construct()

	/**
	 * Whether the recipient copy this row points at is genuinely held by the
	 * row's recipient: it is the copy named here, it is user-owned, and its
	 * owner is the target user.
	 *
	 * This does NOT stop the core advisory case where the recipient is handed
	 * a pointer to one of their OWN pre-existing secrets (`target === owner`,
	 * so the check passes) — that hole is closed by minting the copy
	 * server-side and retiring the client-supplied-id share-create endpoints.
	 * What it does defend is every path that writes to or deletes a linked
	 * copy against a stored row whose `secret_id` names a DIFFERENT user's
	 * secret: an existing poisoned row, or ordinary DB drift. Such a copy is
	 * left untouched so one user's share lifecycle can never corrupt another
	 * user's secret.
	 *
	 * @param Secret $copy The Secret the row's secret_id resolves to
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function isHeldByTarget(Secret $copy): bool {
		return $copy->getId() === $this->secretId
			&& $copy->getOwnerType() === 'user'
			&& $copy->getOwnerId() === $this->targetUserId;
	}//end isHeldByTarget()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'sourceSecretId' => $this->sourceSecretId,
			'targetUserId' => $this->targetUserId,
			'secretId' => $this->secretId,
			'groupShareId' => $this->groupShareId,
			'teamFolderId' => $this->teamFolderId,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
			'useOnly' => ($this->useOnly === true),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
