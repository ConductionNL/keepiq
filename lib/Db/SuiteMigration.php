<?php

/**
 * Doriath Suite Migration Entity
 *
 * Database entity representing a suite migration record.
 *
 * @category Db
 * @package  OCA\Doriath\Db
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

namespace OCA\Doriath\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing a suite migration with lifecycle tracking.
 *
 * @method string getOldSuiteId()
 * @method void setOldSuiteId(string $oldSuiteId)
 * @method string getNewSuiteId()
 * @method void setNewSuiteId(string $newSuiteId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime getStartedAt()
 * @method void setStartedAt(DateTime $startedAt)
 * @method DateTime|null getCompletedAt()
 * @method void setCompletedAt(?DateTime $completedAt)
 */
class SuiteMigration extends Entity implements JsonSerializable
{

    /**
     * The old suite ID.
     *
     * @var string
     */
    protected string $oldSuiteId = '';

    /**
     * The new suite ID.
     *
     * @var string
     */
    protected string $newSuiteId = '';

    /**
     * The migration status.
     *
     * @var string
     */
    protected string $status = 'in_progress';

    /**
     * When the migration started.
     *
     * @var DateTime|null
     */
    protected ?DateTime $startedAt = null;

    /**
     * When the migration completed.
     *
     * @var DateTime|null
     */
    protected ?DateTime $completedAt = null;

    /**
     * Constructor for SuiteMigration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'oldSuiteId', type: 'string');
        $this->addType(fieldName: 'newSuiteId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'startedAt', type: 'datetime');
        $this->addType(fieldName: 'completedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->getId(),
            'oldSuiteId'  => $this->oldSuiteId,
            'newSuiteId'  => $this->newSuiteId,
            'status'      => $this->status,
            'startedAt'   => $this->startedAt?->format('c'),
            'completedAt' => $this->completedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
