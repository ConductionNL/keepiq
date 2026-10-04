<?php

/**
 * Keepiq Secret Entity
 *
 * Database entity representing a secret. The key, login, and
 * additional_fields columns hold RSA-encrypted ciphertext blobs produced
 * in the browser — the server never decrypts them. The name, url, and
 * folder placement are stored in plaintext to enable search.
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
use InvalidArgumentException;
use JsonSerializable;
use OCA\Keepiq\Exception\ForbiddenException;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing a secret.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method string getTypeId()
 * @method void setTypeId(string $typeId)
 * @method string|null getFolderId()
 * @method void setFolderId(?string $folderId)
 * @method string getKey()
 * @method void setKey(string $key)
 * @method string|null getLogin()
 * @method void setLogin(?string $login)
 * @method string|null getAdditionalFields()
 * @method void setAdditionalFields(?string $additionalFields)
 * @method string getEncryptionSuiteId()
 * @method void setEncryptionSuiteId(string $encryptionSuiteId)
 * @method string getOwnerType()
 * @method void setOwnerType(string $ownerType)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method DateTime|null getPossiblyCompromisedAt()
 * @method void setPossiblyCompromisedAt(?DateTime $possiblyCompromisedAt)
 * @method DateTime|null getKeyUpdatedAt()
 * @method void setKeyUpdatedAt(?DateTime $keyUpdatedAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string|null getMigrationError()
 * @method void setMigrationError(?string $migrationError)
 * @method DateTime|null getTombstonedAt()
 * @method void setTombstonedAt(?DateTime $tombstonedAt)
 * @method string|null getTombstoneReason()
 * @method DateTime|null getTrashedAt()
 * @method void setTrashedAt(?DateTime $trashedAt)
 * @method DateTime|null getArchivedAt()
 * @method void setArchivedAt(?DateTime $archivedAt)
 * @method bool|null getIsFavourite()
 * @method void setIsFavourite(bool $isFavourite)
 * @method DateTime|null getLastUsedAt()
 * @method void setLastUsedAt(?DateTime $lastUsedAt)
 * @method bool|null getUseOnly()
 * @method void setUseOnly(bool $useOnly)
 * @method DateTime|null getAccessExpiresAt()
 * @method void setAccessExpiresAt(?DateTime $accessExpiresAt)
 * @method bool|null getReadOnly()
 * @method void setReadOnly(bool $readOnly)
 * @method string|null getFederatedSource()
 * @method void setFederatedSource(?string $federatedSource)
 * @method string|null getPendingAdditionalFields()
 * @method void setPendingAdditionalFields(?string $pendingAdditionalFields)
 * @method void setTombstoneReason(?string $tombstoneReason)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 *
 * @SuppressWarnings(PHPMD.LongVariable) Property names mirror the spec-mandated DB columns.
 */
class Secret extends Entity implements JsonSerializable {

	/**
	 * What every share path answers when asked to share a use-only or
	 * time-limited copy onward (sharing-use-only-and-expiring-shares D4).
	 *
	 * @var string
	 */
	public const ONWARD_SHARE_REFUSAL = 'A use-only or time-limited copy cannot be shared onward';

	/**
	 * What every write and share path answers for a copy received from
	 * another organisation (sharing-federated-recipients task 3.4).
	 *
	 * @var string
	 */
	public const READ_ONLY_REFUSAL = 'A copy from another organisation is read-only';

	/**
	 * What the holder of a read-only copy may still change: where it is filed
	 * (sharing-federated-recipients task 3.5). `baseUpdatedAt` only names the
	 * version an offline move was made from.
	 *
	 * @var string[]
	 */
	public const FILING_FIELDS = ['folderId', 'baseUpdatedAt'];

	/**
	 * The plaintext secret name.
	 *
	 * @var string
	 */
	protected string $name = '';

	/**
	 * The plaintext URL (nullable).
	 *
	 * @var string|null
	 */
	protected ?string $url = null;

	/**
	 * The SecretType ID.
	 *
	 * @var string
	 */
	protected string $typeId = '';

	/**
	 * The Folder ID (null for root-level secrets).
	 *
	 * @var string|null
	 */
	protected ?string $folderId = null;

	/**
	 * The RSA-encrypted key/password blob (base64).
	 *
	 * @var string
	 */
	protected string $key = '';

	/**
	 * The RSA-encrypted login blob (base64, nullable).
	 *
	 * @var string|null
	 */
	protected ?string $login = null;

	/**
	 * The RSA-encrypted additional_fields JSON blob (base64, nullable).
	 *
	 * @var string|null
	 */
	protected ?string $additionalFields = null;

