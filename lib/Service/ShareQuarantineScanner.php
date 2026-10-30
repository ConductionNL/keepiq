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
 *   first-party client ever linked a copy in a second request, so a row this
 *   catches by mistake came from another API client of the legacy two-step
 *   flow; an administrator releases it.
 *
 * Each quarantine is audited as `share.quarantined` and forwarded to the SIEM
 * sinks, with the use-only flag and access end date the copy carries.
 *
 * The scan pages through the candidates (rows whose copy is older than the
 * row or has a `secret.created` entry) by row id and skips quarantined rows,
 * so a pass that stops part-way resumes on the next run. A row an
 * administrator released keeps its reason and is skipped too. Only a pass
 * that ends records completion, for the generation it started in; until then
 * ShareQuarantineScanJob runs it again, and every path that deletes or
 * changes a share row's copy has heldBack() judge that row on the spot. A
 * restriction resolve that skips a row it could not judge is recorded
 * (holdBackResolve()), and the job resolves every copy again once the scan
 * completes. A restore reopens the scan with a new generation (reopen()), so
 * a pass that started before it does not count.
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Quarantines planted share rows, resumably.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The pass and the on-the-spot judging of one row share the candidate
 *   query, the signals and the audit, and the scan's state lives in app config. The gate helpers are thin wrappers over
 *   heldBack() that could move to a class of their own, but the scan and heldBack() alone stay over the limit.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The gate helpers and the scan share heldBack() and its signals;
 *   moving the helpers out still leaves the class at the limit, unless the record of a held-back resolve goes with
 *   them, and that record is scan state, read with the completion it waits for.
 */
class ShareQuarantineScanner {

	/**
	 * The app config key that records the generation a full pass completed in.
	 *
	 * @var string
	 */
	public const COMPLETED_KEY = 'share_quarantine_scan_completed';

	/**
	 * The app config key set when a restriction resolve skipped a row the
	 * pending scan could not judge, so ShareQuarantineScanJob resolves every
	 * copy again once the scan completes.
	 *
	 * @var string
	 */
	public const RESOLVE_PENDING_KEY = 'share_quarantine_scan_resolve_pending';

	/**
	 * The app config key holding the current scan generation, which reopen()
	 * replaces.
	 *
	 * @var string
	 */
	public const GENERATION_KEY = 'share_quarantine_scan_generation';

	/**
	 * The generation before any reopen().
	 *
	 * @var string
	 */
	public const INITIAL_GENERATION = 'initial';

	/**
	 * How far a copy's `created_at` may precede its row's and still count as
	 * minted with it: a same-request mint takes well under a second, so this
	 * absorbs a second boundary and a slow request.
	 *
	 * @var int
	 */
	public const MINT_WINDOW_SECONDS = 5;

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
	 * heldBack(): a signal caught the row, which is now quarantined.
	 *
	 * @var string
	 */
	public const HELD_QUARANTINED = 'quarantined';

	/**
	 * heldBack(): the row could not be judged, as the scan itself fails.
	 *
	 * @var string
	 */
	public const HELD_UNJUDGED = 'unjudged';

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
	 * @param LoggerInterface $logger Durable record of quarantined rows
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
	 * Whether a full pass has completed in the current generation.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function isComplete(): bool {
		return $this->appConfig->getValueString(Application::APP_ID, self::COMPLETED_KEY, '') === $this->generation();
	}//end isComplete()

	/**
	 * Set the scan pending again in a new generation, so the background job
	 * runs a new pass and a pass already running cannot record completion.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vault-backups/spec.md#requirement-archives-are-verified-and-restored-from-the-command-line
	 */
	public function reopen(): void {
		$this->appConfig->setValueString(Application::APP_ID, self::GENERATION_KEY, Uuid::uuid4()->toString());
		$this->appConfig->deleteKey(Application::APP_ID, self::COMPLETED_KEY);
	}//end reopen()

