<?php

/**
 * Doriath AccountDeletionService
 *
 * Orchestrates the GDPR Art. 17 (right-to-erasure) cascade that removes all of
 * a user's Doriath data, with defined semantics for shared and delegated
 * secrets (secret-export-gdpr D4). One implementation serves both triggers:
 * the in-app re-auth flow and the automatic UserDeletedEvent listener.
 *
 * The cascade is ordered and every step is idempotent and keyed by userId, so
 * an interrupted run can be safely re-executed. The AccountDataDeletedEvent is
 * dispatched ONLY on a completed run, carrying counts and the trigger — never
 * any secret material.
 *
 * Shared-secret semantics:
 *  - Delegated secrets transfer ownership to the delegate (delegation made
 *    permanent, owner reassigned).
 *  - Recipient copies of secrets the user shared are detached: the link is
 *    severed and the copy is tombstoned with a non-personal reason. No personal
 *    data of the deleted user is ever written to a recipient's copy.
 *  - Received share copies in the deleted user's vault are hard-deleted; the
 *    original owners' secrets are untouched.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use OCA\Doriath\Db\DashboardSettingMapper;
use OCA\Doriath\Db\FolderMapper;
use OCA\Doriath\Db\LinkShareMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\SecretRequestMapper;
use OCA\Doriath\Event\AccountDataDeletedEvent;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Runs the ordered, idempotent account-deletion cascade.
 *
 * The share-shaped steps live in AccountShareCleanupService, the key-material
 * step in AccountSuiteCleanupService and the per-secret child data (attachments,
 * grants, version history) in SecretChildDataCleaner; this class owns the ORDER
 * of the cascade, the report and the completion event.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The cascade necessarily
 *   touches every entity that references a user; one orchestrator that sequences
 *   the steps is clearer than scattering the ordering itself.
 */
class AccountDeletionService {
	/**
	 * The non-personal tombstone reason written to detached recipient copies.
	 *
	 * @var string
	 */
	public const TOMBSTONE_REASON = AccountShareCleanupService::TOMBSTONE_REASON;

	/**
	 * Constructor for AccountDeletionService.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param FolderMapper $folderMapper The folder mapper
	 * @param LinkShareMapper $linkShareMapper The link-share mapper
	 * @param SecretRequestMapper $requestMapper The secret-request mapper
	 * @param DashboardSettingMapper $settingMapper The settings mapper
	 * @param IEventDispatcher $dispatcher The event dispatcher
	 * @param AccountShareCleanupService $shareCleanup The share-cascade steps
	 * @param AccountSuiteCleanupService $suiteCleanup The suite-cascade step
	 * @param SecretChildDataCleaner $childData The attachment/version cascade
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private FolderMapper $folderMapper,
		private LinkShareMapper $linkShareMapper,
		private SecretRequestMapper $requestMapper,
		private DashboardSettingMapper $settingMapper,
		private IEventDispatcher $dispatcher,
		private AccountShareCleanupService $shareCleanup,
		private AccountSuiteCleanupService $suiteCleanup,
		private SecretChildDataCleaner $childData,
	) {
	}//end __construct()

	/**
	 * Delete all Doriath data for a user, running the ordered cascade and
	 * dispatching AccountDataDeletedEvent on completion.
	 *
	 * @param string $userId The Nextcloud user ID
	 * @param string $trigger The trigger ('in-app' | 'user-deleted')
	 *
	 * @return DeletionReport The per-entity counts
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function deleteAllFor(string $userId, string $trigger = 'user-deleted'): DeletionReport {
		$report = new DeletionReport();

		// Snapshot the user's own secrets once; reused across the share steps.
		$ownedSecrets = $this->secretMapper->findByOwner(
			ownerType: 'user',
			ownerId: $userId,
			limit: 100000,
		);

		$this->shareCleanup->transferDelegatedSecrets(
			userId: $userId,
			ownedSecrets: $ownedSecrets,
			report: $report
		);
		$this->shareCleanup->detachGrantedShares(ownedSecrets: $ownedSecrets, report: $report);
		$this->shareCleanup->removeReceivedShares(userId: $userId, report: $report);

		// Link shares + secret requests via the existing per-user cascade methods.
		// deleteByUserId returns void, so count first for the report.
		$report->linkSharesDeleted = count($this->linkShareMapper->findByCreatedBy(userId: $userId));
		$this->linkShareMapper->deleteByUserId(userId: $userId);
		$report->requestsDeleted = $this->requestMapper->deleteByCreatedBy(userId: $userId);

		// Attachments cascade (encrypted-attachments §3.2): remove every
		// attachment of the user's remaining own secrets and every grant
		// any of their rows hold as a copy — idempotent, before the rows
		// themselves go.
		$this->childData->purgeForOwnerUser(userId: $userId);

		// Own secrets + folders.
		$report->secretsDeleted = $this->secretMapper->deleteByOwnerUser(ownerId: $userId);
		$report->foldersDeleted = $this->folderMapper->deleteByOwnerUser(ownerId: $userId);

		// Suites (cert + encrypted private key) and their migration records.
		$this->suiteCleanup->removeSuites(userId: $userId, report: $report);

		// Settings / preferences.
		$this->settingMapper->deleteByUser(userId: $userId);
		$report->settingsDeleted = true;

		// Emit the completion event (counts only, never secret material).
		$this->dispatcher->dispatchTyped(
			new AccountDataDeletedEvent(userId: $userId, trigger: $trigger, report: $report)
		);

		return $report;
	}//end deleteAllFor()
}//end class