	/**
	 * The EncryptionSuite used to encrypt this secret.
	 *
	 * @var string
	 */
	protected string $encryptionSuiteId = '';

	/**
	 * The owner type: user or application.
	 *
	 * Defaults to an empty string (not 'user') so that an explicit
	 * setOwnerType('user') call marks the column dirty in NC's QBMapper and
	 * is written on INSERT — the column is NOT NULL.
	 *
	 * @var string
	 */
	protected string $ownerType = '';

	/**
	 * The owner ID (Nextcloud user ID or application ID).
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * When the secret was flagged as possibly compromised (nullable).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $possiblyCompromisedAt = null;

	/**
	 * When the secret's encrypted `key` ciphertext last changed (nullable).
	 *
	 * Maintained server-side by SecretService whenever the stored `key` blob
	 * changes; renames / folder moves / metadata edits do NOT touch it. Records
	 * ciphertext age only — the server performs no decryption (password-health
	 * design D4).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $keyUpdatedAt = null;

	/**
	 * Optional per-secret expiry instant (rotation-expiry-policies §2.3).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * A migration error message, if re-encryption failed (nullable).
	 *
	 * @var string|null
	 */
	protected ?string $migrationError = null;

	/**
	 * When this secret was tombstoned as a detached recipient copy (nullable).
	 *
	 * Set on a recipient's share-copy when the sharer's account is deleted
	 * (secret-export-gdpr D4 step 2). Display metadata only — it imposes no
	 * access restriction; the recipient fully owns the copy.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $tombstonedAt = null;

	/**
	 * When the owner moved this secret to the trash (nullable = not trashed).
	 *
	 * A trashed secret keeps its ciphertext, attachments and versions until
	 * it is restored or purged (vault-trash-and-archive D1).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $trashedAt = null;

	/**
	 * When the owner archived this secret (nullable = not archived).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $archivedAt = null;

	/**
	 * Whether the holder of this row starred it (vault-favourites-tags-and-last-used D1).
	 *
	 * Per row, so a recipient's copy carries its own star.
	 *
	 * @var boolean|null
	 */
	protected ?bool $isFavourite = false;

	/**
	 * When the holder last opened the value or filled it from the extension
	 * (nullable = never).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastUsedAt = null;

	/**
	 * Whether this recipient copy is use-only: Keepiq's clients fill it but
	 * never show or copy its value (sharing-use-only-and-expiring-shares D1).
	 * Materialised from the grants by ShareRestrictionResolver.
	 *
	 * @var boolean|null
	 */
	protected ?bool $useOnly = false;

	/**
	 * When the holder's access to this recipient copy ends (nullable = no
	 * end). Not to be confused with expiresAt, which is credential expiry.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $accessExpiresAt = null;

	/**
	 * Whether this is a copy received from another organisation, which its
	 * holder may read but never change or pass on
	 * (sharing-federated-recipients D4, "Remote copies are read-only").
	 *
	 * @var boolean|null
	 */
	protected ?bool $readOnly = false;

	/**
	 * The cloud id of the user on a partner instance who shared this copy,
	 * or null for a secret that did not arrive by federation.
	 *
	 * @var string|null
	 */
	protected ?string $federatedSource = null;

	/**
	 * Extra-field blobs a secret request filled in that the owner has not
	 * merged yet: a JSON list of ciphertexts, each encrypted to the owner's
	 * suite (nullable = none). The filler cannot read the owner's own blob,
	 * so the owner's browser merges these into it on the next open
	 * (keepiq#750).
	 *
	 * @var string|null
	 */
	protected ?string $pendingAdditionalFields = null;

	/**
	 * The non-personal reason a copy was tombstoned (nullable).
	 *
	 * A short enum-ish token (e.g. 'owner-account-deleted'). MUST NOT contain
	 * the deleted user's ID, display name, or any other personal data.
	 *
	 * @var string|null
	 */
	protected ?string $tombstoneReason = null;

