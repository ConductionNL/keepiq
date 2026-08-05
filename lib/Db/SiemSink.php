<?php

/**
 * Doriath SIEM Sink Entity
 *
 * One SIEM delivery target (siem-audit-export §1.3). The HMAC secret is
 * encrypted at rest (ICrypto) and NEVER serialized to the API.
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
 * Entity representing one SIEM sink.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string getType()
 * @method void setType(string $type)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getEndpoint()
 * @method void setEndpoint(string $endpoint)
 * @method bool getTls()
 * @method void setTls(bool $tls)
 * @method string|null getHmacSecretEnc()
 * @method void setHmacSecretEnc(?string $hmacSecretEnc)
 * @method string|null getCategoryFilter()
 * @method void setCategoryFilter(?string $categoryFilter)
 * @method int getQueueCap()
 * @method void setQueueCap(int $queueCap)
 * @method string|null getLastDeliveryStatus()
 * @method void setLastDeliveryStatus(?string $lastDeliveryStatus)
 * @method DateTime|null getLastSuccessAt()
 * @method void setLastSuccessAt(?DateTime $lastSuccessAt)
 * @method DateTime|null getLastAttemptAt()
 * @method void setLastAttemptAt(?DateTime $lastAttemptAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getConsecutiveFailures()
 * @method void setConsecutiveFailures(int $consecutiveFailures)
 * @method int getDroppedCount()
 * @method void setDroppedCount(int $droppedCount)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class SiemSink extends Entity implements JsonSerializable
{

    /**
     * Display name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * The transport: `syslog` or `webhook`.
     *
     * @var string
     */
    protected string $type = '';

    /**
     * Whether the sink receives events.
     *
     * @var boolean
     */
    protected bool $enabled = true;

    /**
     * The delivery endpoint (host:port or HTTPS URL).
     *
     * @var string
     */
    protected string $endpoint = '';

    /**
     * TLS transport toggle (syslog).
     *
     * @var boolean
     */
    protected bool $tls = true;

    /**
     * The ICrypto-encrypted webhook HMAC secret (never serialized).
     *
     * @var string|null
     */
    protected ?string $hmacSecretEnc = null;

    /**
     * JSON category allowlist (null = all categories).
     *
     * @var string|null
     */
    protected ?string $categoryFilter = null;

    /**
     * Bounded queue capacity (drop-oldest beyond).
     *
     * @var integer
     */
    protected int $queueCap = 1000;

    /**
     * Last delivery status: `ok`, `failing`, or `dead`.
     *
     * @var string|null
     */
    protected ?string $lastDeliveryStatus = null;

    /**
     * Last successful delivery instant.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastSuccessAt = null;

    /**
     * Last delivery attempt instant.
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastAttemptAt = null;

    /**
     * Last delivery error (transport-level only).
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * Consecutive failed attempts.
     *
     * @var integer
     */
    protected int $consecutiveFailures = 0;

    /**
     * Events dropped by backpressure.
     *
     * @var integer
     */
    protected int $droppedCount = 0;

    /**
     * The creating admin.
     *
     * @var string
     */
    protected string $createdBy = '';

    /**
     * Creation instant.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Last update instant.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

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
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'endpoint', type: 'string');
        $this->addType(fieldName: 'tls', type: 'boolean');
        $this->addType(fieldName: 'hmacSecretEnc', type: 'string');
        $this->addType(fieldName: 'categoryFilter', type: 'string');
        $this->addType(fieldName: 'queueCap', type: 'integer');
        $this->addType(fieldName: 'lastDeliveryStatus', type: 'string');
        $this->addType(fieldName: 'lastSuccessAt', type: 'datetime');
        $this->addType(fieldName: 'lastAttemptAt', type: 'datetime');
        $this->addType(fieldName: 'lastError', type: 'string');
        $this->addType(fieldName: 'consecutiveFailures', type: 'integer');
        $this->addType(fieldName: 'droppedCount', type: 'integer');
        $this->addType(fieldName: 'createdBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * The decoded category filter (null = all categories).
     *
     * @return string[]|null
     */
    public function categoryFilterArray(): ?array
    {
        if ($this->categoryFilter === null || $this->categoryFilter === '') {
            return null;
        }

        $decoded = json_decode($this->categoryFilter, true);
        if (is_array($decoded) === true && $decoded !== []) {
            return array_map('strval', $decoded);
        }

        return null;
    }//end categoryFilterArray()

    /**
     * API shape — the HMAC secret NEVER appears here (§1.3), only
     * whether one is set.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                  => $this->getId(),
            'name'                => $this->name,
            'type'                => $this->type,
            'enabled'             => $this->enabled,
            'endpoint'            => $this->endpoint,
            'tls'                 => $this->tls,
            'hasHmacSecret'       => ($this->hmacSecretEnc !== null && $this->hmacSecretEnc !== ''),
            'categoryFilter'      => $this->categoryFilterArray(),
            'queueCap'            => $this->queueCap,
            'lastDeliveryStatus'  => $this->lastDeliveryStatus,
            'lastSuccessAt'       => $this->lastSuccessAt?->format('c'),
            'lastAttemptAt'       => $this->lastAttemptAt?->format('c'),
            'lastError'           => $this->lastError,
            'consecutiveFailures' => $this->consecutiveFailures,
            'droppedCount'        => $this->droppedCount,
            'createdBy'           => $this->createdBy,
            'createdAt'           => $this->createdAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
