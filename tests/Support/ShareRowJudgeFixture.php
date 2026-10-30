<?php

/**
 * Mix into a TestCase to build the ShareRowJudge a service under test reads
 * share rows through.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
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

namespace OCA\Keepiq\Tests\Support;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Builds share-row judges over a test's own mappers.
 */
trait ShareRowJudgeFixture {
	/**
	 * A judge over the given share targets and secrets. Without share targets
	 * no share row exists; without secrets no secret exists; without a scanner
	 * the scan holds no row back, as once it has completed.
	 *
	 * @param ShareTargetMapper|null      $targets The share targets
	 * @param SecretMapper|null           $secrets The secrets
	 * @param ShareQuarantineScanner|null $scanner The pending scan
	 *
	 * @return ShareRowJudge
	 */
	protected function shareRowJudge(
		?ShareTargetMapper $targets = null,
		?SecretMapper $secrets = null,
		?ShareQuarantineScanner $scanner = null,
	): ShareRowJudge {
		if ($targets === null) {
			$targets = $this->createMock(ShareTargetMapper::class);
			$targets->method('findByRecipientSecret')->willThrowException(new DoesNotExistException('none'));
			$targets->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
			$targets->method('findBySourceSecret')->willReturn([]);
			$targets->method('findByTargetUser')->willReturn([]);
		}

		if ($secrets === null) {
			$secrets = $this->createMock(SecretMapper::class);
			$secrets->method('findById')->willThrowException(new DoesNotExistException('none'));
		}

		return new ShareRowJudge(
			targets: $targets,
			secrets: $secrets,
			scanner: ($scanner ?? $this->createMock(ShareQuarantineScanner::class)),
		);
	}//end shareRowJudge()

	/**
	 * A pending quarantine scan that holds no row back, as once it has completed.
	 *
	 * @return ShareQuarantineScanner
	 */
	protected function clearedQuarantineScan(): ShareQuarantineScanner {
		return $this->createMock(ShareQuarantineScanner::class);
	}//end clearedQuarantineScan()

	/**
	 * A judge under which every user holds a trusted copy of every source,
	 * for tests about what happens after that check: the row of (S, U) names
	 * the copy `copy-of-U`, which U holds.
	 *
	 * @return ShareRowJudge
	 */
	protected function shareRowJudgeWhereEveryoneHoldsACopy(): ShareRowJudge {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			static function (string $sourceSecretId, string $targetUserId): ShareTarget {
				$row = new ShareTarget();
				$row->setId('row-'.$sourceSecretId.'-'.$targetUserId);
				$row->setSourceSecretId($sourceSecretId);
				$row->setTargetUserId($targetUserId);
				$row->setSecretId('copy-of-'.$targetUserId);
				return $row;
			}
		);
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			static function (string $id): Secret {
				$copy = new Secret();
				$copy->setId($id);
				$copy->setOwnerType('user');
				$copy->setOwnerId(substr($id, strlen('copy-of-')));
				return $copy;
			}
		);

		return $this->shareRowJudge(targets: $targets, secrets: $secrets);
	}//end shareRowJudgeWhereEveryoneHoldsACopy()
}//end trait
