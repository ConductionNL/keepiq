<?php

/**
 * Doriath Rotation Flag Entity
 *
 * One-open-flag-per-secret rotation marker (rotation-expiry-policies
 * §1.1). `key_updated_at_at_flag` freezes the ciphertext age at flag
 * time so mark-rotated can prove a REAL rotation happened (the head's
 * `key_updated_at` must have advanced past it). IDs and timestamps only —
 * never a breach verdict, score, or digest.
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
 * Entity representing one rotation flag.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getReason()
 * @method void setReason(string $reason)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method DateTime|null getFlaggedAt()
 * @method void setFlaggedAt(DateTime $flaggedAt)
 * @method string|null getFlaggedBy()
 * @method void setFlaggedBy(?string $flaggedBy)
 * @method DateTime|null getResolvedAt()
 * @method void setResolvedAt(?DateTime $resolvedAt)
 * @method DateTime|null getKeyUpdatedAtAtFlag()
 * @method void setKeyUpdatedAtAtFlag(?DateTime $keyUpdatedAtAtFlag)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror DB columns.
 */
class RotationFlag extends Entity implements JsonSerializable
{

    /**
     * The flagged secret.
     *
     * @var string
     */
    protected string $secretId = '';

    /**
     * The flag reason: `user_flagged`, `policy_expiry`, or
     * `suite_compromise`. Empty default (NC Entity dirty-tracking).
     *
     * @var string
     */
    protected string $reason = '';

    /**
     * The flag status: `open`, `rotated`, or `dismissed`.
     *
     * @var string
     */
    protected string $status = '';

    /**
     * When the flag was raised.
     *
     * @var DateTime|null
     */
    protected ?DateTime $flaggedAt = null;

    /**
     * The user that raised the flag (null = system).
     *
     * @var string|null
     */
    protected ?string $flaggedBy = null;

    /**
     * When the flag was resolved.
     *
     * @var DateTime|null
     */
    protected ?DateTime $resolvedAt = null;

    /**
     * The head's key_updated_at frozen at flag time (rotation proof).
     *
     * @var DateTime|null
     */
    protected ?DateTime $keyUpdatedAtAtFlag = null;

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
     * Constructor for RotationFlag.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'secretId', type: 'string');
        $this->addType(fieldName: 'reason', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'flaggedAt', type: 'datetime');
        $this->addType(fieldName: 'flaggedBy', type: 'string');
        $this->addType(fieldName: 'resolvedAt', type: 'datetime');
        $this->addType(fieldName: 'keyUpdatedAtAtFlag', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for the API.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->getId(),
            'secretId'   => $this->secretId,
            'reason'     => $this->reason,
            'status'     => $this->status,
            'flaggedAt'  => $this->flaggedAt?->format('c'),
            'flaggedBy'  => $this->flaggedBy,
            'resolvedAt' => $this->resolvedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
