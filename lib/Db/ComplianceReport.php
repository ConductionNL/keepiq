<?php

/**
 * Doriath Compliance Report Entity
 *
 * One immutable compliance-posture snapshot (compliance-reporting §1.2):
 * metadata/counts only — never a secret value, name, or ciphertext.
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
 * Entity representing one compliance report snapshot.
 *
 * @method string getGeneratedBy()
 * @method void setGeneratedBy(string $generatedBy)
 * @method DateTime|null getGeneratedAt()
 * @method void setGeneratedAt(DateTime $generatedAt)
 * @method string getAppVersion()
 * @method void setAppVersion(string $appVersion)
 * @method string getConfigSnapshot()
 * @method void setConfigSnapshot(string $configSnapshot)
 * @method string getAggregate()
 * @method void setAggregate(string $aggregate)
 */
class ComplianceReport extends Entity implements JsonSerializable
{

    /**
     * The generating admin.
     *
     * @var string
     */
    protected string $generatedBy = '';

    /**
     * When the snapshot was generated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $generatedAt = null;

    /**
     * The app version at generation time.
     *
     * @var string
     */
    protected string $appVersion = '';

    /**
     * The JSON config snapshot (retention, gates, policies).
     *
     * @var string
     */
    protected string $configSnapshot = '';

    /**
     * The JSON aggregate (six sections, counts only).
     *
     * @var string
     */
    protected string $aggregate = '';

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
        $this->addType(fieldName: 'generatedBy', type: 'string');
        $this->addType(fieldName: 'generatedAt', type: 'datetime');
        $this->addType(fieldName: 'appVersion', type: 'string');
        $this->addType(fieldName: 'configSnapshot', type: 'string');
        $this->addType(fieldName: 'aggregate', type: 'string');
    }//end __construct()

    /**
     * The decoded aggregate sections.
     *
     * @return array<string,mixed>
     */
    public function getAggregateArray(): array
    {
        $decoded = json_decode($this->aggregate, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getAggregateArray()

    /**
     * The decoded config snapshot.
     *
     * @return array<string,mixed>
     */
    public function getConfigSnapshotArray(): array
    {
        $decoded = json_decode($this->configSnapshot, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getConfigSnapshotArray()

    /**
     * JSON shape with decoded sections.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'             => $this->getId(),
            'generatedBy'    => $this->generatedBy,
            'generatedAt'    => $this->generatedAt?->format('c'),
            'appVersion'     => $this->appVersion,
            'configSnapshot' => $this->getConfigSnapshotArray(),
            'aggregate'      => $this->getAggregateArray(),
        ];
    }//end jsonSerialize()
}//end class