	/**
	 * When the secret was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the secret was last updated.
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
	 * Constructor for Secret.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'typeId', type: 'string');
		$this->addType(fieldName: 'folderId', type: 'string');
		$this->addType(fieldName: 'key', type: 'string');
		$this->addType(fieldName: 'login', type: 'string');
		$this->addType(fieldName: 'additionalFields', type: 'string');
		$this->addType(fieldName: 'encryptionSuiteId', type: 'string');
		$this->addType(fieldName: 'ownerType', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'possiblyCompromisedAt', type: 'datetime');
		$this->addType(fieldName: 'keyUpdatedAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'migrationError', type: 'string');
		$this->addType(fieldName: 'tombstonedAt', type: 'datetime');
		$this->addType(fieldName: 'tombstoneReason', type: 'string');
		$this->addType(fieldName: 'trashedAt', type: 'datetime');
		$this->addType(fieldName: 'archivedAt', type: 'datetime');
		$this->addType(fieldName: 'isFavourite', type: 'boolean');
		$this->addType(fieldName: 'lastUsedAt', type: 'datetime');
		$this->addType(fieldName: 'useOnly', type: 'boolean');
		$this->addType(fieldName: 'accessExpiresAt', type: 'datetime');
		$this->addType(fieldName: 'readOnly', type: 'boolean');
		$this->addType(fieldName: 'federatedSource', type: 'string');
		$this->addType(fieldName: 'pendingAdditionalFields', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'updatedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Whether this Secret holds no value in any of its value columns.
	 *
	 * Lives on the entity because it is a fact about the row, and because two
	 * services now need the same answer: a user revoking their own request and an
	 * administrator revoking an application's. A copy in each would be two
	 * predicates answering one question — which is exactly how the revoke path came
	 * to hard-delete filled secrets, when it tested `key` alone while a Secret could
	 * hold a login, a custom-field blob or a url with no key set.
	 *
	 * Deliberately wider than SecretService's snapshot test: being too eager there
	 * writes a junk version row, while being too eager in a caller of THIS destroys
	 * a credential and its history. The costs are not symmetric, so this one errs
	 * toward reporting that a value is present.
	 *
	 * @return bool True when every value column is empty
	 *
	 * @spec openspec/changes/request-first-secret-requests/specs/secrets/spec.md#requirement-unfilled-request-placeholder
	 */
	public function holdsNoValues(): bool {
		return (string)$this->key === ''
			&& (string)$this->login === ''
			&& (string)$this->additionalFields === ''
			&& (string)$this->url === '';
	}//end holdsNoValues()

	/**
	 * Whether this is a use-only or time-limited recipient copy, which no
	 * share path may use as a source (sharing-use-only-and-expiring-shares D4).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/expiring-shares/spec.md#requirement-an-expiring-copy-cannot-be-shared-onward
	 */
	public function isRestrictedCopy(): bool {
		return $this->useOnly === true || $this->accessExpiresAt !== null || $this->readOnly === true;
	}//end isRestrictedCopy()

	/**
	 * Refuse any change or onward share of a copy received from another
	 * organisation (sharing-federated-recipients task 3.4).
	 *
	 * @return void
	 *
	 * @throws ForbiddenException When it is a read-only federated copy
	 *
	 * @spec openspec/specs/federated-sharing/spec.md#requirement-remote-copies-are-read-only
	 */
	public function assertNotReadOnly(): void {
		if ($this->readOnly === true) {
			throw new ForbiddenException(message: self::READ_ONLY_REFUSAL);
		}
	}//end assertNotReadOnly()

	/**
	 * Refuse this secret as the source of a share when it is a use-only or
	 * time-limited copy (sharing-use-only-and-expiring-shares D4).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is a restricted copy
	 * @throws ForbiddenException When it is a read-only copy from another organisation
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 */
	public function assertOnwardShareable(): void {
		$this->assertNotReadOnly();
		if ($this->isRestrictedCopy() === true) {
			throw new InvalidArgumentException(self::ONWARD_SHARE_REFUSAL);
		}
	}//end assertOnwardShareable()

	/**
	 * Refuse an edit of a use-only copy by its holder
	 * (sharing-use-only-and-expiring-shares D4), and any change of a
	 * read-only copy from another organisation except filing it in a folder
	 * (sharing-federated-recipients task 3.5).
	 *
	 * @param string[]|null $fields The fields the edit changes; null for an unspecified edit
	 *
	 * @return self The same secret, for chaining after a load
	 *
	 * @throws ForbiddenException When it is a use-only copy, or a read-only copy and more than its folder changes
	 *
	 * @spec openspec/specs/use-only-shares/spec.md#requirement-the-server-refuses-what-it-can-enforce
	 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-files-his-copy-in-a-folder
	 */
	public function assertEditableByHolder(?array $fields = null): self {
		$filingOnly = $fields !== null
			&& in_array('folderId', $fields, true) === true
			&& array_diff($fields, self::FILING_FIELDS) === [];
		if ($filingOnly === false) {
			$this->assertNotReadOnly();
		}

		if ($this->useOnly === true) {
			throw new ForbiddenException(message: 'A use-only copy cannot be changed');
		}

		return $this;
	}//end assertEditableByHolder()