	/**
	 * Whether a path that cannot refuse its caller (a sync, the account
	 * deletion tombstone) may change a share row's copy: the row's target
	 * holds it, and the pending scan does not hold the row back (heldBack()).
	 *
	 * @param ShareTarget $row  The share row
	 * @param Secret      $copy The copy its `secret_id` names
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function mayTouchCopy(ShareTarget $row, Secret $copy): bool {
		return $row->isHeldByTarget(copy: $copy) === true && $this->heldBack(row: $row) === null;
	}//end mayTouchCopy()

	/**
	 * Why the pending scan holds a row back, or null when it does not. Until a
	 * pass completed, a row quarantined since the caller loaded it is held
	 * back (HELD_QUARANTINED), and a candidate row is judged here as the scan
	 * would judge it: one a signal catches is quarantined and audited
	 * (HELD_QUARANTINED), any other goes through. When judging fails, the row
	 * is held back unjudged (HELD_UNJUDGED).
	 *
	 * Judging runs in a transaction of its own, a savepoint inside the
	 * caller's, so a failed query does not abort the caller's transaction and
	 * a quarantine commits with its audit entry.
	 *
	 * @param ShareTarget $row The share row
	 *
	 * @return string|null HELD_QUARANTINED, HELD_UNJUDGED or null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function heldBack(ShareTarget $row): ?string {
		if ($this->isComplete() === true) {
			return null;
		}

		$id = (string)$row->getId();
		$this->db->beginTransaction();
		try {
			$held = $this->judgeOnTheSpot(id: $id);
			$this->db->commit();
			return $held;
		} catch (Throwable $exception) {
			$this->db->rollBack();
			$this->logger->error(
				'GHSA-m5p9-6pw7-pf3g: keepiq_share_targets row '.$id.' could not be judged while the share quarantine scan is pending, so its copy is held back: '
					.$exception->getMessage(),
				['app' => 'keepiq', 'exception' => $exception]
			);
			return self::HELD_UNJUDGED;
		}
	}//end heldBack()

	/**
	 * Whether the pending scan holds a row back as quarantined (heldBack()).
	 * A row it cannot judge refuses the caller's request instead, so no copy
	 * is touched or left behind until the scan has judged it.
	 *
	 * @param ShareTarget $row     The share row
	 * @param string      $refusal What waits for the scan, without the advice to the user
	 *
	 * @return bool
	 *
	 * @throws ShareScanPendingException When the scan cannot judge the row
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function refuseUnjudged(ShareTarget $row, string $refusal): bool {
		$held = $this->heldBack(row: $row);
		if ($held === self::HELD_UNJUDGED) {
			throw new ShareScanPendingException(message: $refusal.'; ask an administrator to check that background jobs run');
		}

		return $held === self::HELD_QUARANTINED;
	}//end refuseUnjudged()

	/**
	 * Refuse an owner's revoke or change of one share row the pending scan
	 * holds back: one it quarantines is refused as any quarantined row is,
	 * and one it cannot judge waits for the scan.
	 *
	 * @param ShareTarget $row  The share row, already authorized
	 * @param string      $verb What the request would do to the share ('revoked', 'changed')
	 *
	 * @return void
	 *
	 * @throws ConflictException When the scan quarantines the row
	 * @throws ShareScanPendingException When the scan cannot judge the row
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function assertNotHeldBack(ShareTarget $row, string $verb): void {
		if ($this->refuseUnjudged(row: $row, refusal: 'This share waits for the share quarantine scan and cannot be '.$verb.' yet') === true) {
			throw new ConflictException(
				message: 'This share is quarantined for an administrator to review and cannot be '.$verb.'; ask an administrator to release or drop it'
			);
		}
	}//end assertNotHeldBack()

	/**
	 * Record that a restriction resolve skipped a row the pending scan could
	 * not judge, so its copy is resolved again once the scan completes. Each
	 * record is new, so clearing one that was read earlier leaves it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function holdBackResolve(): void {
		// A cached key another request deleted would turn the write into an update of no row.
		$this->appConfig->clearCache();
		$this->appConfig->setValueString(Application::APP_ID, self::RESOLVE_PENDING_KEY, Uuid::uuid4()->toString());
	}//end holdBackResolve()

	/**
	 * The record of a held-back restriction resolve, or null when there is none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function heldBackResolve(): ?string {
		$record = $this->appConfig->getValueString(Application::APP_ID, self::RESOLVE_PENDING_KEY, '');
		if ($record === '') {
			return null;
		}

		return $record;
	}//end heldBackResolve()

	/**
	 * Clear the record of a held-back resolve once every copy was resolved,
	 * unless a resolve was held back again since it was read.
	 *
	 * @param string $record The record heldBackResolve() returned
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function clearHeldBackResolve(string $record): void {
		// Another request may have recorded one meanwhile.
		$this->appConfig->clearCache();
		if ($this->heldBackResolve() === $record) {
			$this->appConfig->deleteKey(Application::APP_ID, self::RESOLVE_PENDING_KEY);
		}
	}//end clearHeldBackResolve()

	/**
	 * heldBack()'s judgement of one row.
	 *
	 * @param string $id The share-target row id
	 *
	 * @return string|null HELD_QUARANTINED or null
	 */
	private function judgeOnTheSpot(string $id): ?string {
		$qb = $this->candidateQuery();
		$qb->andWhere($qb->expr()->eq('st.id', $qb->createNamedParameter($id)));
		$candidates = $this->candidates(qb: $qb);
		if ($candidates !== []) {
			$this->judge(row: $candidates[0], output: null);
		}

		// Read without the candidate query's quarantine filter: the row may
		// have been quarantined after the caller loaded it, by this request,
		// an earlier one or a pass beside it.
		if ($this->isQuarantined(id: $id) === true) {
			return self::HELD_QUARANTINED;
		}

		return null;
	}//end judgeOnTheSpot()

