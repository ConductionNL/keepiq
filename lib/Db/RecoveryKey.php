<?php

/**
 * Keepiq RecoveryKey
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
 * An organisation recovery key (crypto-organisation-account-recovery D2):
 * only its certificate; the private key exists only wrapped per officer.
 *
 * @method string getCertificate()
 * @method void setCertificate(string $certificate)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method int getThreshold()
 * @method void setThreshold(int $threshold)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getRetiredAt()
 * @method void setRetiredAt(?DateTime $retiredAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class RecoveryKey extends Entity implements JsonSerializable {

	/**
	 * The recovery certificate PEM, issued by the instance CA.
	 *
	 * @var string
	 */
	protected string $certificate = '';

	/**
	 * SHA-256 of the certificate (hex), for out-of-band checks.
	 *
	 * @var string
	 */
	protected string $fingerprint = '';

	/**
	 * Distinct officer approvals a recovery needs.
	 *
	 * @var int
	 */
	protected int $threshold = 0;

	/**
	 * `active`, `retiring` or `retired`.
	 *
	 * @var string
	 */
	protected string $status = '';

	/**
	 * The officer who created it.
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When it was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When it was retired.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $retiredAt = null;

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
	 * Constructor for RecoveryKey.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'certificate', type: 'string');
		$this->addType(fieldName: 'fingerprint', type: 'string');
		$this->addType(fieldName: 'threshold', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'retiredAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'certificate' => $this->certificate,
			'fingerprint' => $this->fingerprint,
			'threshold' => $this->threshold,
			'status' => $this->status,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
			'retiredAt' => $this->retiredAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