	/**
	 * Serialize the entity to an array for the API, including encrypted blobs.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'url' => $this->url,
			'typeId' => $this->typeId,
			'folderId' => $this->folderId,
			'key' => $this->key,
			'login' => $this->login,
			'additionalFields' => $this->additionalFields,
			'encryptionSuiteId' => $this->encryptionSuiteId,
			'ownerType' => $this->ownerType,
			'ownerId' => $this->ownerId,
			'blocked' => false,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
			'keyUpdatedAt' => $this->keyUpdatedAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'possiblyCompromisedAt' => $this->possiblyCompromisedAt?->format('c'),
			'tombstonedAt' => $this->tombstonedAt?->format('c'),
			'tombstoneReason' => $this->tombstoneReason,
			'trashedAt' => $this->trashedAt?->format('c'),
			'archivedAt' => $this->archivedAt?->format('c'),
			'favourite' => ($this->isFavourite === true),
			'lastUsedAt' => $this->lastUsedAt?->format('c'),
			'useOnly' => ($this->useOnly === true),
			'accessExpiresAt' => $this->accessExpiresAt?->format('c'),
			'readOnly' => ($this->readOnly === true),
			'federatedSource' => $this->federatedSource,
			'pendingAdditionalFields' => $this->pendingAdditionalFieldList(),
		];
	}//end jsonSerialize()

	/**
	 * The pending extra-field ciphertexts as a list (empty when none).
	 *
	 * @return list<string>
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function pendingAdditionalFieldList(): array {
		if ($this->pendingAdditionalFields === null || $this->pendingAdditionalFields === '') {
			return [];
		}

		$decoded = json_decode($this->pendingAdditionalFields, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}//end pendingAdditionalFieldList()

	/**
	 * Store the pending extra-field ciphertexts (null when the list is empty).
	 *
	 * @param list<string> $ciphertexts The pending ciphertexts, oldest first
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function setPendingAdditionalFieldList(array $ciphertexts): void {
		if ($ciphertexts === []) {
			$this->setPendingAdditionalFields(null);
			return;
		}

		$this->setPendingAdditionalFields(json_encode(array_values($ciphertexts)));
	}//end setPendingAdditionalFieldList()

	/**
	 * Drop the oldest $count pending blobs: the ones the owner's client read
	 * and merged into the blob it just wrote (keepiq#750).
	 *
	 * @param int $count How many pending blobs were merged
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-requestable-fields
	 */
	public function dropMergedPending(int $count): void {
		if ($count <= 0) {
			return;
		}

		$this->setPendingAdditionalFieldList(array_slice($this->pendingAdditionalFieldList(), $count));
	}//end dropMergedPending()

	/**
	 * Serialize only plaintext metadata, omitting encrypted blobs.
	 *
	 * Used in list responses for secrets whose encryption suite is revoked
	 * or compromised — the metadata is shown but the ciphertext withheld.
	 *
	 * @param string $blockedReason The reason the encrypted fields are withheld
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerializeBlocked(string $blockedReason): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'url' => $this->url,
			'typeId' => $this->typeId,
			'folderId' => $this->folderId,
			'encryptionSuiteId' => $this->encryptionSuiteId,
			'ownerType' => $this->ownerType,
			'ownerId' => $this->ownerId,
			'blocked' => true,
			'blockedReason' => $blockedReason,
			// Plaintext metadata, safe to expose on a blocked row and needed by
			// the list/table/card surfaces: a secret compromise recovery could
			// not carry across is precisely the one the user must be warned
			// about, and withholding these left the warning nothing to render.
			// migrationError never contains any part of the secret's value.
			'possiblyCompromisedAt' => $this->possiblyCompromisedAt?->format('c'),
			'migrationError' => $this->migrationError,
			'unrecoverable' => ($this->migrationError !== null),
			'trashedAt' => $this->trashedAt?->format('c'),
			'archivedAt' => $this->archivedAt?->format('c'),
			'favourite' => ($this->isFavourite === true),
			'lastUsedAt' => $this->lastUsedAt?->format('c'),
			'useOnly' => ($this->useOnly === true),
			'accessExpiresAt' => $this->accessExpiresAt?->format('c'),
			'readOnly' => ($this->readOnly === true),
			'federatedSource' => $this->federatedSource,
			'createdAt' => $this->createdAt?->format('c'),
			'updatedAt' => $this->updatedAt?->format('c'),
		];
	}//end jsonSerializeBlocked()
}//end class