	/**
	 * Run one full pass and record it as completed. A failure anywhere in that
	 * is logged at error level and leaves the scan pending, so the background
	 * job runs the pass again; it never reaches the caller, so `occ upgrade`
	 * or a restore cannot fail on it. A pass that ends after a reopen() leaves
	 * the scan pending too: it may have missed the rows the restore wrote.
	 *
	 * @param IOutput|null $output The migration output, when run from one
	 *
	 * @return bool Whether the pass completed
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function run(?IOutput $output = null): bool {
		try {
			$generation  = $this->generation();
			$quarantined = $this->scan(output: $output);
			$this->appConfig->clearCache();
			if ($this->generation() !== $generation) {
				$this->logger->warning(
					'GHSA-m5p9-6pw7-pf3g: the share quarantine scan was reopened during this pass, which therefore does not count as completed',
					['app' => 'keepiq']
				);
				return false;
			}

			$this->logger->info(
				'GHSA-m5p9-6pw7-pf3g: the share quarantine scan completed and quarantined '.$quarantined.' row(s)',
				['app' => 'keepiq']
			);
			$this->appConfig->setValueString(Application::APP_ID, self::COMPLETED_KEY, $generation);
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
	 * The generation the scan is in.
	 *
	 * @return string
	 */
	private function generation(): string {
		return $this->appConfig->getValueString(Application::APP_ID, self::GENERATION_KEY, self::INITIAL_GENERATION);
	}//end generation()

	/**
	 * One page of candidates after the given row id.
	 *
	 * @param string $after The last row id of the previous page ('' for the first)
	 *
	 * @return list<array<string,string>> Rows keyed id, sourceSecretId, targetUserId, secretId, createdBy,
	 *                                    rowCreatedAt, copyCreatedAt, createdAsSecret ('1' or '0'),
	 *                                    copyUseOnly ('1' or '0') and copyAccessEndsAt ('' when none)
	 */
	private function candidatePage(string $after): array {
		$qb = $this->candidateQuery();
		$qb->andWhere($qb->expr()->gt('st.id', $qb->createNamedParameter($after)))
			->orderBy('st.id', 'ASC')
			->setMaxResults($this->pageSize);

		return $this->candidates(qb: $qb);
	}//end candidatePage()

	/**
	 * The candidates a candidateQuery() returns.
	 *
	 * @param IQueryBuilder $qb The candidate query
	 *
	 * @return list<array<string,string>> Rows as candidatePage() describes them
	 */
	private function candidates(IQueryBuilder $qb): array {
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
	}//end candidates()

	/**
	 * The candidates: live, unjudged rows whose copy was created before the
	 * row or has a `secret.created` entry. Every server-minted copy shares its
	 * row's second and has no such entry, so this leaves out nearly every
	 * legitimate row in SQL.
	 *
	 * @return IQueryBuilder
	 */
	private function candidateQuery(): IQueryBuilder {
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
			);

		return $qb;
	}//end candidateQuery()

	/**
	 * Quarantine one candidate row when a signal holds.
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
	 * Whether a row is quarantined.
	 *
	 * @param string $id The share-target row id
	 *
	 * @return bool
	 */
	private function isQuarantined(string $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->isNotNull('quarantined_at'));

		return $this->exists(qb: $qb);
	}//end isQuarantined()

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
