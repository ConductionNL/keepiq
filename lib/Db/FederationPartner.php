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
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */
class FederationPartner extends Entity implements JsonSerializable {
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
		$this->addType(fieldName: 'baseUrl', type: 'string');
		$this->addType(fieldName: 'host', type: 'string');
		$this->addType(fieldName: 'rootFingerprint', type: 'string');
		$this->addType(fieldName: 'allowOutbound', type: 'boolean');
		$this->addType(fieldName: 'allowInbound', type: 'boolean');
		$this->addType(fieldName: 'addedBy', type: 'string');
		$this->addType(fieldName: 'addedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the admin API.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'baseUrl' => $this->baseUrl,
			'host' => $this->host,
			'rootFingerprint' => $this->rootFingerprint,
			'allowOutbound' => $this->allowOutbound === true,
			'allowInbound' => $this->allowInbound === true,
			'addedBy' => $this->addedBy,
			'addedAt' => $this->addedAt?->format(DATE_ATOM),
		];
	}//end jsonSerialize()
}//end class
