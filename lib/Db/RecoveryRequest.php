<?php

/**
 * Keepiq RecoveryRequest
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
 * A request to recover an account (D3, D4).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSuiteId()
 * @method void setSuiteId(string $suiteId)
 * @method string getEnrolmentId()
 * @method void setEnrolmentId(string $enrolmentId)
 * @method string getRequestPublicKey()
 * @method void setRequestPublicKey(string $requestPublicKey)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string|null getHandledBy()
 * @method void setHandledBy(?string $handledBy)
 * @method string|null getSealedResult()
 * @method void setSealedResult(?string $sealedResult)
 * @method DateTime|null getFulfilledAt()
 * @method void setFulfilledAt(?DateTime $fulfilledAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class RecoveryRequest extends Entity implements JsonSerializable {

	/**
	 * The user who forgot their master password.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The suite to recover.
	 *
	 * @var string
	 */
	protected string $suiteId = '';

	/**
	 * The enrolment it uses.
	 *
	 * @var string
	 */
	protected string $enrolmentId = '';

	/**
	 * The one-time X25519 public key (base64).
	 *
	 * @var string
	 */
	protected string $requestPublicKey = '';

	/**
	 * `pending`, `approved`, `fulfilled`, `declined` or `expired`.
	 *
	 * @var string
	 */
	protected string $status = '';

	/**
	 * When it was filed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When it expires.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * The officer who posted the sealed result.
	 *
	 * @var string|null
	 */
	protected ?string $handledBy = null;

	/**
	 * The private key sealed to the request key, until completion.
	 *
	 * @var string|null
	 */
	protected ?string $sealedResult = null;

	/**
	 * When the user completed it.
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
	 * Constructor for RecoveryRequest.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'suiteId', type: 'string');
		$this->addType(fieldName: 'enrolmentId', type: 'string');
		$this->addType(fieldName: 'requestPublicKey', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'handledBy', type: 'string');
		$this->addType(fieldName: 'sealedResult', type: 'string');
		$this->addType(fieldName: 'fulfilledAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->userId,
			'suiteId' => $this->suiteId,
			'enrolmentId' => $this->enrolmentId,
			'requestPublicKey' => $this->requestPublicKey,
			'status' => $this->status,
			'createdAt' => $this->createdAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'handledBy' => $this->handledBy,
			'fulfilledAt' => $this->fulfilledAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
