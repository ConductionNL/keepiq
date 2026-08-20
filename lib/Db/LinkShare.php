<?php

/**
 * Doriath Link Share Entity
 *
 * Database entity representing a password-protected link share of a
 * point-in-time encrypted snapshot of a secret.
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
 * Entity representing a password-protected link share.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getEncryptedSecretSnapshot()
 * @method void setEncryptedSecretSnapshot(string $encryptedSecretSnapshot)
 * @method string getArgon2idSalt()
 * @method void setArgon2idSalt(string $argon2idSalt)
 * @method string getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(string $encryptionSuiteId)
 * @method int getUsageLimit()
 * @method void setUsageLimit(int $usageLimit)
 * @method int getUsageCount()
 * @method void setUsageCount(int $usageCount)
 * @method int getFailedAttempts()
 * @method void setFailedAttempts(int $failedAttempts)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the spec-mandated DB columns.
 */
class LinkShare extends Entity implements JsonSerializable {

	/**
	 * The ID of the secret this link share snapshots.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The public URL-safe access token.
	 *
	 * @var string
	 */
	protected string $token = '';

	/**
	 * The AES-256-GCM encrypted snapshot blob (base64).
	 *
	 * @var string
	 */
	protected string $encryptedSecretSnapshot = '';

	/**
	 * The base64-encoded Argon2id salt.
	 *
	 * @var string
	 */
	protected string $argon2idSalt = '';

	/**
	 * The encryption suite active at creation time.
	 *
	 * @var string
	 */
	protected string $encryptionSuiteId = '';

	/**
	 * The maximum number of successful accesses (1-10).
	 *
	 * @var integer
	 */
	protected int $usageLimit = 1;

	/**
	 * The number of successful accesses so far.
	 *
	 * @var integer
	 */
	protected int $usageCount = 0;

	/**
	 * The number of consecutive failed password attempts.
	 *
	 * @var integer
	 */
	protected int $failedAttempts = 0;

	/**
	 * The Nextcloud user ID of the secret owner.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When the link share was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the link share expires (nullable).
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
	 * Constructor for LinkShare.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'token', type: 'string');
		$this->addType(fieldName: 'encryptedSecretSnapshot', type: 'string');
		$this->addType(fieldName: 'argon2idSalt', type: 'string');
		$this->addType(fieldName: 'encryptionSuiteId', type: 'string');
		$this->addType(fieldName: 'usageLimit', type: 'integer');
		$this->addType(fieldName: 'usageCount', type: 'integer');
		$this->addType(fieldName: 'failedAttempts', type: 'integer');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize the entity to an array for the authenticated management API.
	 *
	 * The encrypted snapshot blob and the Argon2id salt are intentionally
	 * OMITTED — they are only ever returned by the public access endpoint
	 * (Phase 1), never by the owner's management list, to minimise exposure.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'token' => $this->token,
			'encryptionSuiteId' => $this->encryptionSuiteId,
			'usageLimit' => $this->usageLimit,
			'usageCount' => $this->usageCount,
			'remaining' => max(0, ($this->usageLimit - $this->usageCount)),
			'failedAttempts' => $this->failedAttempts,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end jsonSerialize()

	/**
	 * Serialize only the public access payload (Phase 1).
	 *
	 * Returns the encrypted blob and salt required for client-side
	 * decryption, plus usage metadata. Never exposes owner identity.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerializePublic(): array {
		return [
			'encryptedSecretSnapshot' => $this->encryptedSecretSnapshot,
			'argon2idSalt' => $this->argon2idSalt,
			'usageLimit' => $this->usageLimit,
			'usageCount' => $this->usageCount,
		];
	}//end jsonSerializePublic()
}//end class
