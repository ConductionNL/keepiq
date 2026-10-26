<?php

/**
 * Keepiq Share Quarantine Service
 *
 * The administrator's side of the share quarantine (GHSA-m5p9-6pw7-pf3g):
 * list the quarantined rows, release one the scan caught by mistake, or drop
 * one for good. Each release and drop is audited. Reached only through
 * `occ keepiq:share-quarantine`.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Lists, releases and drops quarantined share rows.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineService {
	/**
	 * Constructor.
	 *
	 * @param QuarantinedShareTargetMapper $quarantined The quarantined rows
	 * @param ShareTargetMapper $mapper The live rows
	 * @param SecretMapper $secretMapper The rows' copies
	 * @param GroupShareMapper $groupShareMapper The group shares a row may be linked to
	 * @param IEventDispatcher $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private QuarantinedShareTargetMapper $quarantined,
		private ShareTargetMapper $mapper,
		private SecretMapper $secretMapper,
		private GroupShareMapper $groupShareMapper,
		private IEventDispatcher $eventDispatcher,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Every quarantined row with its copy (null when the copy is gone), oldest
	 * quarantine first.
	 *
	 * @return list<array{row:ShareTarget,copy:Secret|null}>
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function list(): array {
		$entries = [];
		foreach ($this->quarantined->findQuarantined() as $row) {
			$entries[] = ['row' => $row, 'copy' => $this->copyOf(row: $row)];
		}

		return $entries;
	}//end list()

	/**
	 * Release a row the scan quarantined by mistake: it is a live share again,
	 * which its owner sees and may revoke. Its reason stays, so a later scan
	 * leaves it alone. Refused while the owner holds another live share with
	 * the same recipient, as one source and recipient have one share; drop the
	 * row then. Released without force only when its copy is gone or held by
	 * the row's creator, as a legacy two-step client left it. Released only
	 * when forced: a row whose copy was already shared before it; a row whose
	 * copy belongs to the recipient, as revoking the share then deletes what
	 * may be the recipient's own secret; a row whose copy is held by anyone
	 * else or is the source of a live share, as releasing it makes that
	 * holder's own secret look like a received copy again; and a row whose
	 * share or copy carries an access end date, as the expiry job revokes an
	 * ended share and deletes its copy.
	 *
	 * @param string $id    The share-target row id
	 * @param bool   $force Whether the administrator releases a row that needs force
	 *
	 * @return ShareTarget The released row
	 *
	 * @throws InvalidArgumentException When no quarantined row has this id, it needs force, or a live share replaces it
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function release(string $id, bool $force): ShareTarget {
		$row = $this->loadQuarantined(id: $id);
		if ($force === false) {
			$this->assertReleasableWithoutForce(row: $row);
		}

		try {
			$this->mapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $row->getSourceSecretId(),
				targetUserId: $row->getTargetUserId()
			);
			$replaced = true;
		} catch (DoesNotExistException) {
			$replaced = false;
		} catch (MultipleObjectsReturnedException) {
			$replaced = true;
		}

		if ($replaced === true) {
			throw new InvalidArgumentException(
				message: 'The source is shared with this recipient again; drop this row instead'
			);
		}

		$row->setQuarantinedAt(null);
		$released = $this->quarantined->update($row);
		$this->audit(
			eventType: AuditEventTypes::SHARE_QUARANTINE_RELEASED,
			row: $released,
			extra: ['forced' => $force]
		);

		return $released;
	}//end release()

	/**
	 * Drop a quarantined row for good. Its copy is kept: it may be the
	 * recipient's own secret. With $clearRestrictions, the use-only flag and
	 * access end date a planted row wrote onto that copy are cleared, but only
	 * when the row's recipient holds the copy.
	 *
	 * @param string $id                The share-target row id
	 * @param bool   $clearRestrictions Whether to clear the copy's use-only flag and access end date
	 *
	 * @return bool Whether the copy's restrictions were cleared
	 *
	 * @throws InvalidArgumentException When no quarantined row has this id
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Mirrors the command's --clear-restrictions option.
	 */
	public function drop(string $id, bool $clearRestrictions = false): bool {
		$row     = $this->loadQuarantined(id: $id);
		$cleared = false;
		if ($clearRestrictions === true) {
			$copy = $this->copyOf(row: $row);
			if ($copy !== null && $row->isHeldByTarget(copy: $copy) === true) {
				$copy->setUseOnly(false);
				$copy->setAccessExpiresAt(null);
				$this->secretMapper->update($copy);
				$cleared = true;
			}
		}

		$this->quarantined->delete($row);
		$this->audit(
			eventType: AuditEventTypes::SHARE_QUARANTINE_DROPPED,
			row: $row,
			extra: ['restrictionsCleared' => $cleared]
		);

		return $cleared;
	}//end drop()

	/**
	 * Refuse a release that needs --force, naming every reason.
	 *
	 * @param ShareTarget $row The quarantined row
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the row needs force
	 */
	private function assertReleasableWithoutForce(ShareTarget $row): void {
		$reasons = [];
		if ($row->getQuarantineReason() === ShareQuarantineScanner::REASON_COPY_SHARED_BEFORE_ROW) {
			$reasons[] = 'its copy was shared before the row existed, the mark of a planted row';
		}

		// Only a copy that is gone or held by the row's creator, as a legacy
		// two-step client left it, is released without force. A copy the
		// recipient holds cannot be told apart from their own secret, which
		// revoking the released share deletes.
		$copy = $this->copyOf(row: $row);
		if ($copy !== null && $row->isHeldByTarget(copy: $copy) === true) {
			if ($row->getQuarantineReason() === ShareQuarantineScanner::REASON_COPY_CREATED_AS_SECRET) {
				$reasons[] = 'its copy was created as a secret of its own and belongs to the recipient, the mark of a planted row';
			} else {
				$reasons[] = 'its copy belongs to the recipient and may be their own secret, which revoking the released share deletes';
			}
		} elseif ($copy !== null && ($copy->getOwnerType() !== 'user' || $copy->getOwnerId() !== $row->getCreatedBy())) {
			$reasons[] = 'its copy is held by '.$copy->getOwnerType().' '.$copy->getOwnerId()
				.', neither the recipient nor the row\'s creator, so it may be that holder\'s own secret';
		}

		if ($this->mapper->findBySourceSecret(sourceSecretId: $row->getSecretId()) !== []) {
			$reasons[] = 'its copy is the source of a live share, which a share copy may no longer be, so it is most likely its holder\'s own secret';
		}

		$endsAt = $this->accessEnd(row: $row, copy: $copy);
		if ($endsAt !== null) {
			$passed = '';
			if ($endsAt <= new DateTime()) {
				$passed = ', already passed';
			}

			$reasons[] = 'its share or copy carries an access end date ('.$endsAt->format(DATE_ATOM).$passed
				.'), and the expiry job revokes an ended share and deletes its copy, which may be the recipient\'s own secret';
		}

		if ($reasons === []) {
			return;
		}

		throw new InvalidArgumentException(
			message: 'This row is released only with --force: '.implode('; ', $reasons)
				.'. To keep the copy, drop the row instead, with --clear-restrictions to lift the copy\'s restrictions'
		);
	}//end assertReleasableWithoutForce()

	/**
	 * The earliest access end date among the row's own, the group share it is
	 * linked to, and its copy's, or null when none has one.
	 *
	 * @param ShareTarget $row  The quarantined row
	 * @param Secret|null $copy Its copy, or null when it is gone
	 *
	 * @return DateTime|null
	 */
	private function accessEnd(ShareTarget $row, ?Secret $copy): ?DateTime {
		$ends = [$row->getExpiresAt(), $copy?->getAccessExpiresAt()];
		if ($row->getGroupShareId() !== null) {
			try {
				$ends[] = $this->groupShareMapper->findById($row->getGroupShareId())->getExpiresAt();
			} catch (DoesNotExistException) {
				// The group share is gone; its expiry cannot revoke the row.
			}
		}

		$ends = array_filter($ends, static fn (?DateTime $end): bool => $end !== null);
		if ($ends === []) {
			return null;
		}

		return min($ends);
	}//end accessEnd()

	/**
	 * A row's copy, or null when it is gone.
	 *
	 * @param ShareTarget $row The row
	 *
	 * @return Secret|null
	 */
	private function copyOf(ShareTarget $row): ?Secret {
		try {
			return $this->secretMapper->findById($row->getSecretId());
		} catch (DoesNotExistException) {
			return null;
		}
	}//end copyOf()

	/**
	 * Load one quarantined row.
	 *
	 * @param string $id The share-target row id
	 *
	 * @return ShareTarget
	 *
	 * @throws InvalidArgumentException When no quarantined row has this id
	 */
	private function loadQuarantined(string $id): ShareTarget {
		try {
			return $this->quarantined->findQuarantinedById($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new InvalidArgumentException(message: 'No quarantined share has the id '.$id);
		}
	}//end loadQuarantined()

	/**
	 * Audit a release or drop, with the administrator as the system actor.
	 *
	 * @param string              $eventType The audit event type
	 * @param ShareTarget         $row       The row
	 * @param array<string,mixed> $extra     Further metadata for this event type
	 *
	 * @return void
	 */
	private function audit(string $eventType, ShareTarget $row, array $extra = []): void {
		$this->eventDispatcher->dispatchTyped(
			$this->auditEvents->forSystem(
				eventType: $eventType,
				objectType: 'share',
				objectId: $row->getId(),
				metadata: array_merge(
					[
						'sourceSecretId' => $row->getSourceSecretId(),
						'recipientId' => $row->getTargetUserId(),
						'copyId' => $row->getSecretId(),
						'reason' => (string)$row->getQuarantineReason(),
					],
					$extra
				),
			)
		);
	}//end audit()
}//end class
