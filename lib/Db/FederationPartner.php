<?php

/**
 * Keepiq Federation Partner Entity
 *
 * One partner instance an administrator approved for exchanging secrets
 * (sharing-federated-recipients D1): its base URL, the pinned SHA-256
 * fingerprint of its Keepiq root certificate, and whether secrets may go
 * out to it and come in from it.
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
 * Entity representing one federation partner.
 *
 * @method string getBaseUrl()
 * @method void setBaseUrl(string $baseUrl)
 * @method string getHost()
 * @method void setHost(string $host)
 * @method string getRootFingerprint()
 * @method void setRootFingerprint(string $rootFingerprint)
 * @method bool|null getAllowOutbound()
 * @method void setAllowOutbound(bool $allowOutbound)
 * @method bool|null getAllowInbound()
 * @method void setAllowInbound(bool $allowInbound)
 * @method string getAddedBy()
 * @method void setAddedBy(string $addedBy)
 * @method DateTime|null getAddedAt()
 * @method void setAddedAt(DateTime $addedAt)
 * @method string|null getValueFields()
 * @method void setValueFields(?string $valueFields)
 * @method string|null getPendingRootFingerprint()
 * @method void setPendingRootFingerprint(?string $pendingRootFingerprint)
 * @method DateTime|null getPendingRootVerifiedAt()
 * @method void setPendingRootVerifiedAt(?DateTime $pendingRootVerifiedAt)
 * @method DateTime|null getPendingRootWindowEndsAt()
 * @method void setPendingRootWindowEndsAt(?DateTime $pendingRootWindowEndsAt)
 * @method int|null getPendingRootReminded()
 * @method void setPendingRootReminded(?int $pendingRootReminded)
 * @method int|null getRenewalNoticeAttempts()
 * @method void setRenewalNoticeAttempts(?int $renewalNoticeAttempts)
 * @method DateTime|null getRenewalNoticeNextAt()
 * @method void setRenewalNoticeNextAt(?DateTime $renewalNoticeNextAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartner extends Entity implements JsonSerializable {

	use StringPrimaryKey;

	/**
	 * The partner's base URL, `https://host[:port][/path]`, no trailing slash.
	 *
	 * @var string
	 */
	protected string $baseUrl = '';

	/**
	 * The partner's host (with `:port` when not 443), as an OCM signer names it.
	 *
	 * @var string
	 */
	protected string $host = '';

	/**
	 * Lowercase hex SHA-256 of the partner's Keepiq root certificate (DER).
	 *
	 * @var string
	 */
	protected string $rootFingerprint = '';

	/**
	 * Whether users here may share secrets to this partner.
	 *
	 * @var boolean|null
	 */
	protected ?bool $allowOutbound = false;

	/**
	 * Whether this partner may look up users here and deliver secrets.
	 *
	 * @var boolean|null
	 */
	protected ?bool $allowInbound = false;

	/**
	 * The administrator who added the partner.
	 *
	 * @var string
	 */
	protected string $addedBy = '';

	/**
	 * When the partner was added.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $addedAt = null;

	/**
	 * The value fields the partner advertises it can store, as a JSON array
	 * of field names; null when it has not advertised any.
	 *
	 * @var string|null
	 */
	protected ?string $valueFields = null;

	/**
	 * The fingerprint of a renewed root the partner announced and that passed every check, waiting for an
	 * administrator to accept it; null when none is offered.
	 *
	 * @var string|null
	 */
	protected ?string $pendingRootFingerprint = null;

	/**
	 * When the offered renewal was verified.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $pendingRootVerifiedAt = null;

	/**
	 * When the offered renewal stops being acceptable: 30 days after the renewal time the partner publishes.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $pendingRootWindowEndsAt = null;

	/**
	 * The smallest reminder threshold (days before the window ends) already sent for the offered renewal.
	 *
	 * @var integer|null
	 */
	protected ?int $pendingRootReminded = null;

	/**
	 * Failed attempts at telling this partner that this instance renewed its root.
	 *
	 * @var integer|null
	 */
	protected ?int $renewalNoticeAttempts = null;

	/**
	 * When the renewal notice to this partner is sent next; null when none is waiting.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $renewalNoticeNextAt = null;

	/**
	 * The UUID primary key.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Constructor: declare column types for QBMapper hydration.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'baseUrl', type: 'string');
		$this->addType(fieldName: 'host', type: 'string');
		$this->addType(fieldName: 'rootFingerprint', type: 'string');
		$this->addType(fieldName: 'allowOutbound', type: 'boolean');
		$this->addType(fieldName: 'allowInbound', type: 'boolean');
		$this->addType(fieldName: 'addedBy', type: 'string');
		$this->addType(fieldName: 'addedAt', type: 'datetime');
		$this->addType(fieldName: 'valueFields', type: 'string');
		$this->addType(fieldName: 'pendingRootFingerprint', type: 'string');
		$this->addType(fieldName: 'pendingRootVerifiedAt', type: 'datetime');
		$this->addType(fieldName: 'pendingRootWindowEndsAt', type: 'datetime');
		$this->addType(fieldName: 'pendingRootReminded', type: 'integer');
		$this->addType(fieldName: 'renewalNoticeAttempts', type: 'integer');
		$this->addType(fieldName: 'renewalNoticeNextAt', type: 'datetime');
	}//end __construct()

	/**
	 * Offer a verified renewed root: replaces any offered one and starts its reminders over.
	 *
	 * @param string $fingerprint The renewed root's fingerprint
	 * @param DateTime $verifiedAt When it was verified
	 * @param DateTime $windowEndsAt When it stops being acceptable
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function offerRenewal(string $fingerprint, DateTime $verifiedAt, DateTime $windowEndsAt): void {
		$this->setPendingRootFingerprint($fingerprint);
		$this->setPendingRootVerifiedAt($verifiedAt);
		$this->setPendingRootWindowEndsAt($windowEndsAt);
		$this->setPendingRootReminded(null);
	}//end offerRenewal()

	/**
	 * Withdraw the offered renewal, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function withdrawRenewal(): void {
		$this->setPendingRootFingerprint(null);
		$this->setPendingRootVerifiedAt(null);
		$this->setPendingRootWindowEndsAt(null);
		$this->setPendingRootReminded(null);
	}//end withdrawRenewal()

	/**
	 * The offered renewal while it is still acceptable at `$now`, or null.
	 *
	 * @param DateTime $now The current time
	 *
	 * @return array{fingerprint:string,verifiedAt:string|null,windowEndsAt:string}|null
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-offered-renewal-expires-after-30-days-with-reminders
	 */
	public function offeredRenewal(DateTime $now): ?array {
		$fingerprint = $this->pendingRootFingerprint;
		$endsAt = $this->pendingRootWindowEndsAt;
		if ($fingerprint === null || $endsAt === null || $endsAt <= $now) {
			return null;
		}

		return [
			'fingerprint' => $fingerprint,
			'verifiedAt' => $this->pendingRootVerifiedAt?->format(DATE_ATOM),
			'windowEndsAt' => $endsAt->format(DATE_ATOM),
		];
	}//end offeredRenewal()

	/**
	 * Serialize for the admin API.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function jsonSerialize(): array {
		$valueFields = null;
		if ($this->valueFields !== null) {
			$valueFields = json_decode($this->valueFields, true);
		}

		return [
			'id' => $this->getId(),
			'baseUrl' => $this->baseUrl,
			'host' => $this->host,
			'rootFingerprint' => $this->rootFingerprint,
			'allowOutbound' => $this->allowOutbound === true,
			'allowInbound' => $this->allowInbound === true,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt?->format(DATE_ATOM),
			// What its last lookup answer advertised it stores; null before any.
			'valueFields' => $valueFields,
			'pendingRenewal' => $this->offeredRenewal(now: new DateTime()),
		];
	}//end jsonSerialize()
}//end class
