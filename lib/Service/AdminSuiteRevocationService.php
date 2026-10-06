<?php

/**
 * Keepiq AdminSuiteRevocationService
 *
 * An administrator's force-revoke of a suite (ADR-005): the plain revoke and
 * the compromise revoke with its containment. Moved out of
 * EncryptionSuiteController so the controller keeps only the request checks
 * and the refusal handling (keepiq#1189).
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

use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Force-revoke a suite as an administrator.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
 */
class AdminSuiteRevocationService {
	/**
	 * The `error` of a compromise revoke whose migration end failed.
	 *
	 * @var string
	 */
	public const MIGRATION_END_FAILED = 'migration_end_failed';

	/**
	 * Constructor.
	 *
	 * @param EncryptionSuiteService               $suiteService     Revokes suites and audits the containment
	 * @param MigrationService                     $migrationService Finds and ends the suite's migration
	 * @param EmergencyEnvelopeInvalidationService $emergencyService Counts the emergency contacts a revoke destroys
	 * @param CompromiseContainmentService         $containment      Collects and contains the blast radius
	 * @param AuditEntryMapper                     $auditEntries     Tells whether a containment already completed
	 * @param LoggerInterface                      $logger           The logger
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteService $suiteService,
		private MigrationService $migrationService,
		private EmergencyEnvelopeInvalidationService $emergencyService,
		private CompromiseContainmentService $containment,
		private AuditEntryMapper $auditEntries,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Force-revoke a suite that is not compromised.
	 *
	 * @param string $suiteId  The suite to revoke
	 * @param string $reason   The administrator's reason
	 * @param string $adminUid The acting administrator
	 *
	 * @return array<string,mixed> The response body
	 *
	 * @throws \OCA\Keepiq\Exception\SuiteMigrationInProgressException When the suite is in an in-progress migration
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	public function revoke(string $suiteId, string $reason, string $adminUid): array {
		// Not while the suite is part of an in-progress migration: revoking
		// either end strands it (keepiq#803). Checked before anything else.
		// A COMPROMISE force-revoke is the exception: there the migration is
		// ended instead, or whoever is being contained could block the
		// containment for good by leaving a migration open.
		$this->migrationService->assertNoMigrationInProgress(suiteId: $suiteId);

		// Read BEFORE revokeSuite(): the EncryptionSuiteRevokedEvent cascade
		// clears the grantor's emergency envelopes, so the usable count is
		// non-zero here only while the contacts still exist.
		$emergencyCount = $this->emergencyService->countUsableForGrantorSuite($suiteId);

		$suite = $this->suiteService->revokeSuite(
			id: $suiteId,
			reason: $reason,
			revokedBy: $adminUid,
			markCompromised: false,
			emergencyContactsDestroyed: $emergencyCount,
		);
		$this->containment->notifyEmergencyAccessCleared(suite: $suite, count: $emergencyCount);

		$data = $suite->jsonSerialize();
		$data['emergencyContactsDestroyed'] = $emergencyCount;
		$data['warning'] = 'The revoked user may still know these secrets; consider rotating them.';
		return $data;
	}//end revoke()

	/**
	 * Force-revoke a suite as compromised, and contain what its key reached.
	 *
	 * The blast radius is collected from BOTH ends of an open migration before
	 * either is revoked, because each revoke's cascade deletes the ShareTargets
	 * the lookup reads (keepiq#864). Containment then stamps and warns, revokes
	 * the user's link shares and passkeys (LinkShareService::deleteByUserId via
	 * MigrationService, keepiq#858) and ends their sessions (keepiq#860). Its
	 * failures are counted and returned as `cascadeIncomplete`, so the
	 * administrator is not told containment ran when part of it did not
	 * (keepiq#863), and audited as `suite.compromise_contained` (keepiq#1189).
	 *
	 * Once the named suite is revoked, a failure to revoke the other end or to
	 * end the migration does not stop its containment: it is counted as a
	 * failed step and returned as `error: migration_end_failed`, not as a
	 * refused revoke. The migration stays open, so a retry finds it. The retry
	 * only finishes the migration when the audit trail shows the containment
	 * completed; otherwise it runs everything again (keepiq#1189).
	 *
	 * @param string $suiteId  The suite to revoke
	 * @param string $reason   The administrator's reason
	 * @param string $adminUid The acting administrator
	 *
	 * @return array<string,mixed> The response body
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	public function revokeAsCompromise(string $suiteId, string $reason, string $adminUid): array {
		$migration = $this->migrationService->findInProgressForSuite(suiteId: $suiteId);
		$otherId = $this->otherEnd(migration: $migration, suiteId: $suiteId);

		// A retry after a failed migration end only finishes the migration,
		// so nobody is warned twice, but ONLY when the audit trail shows a
		// complete containment of this suite since the migration started. The
		// suite's status alone proves nothing: an attempt can stop between
		// the revoke and the containment (keepiq#1189). Without that record
		// everything runs again, the revoke included, because a revoke can
		// also stop between its status update and its cascade.
		$named = $this->alreadyContained(suiteId: $suiteId, migration: $migration);
		if ($named !== null && $migration !== null && $otherId !== null) {
			return $this->finishMigrationForCompromise(
				suite: $named,
				migration: $migration,
				otherId: $otherId,
				reason: $reason,
				adminUid: $adminUid
			);
		}

		$radius = $this->containment->collect(suiteIds: array_values(array_filter([$suiteId, $otherId])));

		// Read BEFORE revokeSuite(): the revoke cascade clears the envelopes.
		$emergencyCount = $this->emergencyService->countUsableForGrantorSuite($suiteId);
		$suite = $this->suiteService->revokeSuite(
			id: $suiteId,
			reason: $reason,
			revokedBy: $adminUid,
			markCompromised: true,
			emergencyContactsDestroyed: $emergencyCount,
		);

		$data = $suite->jsonSerialize();
		$data['emergencyContactsDestroyed'] = $emergencyCount;

		// The named suite is revoked from here on, so a failure to end the
		// migration must not stop its containment. A retry collects after the
		// revoke and would no longer find what this radius holds (keepiq#864).
		$cleared = $emergencyCount;
		$migrationEndFailed = false;
		if ($migration !== null && $otherId !== null) {
			$ended = $this->tryEndMigrationForCompromise(
				migration: $migration,
				otherId: $otherId,
				reason: $reason,
				adminUid: $adminUid
			);
			$migrationEndFailed = $ended === null;
			if ($ended !== null) {
				$cleared += $ended['alsoRevokedEmergencyContactsDestroyed'];
				$data += $ended;
			}
		}

		$this->containment->notifyEmergencyAccessCleared(suite: $suite, count: $cleared);

		$tally = $this->containment->contain(radius: $radius, suite: $suite, revokedBy: $adminUid);
		if ($migrationEndFailed === true) {
			$tally['failed']++;
		}

		$this->suiteService->recordContainment(
			suiteId: $suiteId,
			actorId: $adminUid,
			tally: $tally,
			migrationEndFailed: $migrationEndFailed
		);

		$data['cascade'] = $tally;
		$data['cascadeIncomplete'] = $tally['failed'] > 0;
		if ($migrationEndFailed === true) {
			$data += self::migrationEndFailedBody(containmentFailures: $tally['failed'] - 1);
		}

		return $data;
	}//end revokeAsCompromise()

	/**
	 * The named suite, when it is revoked mid-migration and the audit trail
	 * shows its containment completed since the migration started.
	 *
	 * Every doubt answers null, so the containment runs again: no record (the
	 * attempt stopped before it, or the audit write failed, which is
	 * fail-soft), a record with failed containment steps, or a lookup error.
	 *
	 * @param string              $suiteId   The named suite
	 * @param SuiteMigration|null $migration Its in-progress migration
	 *
	 * @return EncryptionSuite|null
	 */
	private function alreadyContained(string $suiteId, ?SuiteMigration $migration): ?EncryptionSuite {
		if ($migration === null) {
			return null;
		}

		try {
			$named = $this->suiteService->getSuite($suiteId);
			if ($named->getStatus() !== 'revoked') {
				return null;
			}

			$entries = $this->auditEntries->findFiltered(
				filters: [
					'eventType' => AuditEventTypes::SUITE_COMPROMISE_CONTAINED,
					'objectType' => 'suite',
					'objectId' => $suiteId,
					'from' => $migration->getStartedAt(),
				],
				limit: 1
			);
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: could not tell whether suite ' . $suiteId . ' was contained; containing it again: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
			return null;
		}

		if ($entries === []) {
			return null;
		}

		// `failed` also counts the failed migration end, which the retry redoes.
		$metadata = $entries[0]->getMetadataArray();
		$containmentFailures = (int)($metadata['failed'] ?? 1) - (int)(($metadata['migrationEndFailed'] ?? false) === true);
		if ($containmentFailures !== 0) {
			return null;
		}

		return $named;
	}//end alreadyContained()

