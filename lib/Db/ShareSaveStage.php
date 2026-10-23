<?php

/**
 * Keepiq ShareSaveStage
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
 * One chunk of a staged share-save: copy and partner ciphertext the client
 * sent ahead of the final call. A stage is every chunk with the same
 * `stageId`; nothing here is applied until that final call commits.
 *
 * @method string getStageId()
 * @method void setStageId(string $stageId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method DateTime|null getBaseUpdatedAt()
 * @method void setBaseUpdatedAt(DateTime $baseUpdatedAt)
 * @method DateTime|null getSourceBaseUpdatedAt()
 * @method void setSourceBaseUpdatedAt(?DateTime $sourceBaseUpdatedAt)
 * @method string getEntries()
 * @method void setEntries(string $entries)
 * @method int getCopyCount()
 * @method void setCopyCount(int $copyCount)
 * @method int getFederatedCount()
 * @method void setFederatedCount(int $federatedCount)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(DateTime $expiresAt)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the columns.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-large-share-save-is-staged-in-chunks
 */
class ShareSaveStage extends Entity {
	/**
	 * The stage this chunk belongs to.
	 *
	 * @var string
	 */
	protected string $stageId = '';

	/**
	 * The user who staged it.
	 *
	 * @var string
	 */
	protected string $userId = '';

	/**
	 * The row the share-save is for (`{id}` of the route).
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The version of that row the edit was made from.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $baseUpdatedAt = null;

	/**
	 * The source's version, on the copy-editor path.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $sourceBaseUpdatedAt = null;

	/**
	 * The chunk's lists as JSON: `{copies, skipped, federated, suspend}`.
	 *
	 * @var string
	 */
	protected string $entries = '';

	/**
	 * Entries in copies plus skipped.
	 *
	 * @var int
	 */
	protected int $copyCount = 0;

	/**
	 * Entries in federated plus suspend.
	 *
	 * @var int
	 */
	protected int $federatedCount = 0;

	/**
	 * When the stage stops being usable.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When this chunk arrived.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * The UUID primary key of the chunk.
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
	 * Constructor for ShareSaveStage.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'stageId', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'baseUpdatedAt', type: 'datetime');
		$this->addType(fieldName: 'sourceBaseUpdatedAt', type: 'datetime');
		$this->addType(fieldName: 'entries', type: 'string');
		$this->addType(fieldName: 'copyCount', type: 'integer');
		$this->addType(fieldName: 'federatedCount', type: 'integer');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()
}//end class
