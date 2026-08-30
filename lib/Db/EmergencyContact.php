<?php

/**
 * Keepiq Emergency Contact Entity
 *
 * Database entity for the break-glass emergency-access relationship between a
 * vault owner (grantor) and a trusted Nextcloud user (grantee). The row holds
 * the lifecycle state, the grantor-configured wait period, and the grantee-
 * encrypted recovery envelope (the grantor's private key hybrid-encrypted to the
 * grantee's public certificate — see add-emergency-access design D1). The server
 * stores ONLY this opaque envelope ciphertext; it never holds a usable key, so
 * the vault stays zero-knowledge (ADR-003).
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
 * Entity representing a break-glass emergency-access relationship.
 *
 * @method string getGrantorUserId()
 * @method void setGrantorUserId(string $grantorUserId)
 * @method string getGranteeUserId()
 * @method void setGranteeUserId(string $granteeUserId)
 * @method string getAccessLevel()
 * @method void setAccessLevel(string $accessLevel)
 * @method int getWaitPeriodDays()
 * @method void setWaitPeriodDays(int $waitPeriodDays)
 * @method string getState()
 * @method void setState(string $state)
 * @method DateTime|null getRequestedAt()
 * @method void setRequestedAt(?DateTime $requestedAt)
 * @method string|null getRecoveryEnvelope()
 * @method void setRecoveryEnvelope(?string $recoveryEnvelope)
 * @method string|null getGrantorSuiteId()
 * @method void setGrantorSuiteId(?string $grantorSuiteId)
 * @method string|null getGranteeSuiteId()
 * @method void setGranteeSuiteId(?string $granteeSuiteId)
 * @method string|null getInvalidatedReason()
 * @method void setInvalidatedReason(?string $invalidatedReason)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class EmergencyContact extends Entity implements JsonSerializable {
	public const ACCESS_VIEW = 'view';

	public const STATE_GRANTED = 'granted';
	public const STATE_REQUESTED = 'requested';
	public const STATE_DECLINED = 'declined';
	public const STATE_APPROVED = 'approved';
	public const STATE_INVALIDATED = 'invalidated';

	/**
	 * The grantor (vault owner) Nextcloud user ID.
	 *
	 * @var string
	 */
	protected string $grantorUserId = '';

	/**
	 * The grantee (emergency contact) Nextcloud user ID.
	 *
	 * @var string
	 */
	protected string $granteeUserId = '';

	/**
	 * The access level — v1 is always 'view'.
	 *
	 * @var string
	 */
	protected string $accessLevel = self::ACCESS_VIEW;

	/**
	 * The grantor-configured wait period in days.
	 *
	 * @var integer
	 */
	protected int $waitPeriodDays = 7;

	/**
	 * The lifecycle state (granted|requested|declined|approved|invalidated).
	 *
	 * @var string
	 */
	protected string $state = self::STATE_GRANTED;

	/**
	 * When the current break-glass request was initiated (nullable).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $requestedAt = null;

	/**
	 * The grantee-encrypted recovery envelope (opaque ciphertext, nullable).
	 *
	 * @var string|null
	 */
	protected ?string $recoveryEnvelope = null;

	/**
	 * The grantor's EncryptionSuite id at designation (for rotation/revocation
	 * invalidation — the envelope escrows that suite's private key).
	 *
	 * @var string|null
	 */
	protected ?string $grantorSuiteId = null;

	/**
	 * The grantee's EncryptionSuite id at designation (the envelope is encrypted
	 * to this suite's certificate; a grantee-suite revocation invalidates it).
	 *
	 * @var string|null
	 */
	protected ?string $granteeSuiteId = null;

	/**
	 * Why the relationship was invalidated (e.g. 'grantor_rotation'), nullable.
	 *
	 * @var string|null
	 */
	protected ?string $invalidatedReason = null;

	/**
	 * When the relationship was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the relationship was last updated.
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
	 * Constructor for EmergencyContact.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'grantorUserId', type: 'string');
		$this->addType(fieldName: 'granteeUserId', type: 'string');
		$this->addType(fieldName: 'accessLevel', type: 'string');
		$this->addType(fieldName: 'waitPeriodDays', type: 'integer');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'requestedAt', type: 'datetime');
		$this->addType(fieldName: 'recoveryEnvelope', type: 'string');
		$this->addType(fieldName: 'grantorSuiteId', type: 'string');
		$this->addType(fieldName: 'granteeSuiteId', type: 'string');
		$this->addType(fieldName: 'invalidatedReason', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the management API. The recovery envelope is intentionally
	 * OMITTED — it is only ever released by the fetch-envelope endpoint, and
	 * only when the request is approved and the caller is the grantee.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'grantorUserId' => $this->grantorUserId,
			'granteeUserId' => $this->granteeUserId,
			'accessLevel' => $this->accessLevel,
			'waitPeriodDays' => $this->waitPeriodDays,
			'state' => $this->state,
			'requestedAt' => $this->requestedAt?->format('c'),
			'hasEnvelope' => ($this->recoveryEnvelope !== null && $this->recoveryEnvelope !== ''),
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
