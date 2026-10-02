<?php

/**
 * Keepiq RecoveryOfficer
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
 * One officer's copy of a recovery private key, wrapped to that officer's
 * suite certificate with the hybrid envelope. Returned only to that officer.
 *
 * @method string getRecoveryKeyId()
 * @method void setRecoveryKeyId(string $recoveryKeyId)
 * @method string getOfficerUid()
 * @method void setOfficerUid(string $officerUid)
 * @method string getOfficerSuiteId()
 * @method void setOfficerSuiteId(string $officerSuiteId)
 * @method string getWrappedPrivateKey()
 * @method void setWrappedPrivateKey(string $wrappedPrivateKey)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method DateTime|null getAddedAt()
 * @method void setAddedAt(?DateTime $addedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class RecoveryOfficer extends Entity implements JsonSerializable {

	/**
	 * The recovery key.
	 *
	 * @var string
	 */
	protected string $recoveryKeyId = '';

	/**
	 * The officer.
	 *
	 * @var string
	 */
	protected string $officerUid = '';

	/**
	 * The officer suite the copy is wrapped to.
	 *
	 * @var string
	 */
	protected string $officerSuiteId = '';

	/**
	 * The wrapped recovery private key (envelope JSON).
	 *
	 * @var string
	 */
	protected string $wrappedPrivateKey = '';

	/**
	 * Who stored the copy.
	 *
	 * @var string
	 */
	protected string $addedBy = '';

	/**
	 * When it was stored.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $addedAt = null;

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
	 * Constructor for RecoveryOfficer.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'recoveryKeyId', type: 'string');
		$this->addType(fieldName: 'officerUid', type: 'string');
		$this->addType(fieldName: 'officerSuiteId', type: 'string');
		$this->addType(fieldName: 'wrappedPrivateKey', type: 'string');
		$this->addType(fieldName: 'addedBy', type: 'string');
		$this->addType(fieldName: 'addedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'recoveryKeyId' => $this->recoveryKeyId,
			'officerUid' => $this->officerUid,
			'officerSuiteId' => $this->officerSuiteId,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
