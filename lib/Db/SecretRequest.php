<?php

/**
 * Keepiq Secret Request Entity
 *
 * Database entity representing a request for someone to fill in a secret —
 * either a brand-new request (creates an unfilled Secret) or a re-request
 * for an existing Secret.
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
 * Entity representing a secret request.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(string $encryptionSuiteId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method string getRequestedFields()
 * @method void setRequestedFields(string $requestedFields)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method bool getIsReRequest()
 * @method void setIsReRequest(bool $isReRequest)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getFulfilledAt()
 * @method void setFulfilledAt(?DateTime $fulfilledAt)
 */
class SecretRequest extends Entity implements JsonSerializable {
	public const STATUS_PENDING = 'pending';
	public const STATUS_FULFILLED = 'fulfilled';
	public const STATUS_DECLINED = 'declined';
	public const STATUS_LOCKED = 'locked';

	/**
	 * Terminal: the request's `expires_at` passed and the sweeper acted on it.
	 *
	 * Distinct from DECLINED on purpose. Revoking means the requester changed
	 * their mind; expiring means time ran out, and the two have different
	 * consequences for someone looking at a vault that is missing a row. Without
	 * a status of its own, an automatic expiry would be indistinguishable from a
	 * cancellation after the fact.
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Prefix marking `created_by` as an application rather than a Nextcloud user.
	 *
	 * A user id can never equal this form, which is what keeps the two kinds of
	 * requester from colliding in one column — and also why no human listing could
	 * ever match an application's rows.
	 *
	 * @var string
	 */
	public const ACTOR_APPLICATION_PREFIX = 'application:';

	/**
	 * The `created_by` value identifying an application as the requester.
	 *
	 * A method rather than string concatenation at each call site: the literal was
	 * already written twice in ApplicationSecretRequestService, and this change
	 * needed a third for the admin listing. Three hand-built copies of the value a
	 * scoping query matches on is how one of them eventually differs.
	 *
	 * @param string $applicationId The application's id
	 *
	 * @return string The composed actor string
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public static function actorForApplication(string $applicationId): string {
		return self::ACTOR_APPLICATION_PREFIX . $applicationId;
	}//end actorForApplication()

	/**
	 * The unfilled (or to-be-overwritten) Secret ID.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The recipient's active EncryptionSuite ID.
	 *
	 * @var string
	 */
	protected string $encryptionSuiteId = '';

	/**
	 * The URL-safe access token.
	 *
	 * @var string
	 */
	protected string $token = '';

	/**
	 * JSON-encoded array of field names the requester wants filled in.
	 *
	 * @var string
	 */
	protected string $requestedFields = '[]';

	/**
	 * Request lifecycle: pending / fulfilled / declined / locked.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_PENDING;

	/**
	 * Whether this is a re-request (overwrite an existing Secret).
	 *
	 * @var boolean
	 */
	protected bool $isReRequest = false;

	/**
	 * Optional expiry timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * The Nextcloud user ID that created the request.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When the request was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the request was fulfilled (nullable).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $fulfilledAt = null;

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
	 * Constructor for SecretRequest.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'encryptionSuiteId', type: 'string');
		$this->addType(fieldName: 'token', type: 'string');
		$this->addType(fieldName: 'requestedFields', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'isReRequest', type: 'boolean');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'fulfilledAt', type: 'datetime');
	}//end __construct()

	/**
	 * Record an application as this request's creator.
	 *
	 * An instance method so no caller composes the actor string itself. Production
	 * code reaches the value only through this setter and
	 * `belongsToApplication()`, both of which go through
	 * `actorForApplication()` — one composition point, and no cross-class static
	 * access for phpmd to object to.
	 *
	 * @param string $applicationId The creating application
	 *
	 * @return void
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function setCreatedByApplication(string $applicationId): void {
		$this->setCreatedBy(self::actorForApplication(applicationId: $applicationId));
	}//end setCreatedByApplication()

	/**
	 * Whether this request was created by the given application.
	 *
	 * An instance method so callers compare through the entity that owns the
	 * column, rather than each composing the actor string themselves.
	 *
	 * @param string $applicationId The application to test against
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function belongsToApplication(string $applicationId): bool {
		if ($applicationId === '') {
			return false;
		}

		return $this->createdBy === self::actorForApplication(applicationId: $applicationId);
	}//end belongsToApplication()

	/**
	 * Check whether the request is currently expired.
	 *
	 * @return bool
	 */
	public function isExpired(): bool {
		if ($this->expiresAt === null) {
			return false;
		}

		return $this->expiresAt < new DateTime();
	}//end isExpired()

	/**
	 * Serialize the entity to an array for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		$requested = json_decode($this->requestedFields, true);
		if (is_array($requested) === false) {
			$requested = [];
		}

		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'encryptionSuiteId' => $this->encryptionSuiteId,
			'token' => $this->token,
			'requestedFields' => $requested,
			'status' => $this->status,
			'isReRequest' => $this->isReRequest,
			'expiresAt' => $this->expiresAt?->format('c'),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
			'fulfilledAt' => $this->fulfilledAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
