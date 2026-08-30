<?php

/**
 * Keepiq Migration Failure entity
 *
 * One record that a compromise-recovery migration could not carry across.
 *
 * The unit here is a RECORD, not a secret. A secret head, each of its
 * versions and each of its attachment grants are independent migratable
 * records, and each can fail on its own — so each needs its own accounting
 * row. The previous single `migration_error` column on the owning secret
 * could not represent that, which is how a failed version on an
 * already-migrated secret became invisible to the completion gates.
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
 * A per-record failure recorded during a suite migration.
 *
 * @method string getMigrationId()
 * @method void setMigrationId(string $migrationId)
 * @method string getStore()
 * @method void setStore(string $store)
 * @method string getRecordId()
 * @method void setRecordId(string $recordId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string|null getMessage()
 * @method void setMessage(?string $message)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 */
class MigrationFailure extends Entity implements JsonSerializable {
	/**
	 * The migration this failure belongs to.
	 *
	 * @var string
	 */
	protected string $migrationId = '';

	/**
	 * Which store the failing record lives in.
	 *
	 * One of MigrationWorkService::STORE_SECRETS / STORE_VERSIONS /
	 * STORE_ATTACHMENT_GRANTS.
	 *
	 * @var string
	 */
	protected string $store = '';

	/**
	 * The failing record's own id.
	 *
	 * @var string
	 */
	protected string $recordId = '';

	/**
	 * The secret that owns the failing record.
	 *
	 * Carried so the acknowledgement list can name a failed version or grant
	 * rather than rendering a blank row.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * Why the record could not be migrated.
	 *
	 * @var string|null
	 */
	protected ?string $message = null;

	/**
	 * When the failure was recorded.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * Declare column types so QBMapper hydrates them correctly.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'migrationId', type: 'string');
		$this->addType(fieldName: 'store', type: 'string');
		$this->addType(fieldName: 'recordId', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'message', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for the API.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'migrationId' => $this->migrationId,
			'store' => $this->store,
			'recordId' => $this->recordId,
			'secretId' => $this->secretId,
			'message' => $this->message,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
