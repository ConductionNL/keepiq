<?php

/**
 * Doriath Application Lease Policy Entity
 *
 * Per-application override of the instance lease policy
 * (machine-secret-leases §2.4). Null fields fall through to the admin
 * defaults in SettingsService.
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

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing one application's lease-policy override.
 *
 * The primary key is `application_id` (no surrogate id column); QBMapper
 * still works through the inherited `id` when unused, so this entity
 * addresses rows via the mapper's own finders only.
 *
 * @method string getApplicationId()
 * @method void setApplicationId(string $applicationId)
 * @method int|null getDefaultTtlSeconds()
 * @method void setDefaultTtlSeconds(?int $defaultTtlSeconds)
 * @method int|null getMaxTtlSeconds()
 * @method void setMaxTtlSeconds(?int $maxTtlSeconds)
 * @method bool|null getRenewable()
 * @method void setRenewable(?bool $renewable)
 */
class ApplicationLeasePolicy extends Entity implements JsonSerializable
{

    /**
     * The application this override belongs to.
     *
     * @var string
     */
    protected string $applicationId = '';

    /**
     * Default lease TTL in seconds (null = instance default).
     *
     * @var integer|null
     */
    protected ?int $defaultTtlSeconds = null;

    /**
     * Maximum lease TTL in seconds (null = instance default).
     *
     * @var integer|null
     */
    protected ?int $maxTtlSeconds = null;

    /**
     * Whether leases are renewable (null = instance default).
     *
     * @var boolean|null
     */
    protected ?bool $renewable = null;

    /**
     * Constructor: declare column types for QBMapper hydration.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'applicationId', type: 'string');
        $this->addType(fieldName: 'defaultTtlSeconds', type: 'integer');
        $this->addType(fieldName: 'maxTtlSeconds', type: 'integer');
        $this->addType(fieldName: 'renewable', type: 'boolean');
    }//end __construct()

    /**
     * JSON shape.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'applicationId'     => $this->applicationId,
            'defaultTtlSeconds' => $this->defaultTtlSeconds,
            'maxTtlSeconds'     => $this->maxTtlSeconds,
            'renewable'         => $this->renewable,
        ];
    }//end jsonSerialize()
}//end class
