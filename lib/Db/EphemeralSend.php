<?php

/**
 * Keepiq Ephemeral Send Entity
 *
 * One ad-hoc burn-after-reading share (ephemeral-send §1). The payload
 * is client-encrypted AES-256-GCM ciphertext; the server never holds a
 * decryptable content key (fragment mode) or plaintext (ADR-003).
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
 * Entity representing one ephemeral send.
 *
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getEncryptedPayload()
 * @method void setEncryptedPayload(string $encryptedPayload)
 * @method string getPayloadType()
 * @method void setPayloadType(string $payloadType)
 * @method bool getHasPassword()
 * @method void setHasPassword(bool $hasPassword)
 * @method string|null getWrappedKey()
 * @method void setWrappedKey(?string $wrappedKey)
 * @method string|null getArgon2idSalt()
 * @method void setArgon2idSalt(?string $argon2idSalt)
 * @method int getMaxViews()
 * @method void setMaxViews(int $maxViews)
 * @method int getViewCount()
 * @method void setViewCount(int $viewCount)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method int getFailedAttempts()
 * @method void setFailedAttempts(int $failedAttempts)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class EphemeralSend extends Entity implements JsonSerializable {

	/**
	 * The creating owner.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * The >=128-bit URL token.
	 *
	 * @var string
	 */
	protected string $token = '';

	/**
	 * The client-encrypted payload (base64 IV||ciphertext).
	 *
	 * @var string
	 */
	protected string $encryptedPayload = '';

	/**
	 * The payload type hint: `text` or `credential`.
	 *
	 * @var string
	 */
	protected string $payloadType = '';

	/**
	 * Whether a password wraps the content key.
	 *
	 * @var boolean
	 */
	protected bool $hasPassword = false;

	/**
	 * The Argon2id-wrapped content key (password mode only).
	 *
	 * @var string|null
	 */
	protected ?string $wrappedKey = null;

	/**
	 * The Argon2id salt (password mode only).
	 *
	 * @var string|null
	 */
	protected ?string $argon2idSalt = null;

	/**
	 * Maximum views before the send burns.
	 *
	 * @var integer
	 */
	protected int $maxViews = 1;

	/**
	 * Confirmed views so far.
	 *
	 * @var integer
	 */
	protected int $viewCount = 0;

	/**
	 * Optional expiry instant (null = view-limit only).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * Failed password attempts (burn at 5).
	 *
	 * @var integer
	 */
	protected int $failedAttempts = 0;

	/**
	 * When the send was created.
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
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'token', type: 'string');
		$this->addType(fieldName: 'encryptedPayload', type: 'string');
		$this->addType(fieldName: 'payloadType', type: 'string');
		$this->addType(fieldName: 'hasPassword', type: 'boolean');
		$this->addType(fieldName: 'wrappedKey', type: 'string');
		$this->addType(fieldName: 'argon2idSalt', type: 'string');
		$this->addType(fieldName: 'maxViews', type: 'integer');
		$this->addType(fieldName: 'viewCount', type: 'integer');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'failedAttempts', type: 'integer');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Owner-facing JSON shape — the ciphertext and wrapped key stay out
	 * of list responses; the token is included so the owner can rebuild
	 * the link.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'token' => $this->token,
			'payloadType' => $this->payloadType,
			'hasPassword' => $this->hasPassword,
			'maxViews' => $this->maxViews,
			'viewCount' => $this->viewCount,
			'remainingViews' => max(0, ($this->maxViews - $this->viewCount)),
			'expiresAt' => $this->expiresAt?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
