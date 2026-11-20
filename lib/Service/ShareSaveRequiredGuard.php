<?php

/**
 * Keepiq Share-Save Required Guard
 *
 * Refuses a value change through the plain update when it would reach one
 * row of a shared secret only: a source with live copies or active partner
 * shares, or a copy whose holder writes its source for everyone. Such a
 * change goes through the share-save, which writes every copy in the same
 * transaction (keepiq#1300). The plain update and a version restore run
 * it; the share-save itself and a secret request fill (values sealed to the
 * requester) keep their own paths.
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

use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ShareSaveRequiredException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;

/**
 * Whether a plain update may change a row's value: only when the share-save
 * would have no copy and no partner share to write beside it.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
 */
class ShareSaveRequiredGuard {
	/**
	 * Constructor.
	 *
	 * @param ShareRowJudge $rowJudge Judged share rows (ShareRowJudge); every lookup of a share row goes through it
	 * @param ShareSyncGuards $guards The live copies of a source, as the share-save requires them
	 * @param FederatedShareMapper $federatedShares The partner shares
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareRowJudge $rowJudge,
		private ShareSyncGuards $guards,
		private FederatedShareMapper $federatedShares,
	) {
	}//end __construct()

	/**
	 * Refuse a change of a value field when the row is a copy, or has live
	 * copies or active partner shares. A share row of the row itself that
	 * is ambiguous or waits for the scan is refused here too, as the
	 * share-save does, whatever ran before.
	 *
	 * @param Secret $row The caller's row, already checked to be theirs
	 * @param string[] $fields The value fields the change writes with a new value
	 *
	 * @return void
	 *
	 * @throws ShareSaveRequiredException When the change must go through the share-save
	 * @throws ShareScanPendingException When a share row of the row or of a copy waits for the share quarantine scan
	 * @throws NotFoundException When the row's own share row is ambiguous
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	public function assertSavedAlone(Secret $row, array $fields): void {
		if (array_intersect(Secret::VALUE_FIELDS, $fields) === []) {
			return;
		}

		$verdict = $this->rowJudge->forCopy(copy: $row);
		if ($verdict->state === ShareRowVerdict::AMBIGUOUS) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($verdict->state === ShareRowVerdict::UNJUDGED) {
			throw $this->scanPending();
		}

		if ($verdict->trustedRow() !== null) {
			throw new ShareSaveRequiredException();
		}

		['copies' => $copies, 'unjudged' => $unjudged] = $this->guards->liveCopies(source: $row, leaveOut: $row->getId());
		if ($unjudged !== []) {
			throw $this->scanPending();
		}

		if ($copies !== [] || $this->hasActivePartnerShare(row: $row) === true) {
			throw new ShareSaveRequiredException();
		}
	}//end assertSavedAlone()

	/**
	 * The refusal while a share row waits for the share quarantine scan.
	 *
	 * @return ShareScanPendingException
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	private function scanPending(): ShareScanPendingException {
		return $this->rowJudge->scanPending(refusal: 'This secret waits for the share quarantine scan and cannot be changed yet');
	}//end scanPending()

	/**
	 * Whether the row has a partner share the share-save writes.
	 *
	 * @param Secret $row The row
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-a-value-change-of-a-shared-secret-goes-through-the-share-save
	 */
	private function hasActivePartnerShare(Secret $row): bool {
		foreach ($this->federatedShares->findBySourceSecret(sourceSecretId: $row->getId()) as $share) {
			if ($share->getStatus() === FederatedShare::STATUS_ACTIVE) {
				return true;
			}
		}

		return false;
	}//end hasActivePartnerShare()
}//end class