	/**
	 * Finish the migration a compromise force-revoke left open.
	 *
	 * The named suite was revoked and its containment ran on the earlier
	 * attempt, so only the other end and the migration are left.
	 *
	 * @param EncryptionSuite $suite     The named suite, already revoked
	 * @param SuiteMigration  $migration The migration still in progress
	 * @param string          $otherId   Its end the administrator did not name
	 * @param string          $reason    The administrator's reason
	 * @param string          $adminUid  The acting administrator
	 *
	 * @return array<string,mixed> The response body
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
	 */
	private function finishMigrationForCompromise(
		EncryptionSuite $suite,
		SuiteMigration $migration,
		string $otherId,
		string $reason,
		string $adminUid,
	): array {
		$data = $suite->jsonSerialize();
		$data['emergencyContactsDestroyed'] = 0;
		$data['containmentAlreadyRan'] = true;

		$ended = $this->tryEndMigrationForCompromise(
			migration: $migration,
			otherId: $otherId,
			reason: $reason,
			adminUid: $adminUid
		);
		if ($ended === null) {
			// No containment ran, so no containment event: record the failed
			// attempt itself, or the trail shows nothing while the other end
			// stays live.
			$this->suiteService->recordRevokeRefused(
				suiteId: (string)$suite->getId(),
				actorId: $adminUid,
				reasonCode: self::MIGRATION_END_FAILED,
				markCompromised: true
			);
			return $data + self::migrationEndFailedBody(containmentFailures: 0);
		}

		$this->containment->notifyEmergencyAccessCleared(
			suite: $suite,
			count: $ended['alsoRevokedEmergencyContactsDestroyed']
		);

		return $data + $ended;
	}//end finishMigrationForCompromise()

