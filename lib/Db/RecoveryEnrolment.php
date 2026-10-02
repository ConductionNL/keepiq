<?php

/**
 * Keepiq RecoveryEnrolment
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
 * A user's suite private key wrapped to the recovery certificate
 * (crypto-organisation-account-recovery D1).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSuiteId()
 * @method void setSuiteId(string $suiteId)
 * @method string getRecoveryKeyId()
 * @method void setRecoveryKeyId(string $recoveryKeyId)
 * @method string getEnvelope()
 * @method void setEnvelope(string $envelope)
 * @method DateTime|null getEnrolledAt()
 * @method void setEnrolledAt(?DateTime $enrolledAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class RecoveryEnrolment extends Entity implements JsonSerializable {

	/**
	 * The enrolled user.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The suite whose private key is wrapped.
	 *
	 * @var string
	 */
	protected string $suiteId = '';

	/**
	 * The recovery key it is wrapped to.
	 *
	 * @var string
	 */
	protected string $recoveryKeyId = '';

	/**
	 * The hybrid envelope JSON.
	 *
	 * @var string
	 */
	protected string $envelope = '';

	/**
	 * When the user enrolled.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $enrolledAt = null;

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
	 * Constructor for RecoveryEnrolment.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'suiteId', type: 'string');
		$this->addType(fieldName: 'recoveryKeyId', type: 'string');
		$this->addType(fieldName: 'envelope', type: 'string');
		$this->addType(fieldName: 'enrolledAt', type: 'datetime');
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
			'recoveryKeyId' => $this->recoveryKeyId,
			'enrolledAt' => $this->enrolledAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
