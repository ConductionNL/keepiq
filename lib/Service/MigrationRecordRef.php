<?php

/**
 * Keepiq Migration Record Ref
 *
 * One record a compromise-recovery migration is asked to touch: the
 * migration, the user it runs for, and the id of the secret, version or
 * attachment grant. MigrationWorkService refuses the write unless the record
 * is that user's and still bound to the migration's old suite.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * A record named in a migration request.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
final class MigrationRecordRef {

	/**
	 * Capture the record.
	 *
	 * @param SuiteMigration $migration The in-progress migration
	 * @param string         $ownerId   The user the migration runs for
	 * @param string         $recordId  The secret, version or attachment grant ID
	 *
	 * @return void
	 */
	public function __construct(
		public readonly SuiteMigration $migration,
		public readonly string $ownerId,
		public readonly string $recordId,
	) {
	}//end __construct()

	/**
	 * Load a secret, insisting it is the migration owner's and still on the
	 * old suite.
	 *
	 * The suite half of the guard is what makes "process only unmigrated rows"
	 * a server-side invariant instead of a client promise: a replayed or
	 * out-of-order request targeting an already-migrated row is refused rather
	 * than overwriting good new ciphertext with a stale re-encryption.
	 *
	 * @param SecretMapper $secrets The secret store
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function ownedSecret(SecretMapper $secrets): Secret {
		try {
			$secret = $secrets->findById($this->recordId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $this->ownerId) {
			throw new ForbiddenException(message: 'Secret does not belong to you');
		}

		if ($secret->getEncryptionSuiteId() !== $this->migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Secret is not bound to this migration\'s old suite');
		}

		return $secret;
	}//end ownedSecret()


	/**
	 * Load a version, insisting its secret is the migration owner's and the
	 * version is still on the old suite.
	 *
	 * Ownership is checked on the owning secret, while suite binding is checked
	 * on the version row: a head that has already migrated still owns versions
	 * that have not.
	 *
	 * @param SecretVersionMapper $versions The version store
	 * @param SecretMapper        $secrets  The secret store, for the owning secret
	 *
	 * @return SecretVersion
	 *
	 * @throws NotFoundException When the version or its secret does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function ownedVersion(SecretVersionMapper $versions, SecretMapper $secrets): SecretVersion {
		$version = $this->oldSuiteVersion(versions: $versions);
		try {
			$secret = $secrets->findById($version->getSecretId());
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Owning secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $this->ownerId) {
			throw new ForbiddenException(message: 'Version does not belong to you');
		}

		return $version;
	}//end ownedVersion()

	/**
	 * Load the version, insisting it is still on the migration's old suite.
	 *
	 * @param SecretVersionMapper $versions The version store
	 *
	 * @return SecretVersion
	 *
	 * @throws NotFoundException When the version does not exist
	 * @throws ForbiddenException When the version is no longer on the old suite
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function oldSuiteVersion(SecretVersionMapper $versions): SecretVersion {
		try {
			$version = $versions->findById($this->recordId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret version not found');
		}

		if ($version->getEncryptionSuiteId() !== $this->migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Version is not bound to this migration\'s old suite');
		}

		return $version;
	}//end oldSuiteVersion()


	/**
	 * Load an attachment grant, insisting it is addressed to the migration
	 * owner and still on the old suite.
	 *
	 * Scoped by recipient: the rotating user can only re-wrap the file keys
	 * wrapped to their own certificate. A grant addressed to somebody else is
	 * refused even when it hangs off a secret this user owns — re-pointing it
	 * would seal that recipient out of the attachment.
	 *
	 * @param AttachmentGrantMapper $grants The attachment grant store
	 *
	 * @return AttachmentGrant
	 *
	 * @throws NotFoundException When the grant does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function ownedGrant(AttachmentGrantMapper $grants): AttachmentGrant {
		try {
			$grant = $grants->findById($this->recordId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Attachment grant not found');
		}

		if ($grant->getRecipientType() !== 'user' || $grant->getRecipientId() !== $this->ownerId) {
			throw new ForbiddenException(message: 'Attachment grant does not belong to you');
		}

		if ($grant->getEncryptionSuiteId() !== $this->migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Attachment grant is not bound to this migration\'s old suite');
		}

		return $grant;
	}//end ownedGrant()
}//end class