	/**
	 * End the migration, or log why it could not be ended.
	 *
	 * @param SuiteMigration $migration The in-progress migration
	 * @param string         $otherId   Its end the administrator did not name
	 * @param string         $reason    The administrator's reason
	 * @param string         $adminUid  The acting administrator
	 *
	 * @return array{terminatedMigration: string, alsoRevokedSuite: string, alsoRevokedEmergencyContactsDestroyed: int}|null Null when it failed
	 */
	private function tryEndMigrationForCompromise(
		SuiteMigration $migration,
		string $otherId,
		string $reason,
		string $adminUid,
	): ?array {
		try {
			return $this->endMigrationForCompromise(
				migration: $migration,
				otherId: $otherId,
				reason: $reason,
				adminUid: $adminUid
			);
		} catch (Throwable $exception) {
			$this->logger->error(
				'Keepiq: could not end migration ' . $migration->getId() . ' of a compromise force-revoke: ' . $exception->getMessage(),
				['app' => 'keepiq', 'exception' => $exception]
			);
			return null;
		}
	}//end tryEndMigrationForCompromise()

	/**
	 * The response fields of a compromise force-revoke whose migration end failed.
	 *
	 * @param int $containmentFailures Failed containment steps, not counting the migration end
	 *
	 * @return array{error: string, message: string}
	 */
	private static function migrationEndFailedBody(int $containmentFailures): array {
		$message = 'The suite is revoked and contained, but ending its key migration failed. Force-revoke it again to finish.';
		if ($containmentFailures > 0) {
			$message = 'The suite is revoked, but part of its containment and ending its key migration failed. Force-revoke it again to retry both.';
		}

		return [
			'error' => self::MIGRATION_END_FAILED,
			'message' => $message,
		];
	}//end migrationEndFailedBody()

	/**
	 * Revoke the other end of the suite's in-progress migration, then end it.
	 *
	 * Part of a compromise force-revoke. The other end is revoked as
	 * compromised too: during a compromise either end may be the one the
	 * attacker controls (keepiq#809 review). The migration is terminated LAST,
	 * so if revoking the other end fails, a retry of the force-revoke still
	 * finds the open migration and finishes the job.
	 *
	 * @param SuiteMigration $migration The in-progress migration
	 * @param string         $otherId   Its end the administrator did not name
	 * @param string         $reason    The admin's reason, reused for the other end
	 * @param string         $adminUid  The acting administrator
	 *
	 * @return array{terminatedMigration: string, alsoRevokedSuite: string, alsoRevokedEmergencyContactsDestroyed: int}
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-suite-in-an-in-progress-migration-cannot-be-revoked
	 */
	private function endMigrationForCompromise(
		SuiteMigration $migration,
		string $otherId,
		string $reason,
		string $adminUid,
	): array {
		$otherCount = $this->emergencyService->countUsableForGrantorSuite($otherId);
		$this->suiteService->revokeSuite(
			id: $otherId,
			reason: $reason,
			revokedBy: $adminUid,
			markCompromised: true,
			emergencyContactsDestroyed: $otherCount,
		);

		$this->migrationService->terminateForCompromise(migration: $migration, actorId: $adminUid);

		return [
			'terminatedMigration' => (string)$migration->getId(),
			'alsoRevokedSuite' => $otherId,
			'alsoRevokedEmergencyContactsDestroyed' => $otherCount,
		];

	}//end endMigrationForCompromise()

	/**
	 * The end of the suite's migration the administrator did not name.
	 *
	 * @param SuiteMigration|null $migration The suite's in-progress migration
	 * @param string              $suiteId   The named suite
	 *
	 * @return string|null Null without a migration
	 */
	private function otherEnd(?SuiteMigration $migration, string $suiteId): ?string {
		if ($migration === null) {
			return null;
		}

		if ($migration->getOldSuiteId() === $suiteId) {
			return $migration->getNewSuiteId();
		}

		return $migration->getOldSuiteId();
	}//end otherEnd()
}//end class
