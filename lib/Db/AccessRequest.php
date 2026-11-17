<?php

/**
 * Keepiq Access Request
 *
 * A recipient's request for a time window on a privileged login, and the owner's answer.
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
use DateTimeImmutable;
use JsonSerializable;
use OCA\Keepiq\Service\PrivilegedAccess\AccessWindow;
use OCA\Keepiq\Service\PrivilegedAccess\AddressRange;
use OCP\AppFramework\Db\Entity;

/**
 * A request for a time window on a privileged login.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string getRequesterId()
 * @method void setRequesterId(string $requesterId)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime getRequestedStart()
 * @method void setRequestedStart(DateTime $requestedStart)
 * @method DateTime getRequestedEnd()
 * @method void setRequestedEnd(DateTime $requestedEnd)
 * @method DateTime|null getApprovedStart()
 * @method void setApprovedStart(?DateTime $approvedStart)
 * @method DateTime|null getApprovedEnd()
 * @method void setApprovedEnd(?DateTime $approvedEnd)
 * @method string|null getRanges()
 * @method void setRanges(?string $ranges)
 * @method DateTime|null getDecidedAt()
 * @method void setDecidedAt(?DateTime $decidedAt)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class AccessRequest extends Entity implements JsonSerializable {

	use StringPrimaryKey;

	public const STATUS_PENDING  = 'pending';
	public const STATUS_APPROVED = 'approved';
	public const STATUS_DENIED   = 'denied';

	/**
	 * The owner's source secret.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The secret owner (NC user id), who answers the request.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * The recipient asking for access (NC user id).
	 *
	 * @var string
	 */
	protected string $requesterId = '';

	/**
	 * Why the recipient needs the login.
	 *
	 * @var string
	 */
	protected string $reason = '';

	/**
	 * pending, approved or denied.
	 *
	 * @var string
	 */
	protected string $status = self::STATUS_PENDING;

	/**
	 * The start the recipient asked for.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $requestedStart = null;

	/**
	 * The end the recipient asked for.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $requestedEnd = null;

	/**
	 * The approved start, at or after the requested one.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $approvedStart = null;

	/**
	 * The approved end, at or before the requested one.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $approvedEnd = null;

	/**
	 * The approved address ranges as a JSON list of CIDR strings; null or
	 * an empty list for any address.
	 *
	 * @var string|null
	 */
	protected ?string $ranges = null;

	/**
	 * When the owner answered.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $decidedAt = null;

	/**
	 * When the request was filed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'requesterId', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'requestedStart', type: 'datetime');
		$this->addType(fieldName: 'requestedEnd', type: 'datetime');
		$this->addType(fieldName: 'approvedStart', type: 'datetime');
		$this->addType(fieldName: 'approvedEnd', type: 'datetime');
		$this->addType(fieldName: 'ranges', type: 'string');
		$this->addType(fieldName: 'decidedAt', type: 'datetime');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * The approved address ranges as CIDR strings.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function rangeList(): array {
		$decoded = json_decode((string)$this->ranges, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}//end rangeList()

	/**
	 * The approved window, or null when the request is not approved.
	 *
	 * @return AccessWindow|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromMutable()
	 *   and AddressRange::parse() are named constructors of value objects.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
	 */
	public function approvedWindow(): ?AccessWindow {
		if ($this->status !== self::STATUS_APPROVED || $this->approvedStart === null || $this->approvedEnd === null) {
			return null;
		}

		return new AccessWindow(
			startsAt: DateTimeImmutable::createFromMutable($this->approvedStart),
			endsAt: DateTimeImmutable::createFromMutable($this->approvedEnd),
			ranges: array_map(static fn (string $cidr): AddressRange => AddressRange::parse(cidr: $cidr), $this->rangeList())
		);
	}//end approvedWindow()

	/**
	 * Serialize for the owner and the requester. Carries no secret material.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'ownerId' => $this->ownerId,
			'requesterId' => $this->requesterId,
			'reason' => $this->reason,
			'status' => $this->status,
			'requestedStart' => $this->requestedStart?->format('c'),
			'requestedEnd' => $this->requestedEnd?->format('c'),
			'approvedStart' => $this->approvedStart?->format('c'),
			'approvedEnd' => $this->approvedEnd?->format('c'),
			'ranges' => $this->rangeList(),
			'decidedAt' => $this->decidedAt?->format('c'),
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
