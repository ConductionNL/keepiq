<?php

/**
 * Keepiq RecoveryApproval
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
 * One officer's decision on one request; unique per request and officer.
 *
 * @method string getRequestId()
 * @method void setRequestId(string $requestId)
 * @method string getOfficerUid()
 * @method void setOfficerUid(string $officerUid)
 * @method string getDecision()
 * @method void setDecision(string $decision)
 * @method DateTime|null getDecidedAt()
 * @method void setDecidedAt(?DateTime $decidedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 */
class RecoveryApproval extends Entity implements JsonSerializable {

	use StringPrimaryKey;

	/**
	 * The request.
	 *
	 * @var string
	 */
	protected string $requestId = '';

	/**
	 * The officer.
	 *
	 * @var string
	 */
	protected string $officerUid = '';

	/**
	 * `approve` or `decline`.
	 *
	 * @var string
	 */
	protected string $decision = '';

	/**
	 * When.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $decidedAt = null;

	/**
	 * The UUID primary key.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Constructor for RecoveryApproval.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'requestId', type: 'string');
		$this->addType(fieldName: 'officerUid', type: 'string');
		$this->addType(fieldName: 'decision', type: 'string');
		$this->addType(fieldName: 'decidedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'requestId' => $this->requestId,
			'officerUid' => $this->officerUid,
			'decision' => $this->decision,
			'decidedAt' => $this->decidedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
