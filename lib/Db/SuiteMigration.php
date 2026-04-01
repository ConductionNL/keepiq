<?php

declare(strict_types=1);

namespace OCA\Doriath\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getOldSuiteId()
 * @method void setOldSuiteId(string $oldSuiteId)
 * @method string getNewSuiteId()
 * @method void setNewSuiteId(string $newSuiteId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime getStartedAt()
 * @method void setStartedAt(\DateTime $startedAt)
 * @method \DateTime|null getCompletedAt()
 * @method void setCompletedAt(?\DateTime $completedAt)
 */
class SuiteMigration extends Entity implements JsonSerializable
{
    protected string $oldSuiteId = '';
    protected string $newSuiteId = '';
    protected string $status = 'in_progress';
    protected ?\DateTime $startedAt = null;
    protected ?\DateTime $completedAt = null;

    public function __construct()
    {
        $this->addType('oldSuiteId', 'string');
        $this->addType('newSuiteId', 'string');
        $this->addType('status', 'string');
        $this->addType('startedAt', 'datetime');
        $this->addType('completedAt', 'datetime');
    }//end __construct()

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
