<?php

/**
 * Keepiq Certificate Metadata Entity
 *
 * Client-parsed, NON-SECRET X.509 display metadata for an encrypted
 * certificate-type secret (certificate-lifecycle §1). Populated only by
 * the owner's browser after it decrypts and parses the PEM — never
 * derived server-side (ADR-003). Holds no key material or ciphertext.
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
 * Entity representing client-parsed X.509 metadata of a stored secret.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getIssuer()
 * @method void setIssuer(?string $issuer)
 * @method string|null getSerial()
 * @method void setSerial(?string $serial)
 * @method string|null getFingerprintSha256()
 * @method void setFingerprintSha256(?string $fingerprintSha256)
 * @method DateTime|null getNotBefore()
 * @method void setNotBefore(?DateTime $notBefore)
 * @method DateTime|null getNotAfter()
 * @method void setNotAfter(?DateTime $notAfter)
 * @method DateTime|null getParsedAt()
 * @method void setParsedAt(DateTime $parsedAt)
 */
class CertificateMetadata extends Entity implements JsonSerializable {

	/**
	 * The described secret.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * Denormalized owner (NC user id) for owner-scoped queries.
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * X.509 subject DN.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * X.509 issuer DN.
	 *
	 * @var string|null
	 */
	protected ?string $issuer = null;

	/**
	 * Certificate serial.
	 *
	 * @var string|null
	 */
	protected ?string $serial = null;

	/**
	 * The `sha256:`-prefixed fingerprint.
	 *
	 * @var string|null
	 */
	protected ?string $fingerprintSha256 = null;

	/**
	 * Parsed validity start.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $notBefore = null;

	/**
	 * Parsed validity end (mirrored to the secret's expires_at).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $notAfter = null;

	/**
	 * When the client last submitted.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $parsedAt = null;

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
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'subject', type: 'string');
		$this->addType(fieldName: 'issuer', type: 'string');
		$this->addType(fieldName: 'serial', type: 'string');
		$this->addType(fieldName: 'fingerprintSha256', type: 'string');
		$this->addType(fieldName: 'notBefore', type: 'datetime');
		$this->addType(fieldName: 'notAfter', type: 'datetime');
		$this->addType(fieldName: 'parsedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for API responses — display metadata only.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'subject' => $this->subject,
			'issuer' => $this->issuer,
			'serial' => $this->serial,
			'fingerprintSha256' => $this->fingerprintSha256,
			'notBefore' => $this->notBefore?->format('c'),
			'notAfter' => $this->notAfter?->format('c'),
			'parsedAt' => $this->parsedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
