<?php

/**
 * Doriath Application Entity
 *
 * Database entity representing a registered external or internal
 * application that consumes the Doriath vault.
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
 * Entity representing a registered application.
 *
 * Its cryptographic identity is held in a linked EncryptionSuite via
 * polymorphic ownership (owner_type=application, owner_id=application.id),
 * so no encrypted fields live on this entity.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getCsr()
 * @method void setCsr(?string $csr)
 * @method string|null getRegisteredBy()
 * @method void setRegisteredBy(?string $registeredBy)
 * @method string|null getApprovedBy()
 * @method void setApprovedBy(?string $approvedBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getApprovedAt()
 * @method void setApprovedAt(?DateTime $approvedAt)
 */
class Application extends Entity implements JsonSerializable
{

    /**
     * The human-readable application name.
     *
     * @var string
     */
    protected string $name = '';

    /**
     * An optional description of the application's purpose.
     *
     * @var string|null
     */
    protected ?string $description = null;

    /**
     * The application type ('internal' or 'external'); informational only.
     *
     * @var string
     */
    protected string $type = 'external';

    /**
     * The application status ('pending' or 'active').
     *
     * @var string
     */
    protected string $status = 'pending';

    /**
     * Temporary PKCS#10 CSR storage for pending applications; cleared on
     * approval. Never serialised.
     *
     * @var string|null
     */
    protected ?string $csr = null;

    /**
     * The Nextcloud user ID that registered the application, or null for
     * anonymous registration.
     *
     * @var string|null
     */
    protected ?string $registeredBy = null;

    /**
     * The admin user ID that approved the application; null while pending.
     *
     * @var string|null
     */
    protected ?string $approvedBy = null;

    /**
     * When the application was registered.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When the application was approved; null while pending.
     *
     * @var DateTime|null
     */
    protected ?DateTime $approvedAt = null;

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
     * Constructor for Application.
     *
     * @return void
     */
    public function __construct()
    {
        $this->addType(fieldName: 'id', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'csr', type: 'string');
        $this->addType(fieldName: 'registeredBy', type: 'string');
        $this->addType(fieldName: 'approvedBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'approvedAt', type: 'datetime');
    }//end __construct()

    /**
     * Serialize the entity to an array for JSON output.
     *
     * The csr field is intentionally OMITTED — it is temporary transport
     * for the public key during the pending → approved transition and is
     * never exposed to clients.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'           => $this->getId(),
            'name'         => $this->name,
            'description'  => $this->description,
            'type'         => $this->type,
            'status'       => $this->status,
            'registeredBy' => $this->registeredBy,
            'approvedBy'   => $this->approvedBy,
            'createdAt'    => $this->createdAt?->format('c'),
            'approvedAt'   => $this->approvedAt?->format('c'),
        ];
    }//end jsonSerialize()
}//end class
