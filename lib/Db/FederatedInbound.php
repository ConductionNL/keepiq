<?php

/**
 * Keepiq Federated Inbound Share Entity
 *
 * The receiving side of one secret a partner instance shared with a user
 * here (sharing-federated-recipients D4): pending until the user accepts,
 * then linked to the read-only copy in their vault.
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
 * One inbound federated share.
 *
 * @method string getRecipientUid()
 * @method void setRecipientUid(string $recipientUid)
 * @method string getSenderCloudId()
 * @method void setSenderCloudId(string $senderCloudId)
 * @method string getPartnerId()
 * @method void setPartnerId(string $partnerId)
 * @method string getRemoteShareId()
 * @method void setRemoteShareId(string $remoteShareId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getSharedSecretEnc()
 * @method void setSharedSecretEnc(string $sharedSecretEnc)
 * @method string|null getSecretId()
 * @method void setSecretId(?string $secretId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getReceivedAt()
 * @method void setReceivedAt(DateTime $receivedAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class FederatedInbound extends Entity implements JsonSerializable {
	/**
	 * Announced, not yet accepted.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Accepted: the read-only copy is in the recipient's vault.
	 *
	 * @var string
	 */
	public const STATUS_ACCEPTED = 'accepted';

	/**
	 * Declined by the recipient.
	 *
	 * @var string
	 */
	public const STATUS_DECLINED = 'declined';

	/**
	 * Revoked by the sender.
	 *
	 * @var string
	 */
	public const STATUS_REVOKED = 'revoked';

	/**
	 * The local user the share was made to.
	 *
	 * @var string
	 */
	protected string $recipientUid = '';

	/**
	 * The owner's cloud id on the sending instance.
	 *
	 * @var string
	 */
	protected string $senderCloudId = '';

	/**
	 * The partner instance that sent the share.
	 *
	 * @var string
	 */
	protected string $partnerId = '';

	/**
	 * The share id on the sending instance (the OCM providerId).
	 *
	 * @var string
	 */
	protected string $remoteShareId = '';

	/**
	 * The secret's plain name, as the sender announced it.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The share's shared secret, encrypted with ICrypto.
	 *
	 * @var string
	 */
	protected string $sharedSecretEnc = '';

	/**
	 * The local read-only copy once accepted, or null.
	 *
	 * @var string|null
	 */
	protected ?string $secretId = null;

	/**
	 * One of the STATUS_ constants.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_PENDING;

	/**
	 * When the share arrived.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $receivedAt = null;

	/**
	 * When the state last changed.
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
		$this->addType(fieldName: 'recipientUid', type: 'string');
		$this->addType(fieldName: 'senderCloudId', type: 'string');
		$this->addType(fieldName: 'partnerId', type: 'string');
		$this->addType(fieldName: 'remoteShareId', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'sharedSecretEnc', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'receivedAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the recipient's list: never the shared secret.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'senderCloudId' => $this->senderCloudId,
			'name' => $this->name,
			'secretId' => $this->secretId,
			'status' => $this->status,
			'receivedAt' => $this->receivedAt?->format(DATE_ATOM),
			'updatedAt' => $this->updatedAt?->format(DATE_ATOM),
		];
	}//end jsonSerialize()
}//end class
