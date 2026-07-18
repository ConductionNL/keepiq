<?php

/**
 * Doriath SIEM Queue Item Entity
 *
 * One queued forwarding payload (siem-audit-export §1.3) — a strict
 * subset of a sanitized audit entry, never secret material.
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
 * Entity representing one queued SIEM payload.
 *
 * @method string getSinkId()
 * @method void setSinkId(string $sinkId)
 * @method string getPayload()
 * @method void setPayload(string $payload)
 * @method DateTime|null getEnqueuedAt()
 * @method void setEnqueuedAt(DateTime $enqueuedAt)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method DateTime|null getNextAttemptAt()
 * @method void setNextAttemptAt(?DateTime $nextAttemptAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 */
class SiemQueueItem extends Entity implements JsonSerializable
{

    /**
     * The target sink.
     *
     * @var string
     */
    protected string $sinkId = '';

    /**
     * The JSON payload (sanitized-audit subset).
     *
     * @var string
     */
    protected string $payload = '';

    /**
     * Enqueue instant.
     *
     * @var DateTime|null
     */
    protected ?DateTime $enqueuedAt = null;

    /**
     * The delivery status: pending|delivering|delivered|dead.
     *
     * @var string
     */
    protected string $status = '';

    /**
     * Delivery attempts so far.
     *
     * @var integer
     */
    protected int $attempts = 0;

    /**
     * Earliest next attempt (backoff).
     *
     * @var DateTime|null
     */
    protected ?DateTime $nextAttemptAt = null;

    /**
     * Last transport error.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

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
    public function getId(): string
    {
        return (string) $this->id;
    }//end getId()

    /**
     * Set the UUID primary key.
     *
     * @param string $id The UUID
     *
     * @return void
     */
    public function setId($id): void
    {
        $this->setter(name: 'id', args: [$id]);
    }//end setId()

    /**
     * Constructor: declare column types for QBMapper hydration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'sinkId', type: 'string');
        $this->addType(fieldName: 'payload', type: 'string');
        $this->addType(fieldName: 'enqueuedAt', type: 'datetime');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'attempts', type: 'integer');
        $this->addType(fieldName: 'nextAttemptAt', type: 'datetime');
        $this->addType(fieldName: 'lastError', type: 'string');
    }//end __construct()

    /**
     * JSON shape.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'sinkId'     => $this->sinkId,
            'status'     => $this->status,
            'attempts'   => $this->attempts,
            'enqueuedAt' => $this->enqueuedAt?->format('c'),
            'lastError'  => $this->lastError,
        ];
    }//end jsonSerialize()
}//end class
