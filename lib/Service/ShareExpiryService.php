<?php

/**
 * Keepiq ShareExpiryService
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
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * One run of the share expiry (sharing-use-only-and-expiring-shares D5, D6):
 * warn the holders of copies whose access ends within a day, remove the
 * grants whose end date passed, clean up copies left expired, and tell the
 * holder and the owner of every copy whose access ended.
 */
class ShareExpiryService {

	/**
	 * Constructor for ShareExpiryService.
	 *
	 * @param SecretMapper             $secretMapper The secret mapper (copies)
	 * @param ShareTargetMapper        $targetMapper The share-target mapper
	 * @param ShareRestrictionResolver $restrictions The restriction resolver
	 * @param ExpiredGrantRemover      $remover      Removes expired grants through their own paths
	 * @param NotificationService      $notifications The notification dispatcher
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $targetMapper,
		private ShareRestrictionResolver $restrictions,
		private ExpiredGrantRemover $remover,
		private NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * Warn every holder whose access ends in (from, to].
	 *
	 * @param DateTime $from Exclusive lower bound
	 * @param DateTime $to   Inclusive upper bound
	 *
	 * @return int The number of warnings sent
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	public function warnEnding(DateTime $from, DateTime $to): int {
		$sent = 0;
		foreach ($this->secretMapper->findAccessEndingBetween($from, $to) as $copy) {
			$this->notifications->notify(
				subject: 'share_access_ending',
				recipientId: $copy->getOwnerId(),
				params: [
					'secret_id' => $copy->getId(),
					'secret_name' => $copy->getName(),
					'ends_at' => $copy->getAccessExpiresAt()?->format('c'),
				],
				objectType: 'secret',
				objectId: $copy->getId(),
			);
			++$sent;
		}

		return $sent;
	}//end warnEnding()

	/**
	 * Remove every expired grant and every copy left expired, and notify.
	 *
	 * @param DateTime $now The cut-off
	 *
	 * @return int The number of copies whose access ended in this run
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-a-background-job-removes-expired-access
	 */
	public function expire(DateTime $now): int {
		// Remember who held what before the removals delete the copies.
		$ended = [];
		foreach ($this->secretMapper->findAccessEndingBetween(null, $now) as $copy) {
			$ended[$copy->getId()] = [
				'copy' => $copy,
				'target' => $this->targetOf(copyId: $copy->getId()),
			];
		}

		$this->remover->removeExpired(now: $now);

		$count = 0;
		foreach ($ended as $copyId => $entry) {
			if ($this->copyEnded(copyId: (string)$copyId, target: $entry['target'], now: $now) === false) {
				continue;
			}

			$this->notifyEnded(copy: $entry['copy'], target: $entry['target']);
			++$count;
		}

		return $count;
	}//end expire()

	/**
	 * Whether a copy that was expired at the start of the run is gone now.
	 * A copy a grant removal did not reach (a group-derived copy whose
	 * group share ended earlier, a stale value) is recomputed, and revoked
	 * when it is still expired.
	 *
	 * @param string           $copyId The copy
	 * @param ShareTarget|null $target Its share row, as it was
	 * @param DateTime         $now    The cut-off
	 *
	 * @return bool True when the holder's access ended
	 */
	private function copyEnded(string $copyId, ?ShareTarget $target, DateTime $now): bool {
		try {
			$this->secretMapper->findById($copyId);
		} catch (DoesNotExistException) {
			return true;
		}

		if ($target === null) {
			return false;
		}

		try {
			$current = $this->targetMapper->findById($target->getId());
		} catch (DoesNotExistException) {
			return true;
		}

		$this->restrictions->resolveTarget(target: $current);
		$effective = $this->restrictions->effectiveFor(target: $current);
		if ($effective->expiresAt === null || $effective->expiresAt > $now) {
			return false;
		}

		try {
			return $this->remover->revokeTarget(target: $current);
		} catch (Throwable) {
			return false;
		}
	}//end copyEnded()

	/**
	 * Tell the holder their access ended, and the owner that it did, with a
	 * rotation hint when the holder could see the value.
	 *
	 * @param Secret           $copy   The copy as it was
	 * @param ShareTarget|null $target Its share row, as it was
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-use-only-and-expiring-shares/specs/expiring-shares/spec.md#requirement-people-are-told-before-and-when-access-ends
	 */
	private function notifyEnded(Secret $copy, ?ShareTarget $target): void {
		$this->notifications->notify(
			subject: 'share_access_ended',
			recipientId: $copy->getOwnerId(),
			params: ['secret_name' => $copy->getName()],
		);

		if ($target === null) {
			return;
		}

		try {
			$source = $this->secretMapper->findById($target->getSourceSecretId());
		} catch (DoesNotExistException) {
			return;
		}

		$this->notifications->notify(
			subject: 'share_access_ended_owner',
			recipientId: $source->getOwnerId(),
			params: [
				'secret_id' => $source->getId(),
				'secret_name' => $source->getName(),
				'recipient' => $copy->getOwnerId(),
				'use_only' => ($copy->getUseOnly() === true),
			],
			objectType: 'secret',
			objectId: $source->getId(),
		);
	}//end notifyEnded()

	/**
	 * The share row of a copy, or null when it has none.
	 *
	 * @param string $copyId The copy
	 *
	 * @return ShareTarget|null
	 */
	private function targetOf(string $copyId): ?ShareTarget {
		try {
			return $this->targetMapper->findByRecipientSecret(recipientSecretId: $copyId);
		} catch (DoesNotExistException) {
			return null;
		}
	}//end targetOf()
}//end class
