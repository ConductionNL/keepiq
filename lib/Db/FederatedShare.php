<?php

/**
 * Keepiq Federated Share Entity
 *
 * The sending side of one secret shared with a user of a partner instance
 * (sharing-federated-recipients D4): the latest ciphertext the owner's
 * browser made for the recipient's verified certificate, the hash of the
 * share's shared secret that the recipient's server must present to pull it,
 * and the state of a notification still to be delivered (D5).
 *
 * @category Db
 * @package  OCA\Keepiq\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
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
 * One outbound federated share.
 *
 * @method string getSourceSecretId()
 * @method void setSourceSecretId(string $sourceSecretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRecipientCloudId()
 * @method void setRecipientCloudId(string $recipientCloudId)
 * @method string getPartnerId()
 * @method void setPartnerId(string $partnerId)
 * @method string getRecipientCertFingerprint()
 * @method void setRecipientCertFingerprint(string $recipientCertFingerprint)
 * @method string|null getKey()
 * @method void setKey(?string $key)
 * @method string|null getLogin()
 * @method void setLogin(?string $login)
 * @method string|null getAdditionalFields()
 * @method void setAdditionalFields(?string $additionalFields)
 * @method string getSharedSecretHash()
 * @method void setSharedSecretHash(string $sharedSecretHash)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getPendingNotification()
 * @method void setPendingNotification(?string $pendingNotification)
 * @method int getNotifyAttempts()
 * @method void setNotifyAttempts(int $notifyAttempts)
 * @method DateTime|null getNextNotifyAt()
 * @method void setNextNotifyAt(?DateTime $nextNotifyAt)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the design's DB columns.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedShare extends Entity implements JsonSerializable {
	/**
	 * The share is live: the recipient's server may pull it.
	 *
	 * @var string
	 */
	public const STATUS_ACTIVE = 'active';

	/**
	 * The partner was removed or the recipient's certificate no longer
	 * verifies: nothing is served until the owner revokes or shares again.
	 *
	 * @var string
	 */
	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * A notification could not be delivered after the last retry.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The owner revoked it; the SHARE_UNSHARED notification is still on its
	 * way. Nothing is served; the row goes once the notification arrives.
	 *
	 * @var string
	 */
	public const STATUS_REVOKED = 'revoked';

	/**
	 * The recipient removed the copy they had accepted
	 * (sharing-federated-recipients task 4.4). Nothing is served or sent;
	 * the owner may share again.
	 *
	 * @var string
	 */
	public const STATUS_DECLINED = 'declined';

	/**
	 * The owner's secret this share copies.
	 *
	 * @var string
	 */
	protected string $sourceSecretId = '';

	/**
	 * The owner's user id.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * The recipient's federated cloud id.
	 *
	 * @var string
	 */
	protected string $recipientCloudId = '';

	/**
	 * The partner instance the recipient belongs to.
	 *
	 * @var string
	 */
	protected string $partnerId = '';

	/**
	 * Lowercase hex SHA-256 of the recipient certificate the ciphertext was made for.
	 *
	 * @var string
	 */
	protected string $recipientCertFingerprint = '';

	/**
	 * The value, encrypted in the owner's browser for the recipient.
	 *
	 * @var string|null
	 */
	protected ?string $key = null;

	/**
	 * The login, encrypted for the recipient.
	 *
	 * @var string|null
	 */
	protected ?string $login = null;

	/**
	 * The additional fields, encrypted for the recipient.
	 *
	 * @var string|null
	 */
	protected ?string $additionalFields = null;

	/**
	 * Lowercase hex SHA-256 of the share's shared secret; the secret itself is
	 * never stored on the sending side.
	 *
	 * @var string
	 */
	protected string $sharedSecretHash = '';

	/**
	 * One of the STATUS_ constants.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_ACTIVE;

	/**
	 * An OCM notification still to deliver (SHARE_UPDATED, SHARE_UNSHARED), or null.
	 *
	 * @var string|null
	 */
	protected ?string $pendingNotification = null;

	/**
	 * How many times delivery of the pending notification failed.
	 *
	 * @var integer
	 */
	protected int $notifyAttempts = 0;

	/**
	 * When the retry job tries the pending notification again.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $nextNotifyAt = null;

	/**
	 * When the share was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the ciphertext or the state last changed.
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
	 * Constructor: declare column types for QBMapper hydration.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'sourceSecretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'recipientCloudId', type: 'string');
		$this->addType(fieldName: 'partnerId', type: 'string');
		$this->addType(fieldName: 'recipientCertFingerprint', type: 'string');
		$this->addType(fieldName: 'key', type: 'string');
		$this->addType(fieldName: 'login', type: 'string');
		$this->addType(fieldName: 'additionalFields', type: 'string');
		$this->addType(fieldName: 'sharedSecretHash', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'pendingNotification', type: 'string');
		$this->addType(fieldName: 'notifyAttempts', type: 'integer');
		$this->addType(fieldName: 'nextNotifyAt', type: 'datetime');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the owner's API: identifiers and state only, never the
	 * ciphertext or the shared secret hash.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'sourceSecretId' => $this->sourceSecretId,
			'recipientCloudId' => $this->recipientCloudId,
			'partnerId' => $this->partnerId,
			'recipientCertFingerprint' => $this->recipientCertFingerprint,
			'status' => $this->status,
			'pendingNotification' => $this->pendingNotification,
			'notifyAttempts' => $this->notifyAttempts,
			'createdAt' => $this->createdAt?->format(DATE_ATOM),
			'updatedAt' => $this->updatedAt?->format(DATE_ATOM),
		];
	}//end jsonSerialize()
}//end class
