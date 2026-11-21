<?php

/**
 * Keepiq BrokerMint entity
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
use OCP\AppFramework\Db\Entity;

/**
 * For an application-owned secret the credential broker minted: the
 * organisation and the consuming app it was minted for. Nothing else.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string|null getOrganisationId()
 * @method void setOrganisationId(?string $organisationId)
 * @method string|null getConsumer()
 * @method void setConsumer(?string $consumer)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 *
 * @spec openspec/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
class BrokerMint extends Entity {

	use StringPrimaryKey;

	/**
	 * The minted secret.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The organisation the credential belongs to; null for none.
	 *
	 * @var string|null
	 */
	protected ?string $organisationId = null;

	/**
	 * The app the credential was minted for; null for none.
	 *
	 * @var string|null
	 */
	protected ?string $consumer = null;

	/**
	 * When the mint was recorded.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * Declare the column types.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'organisationId', type: 'string');
		$this->addType(fieldName: 'consumer', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()
}//end class
