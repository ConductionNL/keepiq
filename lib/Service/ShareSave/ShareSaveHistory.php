<?php

/**
 * Keepiq ShareSaveHistory
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCP\AppFramework\Http;

/**
 * The version history and audit a share-save adds to the row save: the
 * version a restore comes from, the restore audit and the team-write audit.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
 */
class ShareSaveHistory {
	/**
	 * Constructor.
	 *
	 * @param SecretVersionService $versions The version history
	 * @param ShareAuditTrail $auditTrail The team-write audit
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretVersionService $versions,
		private ShareAuditTrail $auditTrail,
	) {
	}//end __construct()

	/**
	 * The version a restore comes from, refused as `POST /versions/{id}/restore`
	 * refuses it (400), and also when it is a version of another row.
	 *
	 * @param string $versionId The version
	 * @param Secret $row The row being saved, `{id}`
	 * @param string $userId The caller
	 *
	 * @return SecretVersion
	 *
	 * @throws ShareSaveRefusal `version_refused`
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function versionToRestore(string $versionId, Secret $row, string $userId): SecretVersion {
		try {
			$version = $this->versions->getVersion(versionId: $versionId, userId: $userId);
		} catch (InvalidArgumentException $e) {
			throw new ShareSaveRefusal(error: 'version_refused', message: $e->getMessage(), status: Http::STATUS_BAD_REQUEST);
		}

		if ($version->getSecretId() !== $row->getId()) {
			throw new ShareSaveRefusal(error: 'version_refused', message: 'Version not found', status: Http::STATUS_BAD_REQUEST);
		}

		return $version;
	}//end versionToRestore()

	/**
	 * Audit the restore; called inside the save's transaction. The log line
	 * is written by logRestore() once the save has committed.
	 *
	 * @param Secret $secret The saved row
	 * @param SecretVersion $version The version it was restored to
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function recordRestore(Secret $secret, SecretVersion $version, string $userId): void {
		$this->versions->recordRestored(secret: $secret, version: $version, userId: $userId);
	}//end recordRestore()

	/**
	 * Log the restore; called after the save's transaction committed, so a
	 * save that rolls back leaves no log line saying it was restored.
	 *
	 * @param Secret $secret The saved row
	 * @param SecretVersion $version The version it was restored to
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-restore-reaches-every-copy-in-the-same-transaction
	 */
	public function logRestore(Secret $secret, SecretVersion $version, string $userId): void {
		$this->versions->logRestored(secret: $secret, version: $version, userId: $userId);
	}//end logRestore()

	/**
	 * Attribute a copy-editor's save to the writer when they write on a
	 * team-folder grade rather than as owner or delegate.
	 *
	 * @param ShareSavePath $path The path
	 * @param string $userId The caller
	 *
	 * @return void
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-grade-changes-and-non-owner-writes-are-audited
	 */
	public function recordTeamWrite(ShareSavePath $path, string $userId): void {
		if ($path->isCopyEditor() === true && $path->teamWriter === true) {
			$this->auditTrail->recordTeamWriteSync(
				userId: $userId,
				secretId: $path->source->getId(),
				secretName: $path->source->getName(),
			);
		}
	}//end recordTeamWrite()
}//end class
