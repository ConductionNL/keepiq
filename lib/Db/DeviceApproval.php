<?php

/**
 * Keepiq DeviceApproval
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
 * A request from a new device to be unlocked by an approval from an unlocked
 * one (crypto-new-device-approval D1). Holds only public material and, between
 * approval and pickup, the unlock key sealed to the request's one-time key.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getClientKind()
 * @method void setClientKind(string $clientKind)
 * @method string getDeviceLabel()
 * @method void setDeviceLabel(string $deviceLabel)
 * @method string getRequesterIp()
 * @method void setRequesterIp(string $requesterIp)
 * @method string getRequesterAgent()
 * @method void setRequesterAgent(string $requesterAgent)
 * @method string getRequestPublicKey()
 * @method void setRequestPublicKey(string $requestPublicKey)
 * @method string getRequestSecretHash()
 * @method void setRequestSecretHash(string $requestSecretHash)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(DateTime $expiresAt)
 * @method DateTime|null getDecidedAt()
 * @method void setDecidedAt(?DateTime $decidedAt)
 * @method string|null getSealedUnlockKey()
 * @method void setSealedUnlockKey(?string $sealedUnlockKey)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class DeviceApproval extends Entity implements JsonSerializable {

	public const STATUS_PENDING = 'pending';

	public const STATUS_APPROVED = 'approved';

	public const STATUS_DENIED = 'denied';

	public const STATUS_EXPIRED = 'expired';

	public const STATUS_CONSUMED = 'consumed';

	/**
	 * The user the vault belongs to.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The requesting client: `web` or `extension`.
	 *
	 * @var string
	 */
	protected string $clientKind = '';

	/**
	 * A label for the device (browser and operating system).
	 *
	 * @var string
	 */
	protected string $deviceLabel = '';

	/**
	 * The IP address the request came from.
	 *
	 * @var string
	 */
	protected string $requesterIp = '';

	/**
	 * The user agent the request came with.
	 *
	 * @var string
	 */
	protected string $requesterAgent = '';

	/**
	 * The one-time X25519 public key (base64, raw 32 bytes).
	 *
	 * @var string
	 */
	protected string $requestPublicKey = '';

	/**
	 * SHA-256 (hex) of the request secret only the requesting client knows.
	 *
	 * @var string
	 */
	protected string $requestSecretHash = '';

	/**
	 * One of the STATUS_* values.
	 *
	 * @var string
	 */
	protected string $status = '';

	/**
	 * When the request was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the request expires unless decided.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When it was approved or denied.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $decidedAt = null;

	/**
	 * The unlock key sealed to the request key (HPKE, base64 JSON), held
	 * only between approval and pickup.
	 *
	 * @var string|null
	 */
	protected ?string $sealedUnlockKey = null;

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
	 * Constructor for DeviceApproval.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'clientKind', type: 'string');
		$this->addType(fieldName: 'deviceLabel', type: 'string');
		$this->addType(fieldName: 'requesterIp', type: 'string');
		$this->addType(fieldName: 'requesterAgent', type: 'string');
		$this->addType(fieldName: 'requestPublicKey', type: 'string');
		$this->addType(fieldName: 'requestSecretHash', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'decidedAt', type: 'datetime');
		$this->addType(fieldName: 'sealedUnlockKey', type: 'string');
	}//end __construct()

	/**
	 * What the approving device is shown: never the secret hash or the sealed key.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'clientKind' => $this->clientKind,
			'deviceLabel' => $this->deviceLabel,
			'requesterIp' => $this->requesterIp,
			'requestPublicKey' => $this->requestPublicKey,
			'status' => $this->status,
			'createdAt' => $this->createdAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
