<?php

/**
 * Keepiq Share Quarantine Scanner
 *
 * Finds share rows planted through the retired client-supplied-id share
 * endpoints (GHSA-m5p9-6pw7-pf3g) and quarantines them: sets the reversible
 * `quarantined_at` marker, never deletes a row or a secret. The mappers hide a
 * quarantined row from every runtime path; `occ keepiq:share-quarantine`
 * lists, releases or drops it.
 *
 * A row is quarantined on any of three signals, each with its own reason:
 *
 * - its copy was already shared, with anyone, before the row existed. That is
 *   the advisory's precondition: the attacker learned the copy's id because
 *   it was shared with them or an accomplice. A server-minted copy is created
 *   with its row, so it cannot have been a share source before it. Checked
 *   against every share row and the `share.granted` entries in the audit log
 *   that name the copy as their object.
 * - its copy has a `secret.created` audit entry: it was created as a secret of
 *   its own (SecretService, a team-folder contribution), never minted for a
 *   share, as RecipientSecretCopyFactory and RecipientSecretCopyService record
 *   no such entry. This holds however soon after the copy the row was planted.
 * - its copy predates the row by more than MINT_WINDOW_SECONDS. Every copy the
 *   server mints is created in the same request as its row, and no
 *   first-party client ever linked a copy in a second request. The window
 *   only absorbs a slow request or another API client of the legacy two-step
 *   flow.
 *
 * Each quarantine is audited as `share.quarantined` and forwarded to the SIEM
 * sinks, with the use-only flag and access end date the copy carries. A row
 * kept with a gap above REVIEW_AFTER_SECONDS is logged for the admin.
 *
 * The scan pages through the candidates (rows whose copy is older than the
 * row or has a `secret.created` entry) by row id and skips quarantined rows,
 * so a pass that stops part-way resumes on the next run. A row an
 * administrator released keeps its reason and is skipped too. Only a pass
 * that ends sets the completion flag; until then ShareQuarantineScanJob runs
 * it again. A restore reopens the scan (reopen()).
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

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Quarantines planted share rows, resumably.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScanner {

	/**
	 * The app config key that records when a full pass completed.
	 *
	 * @var string
	 */
	public const COMPLETED_KEY = 'share_quarantine_scan_completed_at';

	/**
	 * How far a copy's `created_at` may precede its row's and still count as
	 * minted with it.
	 *
	 * @var int
	 */
	public const MINT_WINDOW_SECONDS = 60;

	/**
	 * A kept row whose copy precedes it by more than this is logged for review:
	 * a same-request mint takes well under a second.
	 *
	 * @var int
	 */
	public const REVIEW_AFTER_SECONDS = 5;

	/**
	 * Candidate rows read per query.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 500;

	/**
	 * The reason recorded when the copy was already a share source before the
	 * row existed.
	 *
	 * @var string
	 */
	public const REASON_COPY_SHARED_BEFORE_ROW = 'GHSA-m5p9-6pw7-pf3g: copy was already shared before its share row';

	/**
	 * The reason recorded when the copy was created as a secret of its own.
	 *
	 * @var string
	 */
	public const REASON_COPY_CREATED_AS_SECRET = 'GHSA-m5p9-6pw7-pf3g: copy was created as a secret of its own, not minted for its share row';

	/**
	 * The reason recorded when the copy predates the row by more than the mint
	 * window.
	 *
	 * @var string
	 */
	public const REASON_COPY_PREDATES_ROW = 'GHSA-m5p9-6pw7-pf3g: copy predates its share row by more than the mint window';

	/**
	 * The share targets table.
	 *
	 * @var string
	 */
	private const TABLE = 'keepiq_share_targets';

	/**
	 * The secrets table.
	 *
	 * @var string
	 */
	private const SECRETS_TABLE = 'keepiq_secrets';

	/**
	 * The audit log table.
	 *
	 * @var string
	 */
	private const AUDIT_TABLE = 'keepiq_audit_log';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection
	 * @param IAppConfig $appConfig Records a completed pass
	 * @param LoggerInterface $logger Durable record of quarantined and near-boundary rows
	 * @param AuditService $auditService Records each quarantine in the audit log
	 * @param SiemService $siem Forwards each quarantine to the SIEM sinks
	 * @param int $pageSize Candidate rows read per query
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private AuditService $auditService,
		private SiemService $siem,
		private int $pageSize = self::PAGE_SIZE,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Whether a full pass has completed.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function isComplete(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::COMPLETED_KEY, '') !== '';
	}//end isComplete()

	/**
	 * Set the scan pending again, so the background job runs a new pass.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function reopen(): void {
		$this->appConfig->deleteKey(Application::APP_ID, self::COMPLETED_KEY);
	}//end reopen()

	/**
	 * Run one full pass and record it as completed. A failure anywhere in that
	 * is logged at error level and leaves the completion flag unset, so the
	 * background job runs the pass again; it never reaches the caller, so
	 * `occ upgrade` or a restore cannot fail on it.
	 *
	 * @param IOutput|null $output The migration output, when run from one
	 *
	 * @return bool Whether the pass completed
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function run(?IOutput $output = null): bool {
		try {
			$quarantined = $this->scan(output: $output);
			$this->logger->info(
				'GHSA-m5p9-6pw7-pf3g: the share quarantine scan completed and quarantined '.$quarantined.' row(s)',
				['app' => 'keepiq']
			);
			$this->appConfig->setValueString(Application::APP_ID, self::COMPLETED_KEY, (new DateTime())->format(DATE_ATOM));
		} catch (Throwable $exception) {
			$message = 'GHSA-m5p9-6pw7-pf3g: the share quarantine scan failed and will run again from a background job: '
				.$exception->getMessage();
			$output?->warning($message);
			$this->logger->error($message, ['app' => 'keepiq', 'exception' => $exception]);
			return false;
		}

		return true;
	}//end run()

	/**
	 * Judge every candidate row, a page at a time.
	 *
	 * @param IOutput|null $output The migration output
	 *
	 * @return int The number of rows quarantined
	 */
	private function scan(?IOutput $output): int {
		$quarantined = 0;
		$after = '';
		do {
			$page = $this->candidatePage(after: $after);
			foreach ($page as $row) {
				$after = $row['id'];
				if ($this->judge(row: $row, output: $output) === true) {
					++$quarantined;
				}
			}

			$full = (count($page) === $this->pageSize);
		} while ($full === true);

		return $quarantined;
	}//end scan()

	/**
	 * One page of live rows whose copy was created before the row or has a
	 * `secret.created` entry, after the given row id. Every server-minted copy
	 * shares its row's second and has no such entry, so this leaves out nearly
	 * every legitimate row in SQL.
	 *
	 * @param string $after The last row id of the previous page ('' for the first)
	 *
	 * @return list<array<string,string>> Rows keyed id, sourceSecretId, targetUserId, secretId, createdBy,
	 *                                    rowCreatedAt, copyCreatedAt, createdAsSecret ('1' or '0'),
	 *                                    copyUseOnly ('1' or '0') and copyAccessEndsAt ('' when none)
	 */
	private function candidatePage(string $after): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('st.id', 'st.source_secret_id', 'st.target_user_id', 'st.secret_id', 'st.created_by')
			->selectAlias('st.created_at', 'row_created_at')
			->selectAlias('s.created_at', 'copy_created_at')
			->selectAlias('s.use_only', 'copy_use_only')
			->selectAlias('s.access_expires_at', 'copy_access_expires_at')
			->selectAlias('c.id', 'created_entry_id')
			->from(self::TABLE, 'st')
			->innerJoin('st', self::SECRETS_TABLE, 's', $qb->expr()->eq('s.id', 'st.secret_id'))
			// The copy's `secret.created` entry, which no server-minted copy has.
			->leftJoin(
				'st',
				self::AUDIT_TABLE,
				'c',
				$qb->expr()->andX(
					$qb->expr()->eq('c.object_type', $qb->createNamedParameter('secret')),
					$qb->expr()->eq('c.object_id', 'st.secret_id'),
					$qb->expr()->eq('c.event_type', $qb->createNamedParameter(AuditEventTypes::SECRET_CREATED))
				)
			)
			->where($qb->expr()->isNull('st.quarantined_at'))
			->andWhere($qb->expr()->isNull('st.quarantine_reason'))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->lt('s.created_at', 'st.created_at'),
					$qb->expr()->isNotNull('c.id')
				)
			)
			->andWhere($qb->expr()->gt('st.id', $qb->createNamedParameter($after)))
			->orderBy('st.id', 'ASC')
			->setMaxResults($this->pageSize);

		$rows   = [];
		$result = $qb->executeQuery();
		while (($row = $result->fetch()) !== false) {
			$accessEndsAt = $this->toDateTime(value: (string)$row['copy_access_expires_at']);
			$rows[]       = [
				'id' => (string)$row['id'],
				'sourceSecretId' => (string)$row['source_secret_id'],
				'targetUserId' => (string)$row['target_user_id'],
				'secretId' => (string)$row['secret_id'],
				'createdBy' => (string)$row['created_by'],
				'rowCreatedAt' => (string)$row['row_created_at'],
				'copyCreatedAt' => (string)$row['copy_created_at'],
				'createdAsSecret' => (string)(int)($row['created_entry_id'] !== null),
				'copyUseOnly' => (string)(int)(bool)$row['copy_use_only'],
				'copyAccessEndsAt' => ($accessEndsAt?->format(DATE_ATOM) ?? ''),
			];
		}

		$result->closeCursor();

		return $rows;
	}//end candidatePage()

	/**
	 * Quarantine one candidate row when a signal holds, or log it for review
	 * when it is kept with a gap above REVIEW_AFTER_SECONDS.
	 *
	 * @param array<string,string> $row    The candidate, as candidatePage() reads it
	 * @param IOutput|null         $output The migration output
	 *
	 * @return bool Whether the row was quarantined
	 */
	private function judge(array $row, ?IOutput $output): bool {
		$rowCreatedAt  = $this->toDateTime(value: $row['rowCreatedAt']);
		$copyCreatedAt = $this->toDateTime(value: $row['copyCreatedAt']);
		if ($rowCreatedAt === null || $copyCreatedAt === null) {
			return false;
		}

		$gap = ($rowCreatedAt->getTimestamp() - $copyCreatedAt->getTimestamp());

		$reason = null;
		if ($this->sharedBefore(copyId: $row['secretId'], before: $rowCreatedAt) === true) {
			$reason = self::REASON_COPY_SHARED_BEFORE_ROW;
		} elseif ($row['createdAsSecret'] === '1') {
			$reason = self::REASON_COPY_CREATED_AS_SECRET;
		} elseif ($gap > self::MINT_WINDOW_SECONDS) {
			$reason = self::REASON_COPY_PREDATES_ROW;
		}

		if ($reason === null) {
			if ($gap > self::REVIEW_AFTER_SECONDS) {
				$this->report(
					output: $output,
					message: 'GHSA-m5p9-6pw7-pf3g: kept keepiq_share_targets row '.$row['id'].' for review: its copy predates it by '.$gap
						.'s, inside the '.self::MINT_WINDOW_SECONDS.'s mint window',
					row: $row,
				);
			}

			return false;
		}

		if ($this->quarantine(id: $row['id'], reason: $reason) === false) {
			return false;
		}

		$this->audit(row: $row, reason: $reason);
		$this->report(
			output: $output,
			message: 'quarantined keepiq_share_targets row '.$row['id'].' ('.$reason.'; the copy predates it by '.$gap
				.'s); its secret was left untouched',
			row: $row,
		);

		return true;
	}//end judge()

	/**
	 * Whether the copy was shared, with anyone, no later than the given
	 * moment: as the source of a share row (quarantined or not), or as the
	 * object of a `share.granted` audit entry. A copy minted with its row was
	 * never a share source before it, and sharing it onward afterwards comes
	 * after the row.
	 *
	 * @param string   $copyId The copy's secret id
	 * @param DateTime $before The row's creation
	 *
	 * @return bool
	 */
	private function sharedBefore(string $copyId, DateTime $before): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('source_secret_id', $qb->createNamedParameter($copyId)))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->setMaxResults(1);
		if ($this->exists(qb: $qb) === true) {
			return true;
		}

		// Only register-batch entries name the shared secret as their object,
		// so a revoked share is found when it was made through register-batch.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::AUDIT_TABLE)
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('secret')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter($copyId)))
			->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter(AuditEventTypes::SHARE_GRANTED)))
			->andWhere($qb->expr()->lte('occurred_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->setMaxResults(1);

		return $this->exists(qb: $qb);
	}//end sharedBefore()

	/**
	 * Whether a query returns a row.
	 *
	 * @param IQueryBuilder $qb The query
	 *
	 * @return bool
	 */
	private function exists(IQueryBuilder $qb): bool {
		$result = $qb->executeQuery();
		$found  = $result->fetch();
		$result->closeCursor();

		return $found !== false;
	}//end exists()

	/**
	 * Mark one row quarantined, unless it already is.
	 *
	 * @param string $id     The share-target row id
	 * @param string $reason The signal that caught it
	 *
	 * @return bool Whether this call quarantined it
	 */
	private function quarantine(string $id, string $reason): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('quarantined_at', $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('quarantine_reason', $qb->createNamedParameter($reason))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNull('quarantined_at'));

		return $qb->executeStatement() > 0;
	}//end quarantine()

	/**
	 * Record a quarantine in the audit log, so the row's plant stays on record
	 * after a later drop or cascade removes it, and forward it to the SIEM
	 * sinks. Both are written directly rather than through a dispatched event,
	 * so neither depends on the app's listeners being registered when the scan
	 * runs. Fail-soft: the row stays quarantined and the log keeps the error.
	 *
	 * @param array<string,string> $row    The row, as candidatePage() reads it
	 * @param string               $reason The signal that caught it
	 *
	 * @return void
	 */
	private function audit(array $row, string $reason): void {
		try {
			$event = $this->auditEvents->forSystem(
				eventType: AuditEventTypes::SHARE_QUARANTINED,
				objectType: 'share',
				objectId: $row['id'],
				metadata: [
					'sourceSecretId' => $row['sourceSecretId'],
					'recipientId' => $row['targetUserId'],
					'copyId' => $row['secretId'],
					'reason' => $reason,
					'copyUseOnly' => ($row['copyUseOnly'] === '1'),
					'copyAccessEndsAt' => $this->toDateTime(value: $row['copyAccessEndsAt'])?->format(DATE_ATOM),
				],
			);
			$this->auditService->record($event);
			$payload = $this->siem->buildPayload(event: $event);
			if ($payload !== null) {
				$this->siem->enqueue(payload: $payload);
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				'GHSA-m5p9-6pw7-pf3g: the audit entry for quarantined keepiq_share_targets row '.$row['id']
					.' could not be recorded or forwarded: '.$exception->getMessage(),
				['app' => 'keepiq', 'exception' => $exception]
			);
		}
	}//end audit()

	/**
	 * Write one finding to the migration output and the log.
	 *
	 * @param IOutput|null         $output  The migration output
	 * @param string               $message What happened to the row
	 * @param array<string,string> $row     The row, as candidatePage() reads it
	 *
	 * @return void
	 */
	private function report(?IOutput $output, string $message, array $row): void {
		$message = $message.' (source '.$row['sourceSecretId'].', target '.$row['targetUserId']
			.', secret_id '.$row['secretId'].', created by '.$row['createdBy'].')'.$this->restrictionNote(row: $row);
		$output?->warning($message);
		$this->logger->warning(
			$message,
			[
				'app' => 'keepiq',
				'id' => $row['id'],
				'source' => $row['sourceSecretId'],
				'target' => $row['targetUserId'],
				'secretId' => $row['secretId'],
				'createdBy' => $row['createdBy'],
				'rowCreatedAt' => $row['rowCreatedAt'],
				'copyCreatedAt' => $row['copyCreatedAt'],
				'copyUseOnly' => ($row['copyUseOnly'] === '1'),
				'copyAccessEndsAt' => $row['copyAccessEndsAt'],
			]
		);
	}//end report()

	/**
	 * The restrictions the copy carries, for a report: a planted row's
	 * restriction may have been written onto the target's own secret.
	 *
	 * @param array<string,string> $row The row, as candidatePage() reads it
	 *
	 * @return string '' when the copy carries none
	 */
	private function restrictionNote(array $row): string {
		$notes = [];
		if ($row['copyUseOnly'] === '1') {
			$notes[] = 'is use-only';
		}

		if ($row['copyAccessEndsAt'] !== '') {
			$notes[] = 'has its access end at '.$row['copyAccessEndsAt'];
		}

		if ($notes === []) {
			return '';
		}

		return '; the copy '.implode(' and ', $notes);
	}//end restrictionNote()

	/**
	 * Parse a stored datetime, or null when it cannot be read (so an unreadable
	 * value never triggers a quarantine).
	 *
	 * @param string $value The stored datetime
	 *
	 * @return DateTime|null
	 */
	private function toDateTime(string $value): ?DateTime {
		if ($value === '' || strtotime($value) === false) {
			return null;
		}

		return new DateTime($value);
	}//end toDateTime()
}//end class
