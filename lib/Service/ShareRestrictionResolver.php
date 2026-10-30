<?php

/**
 * Keepiq ShareRestrictionResolver
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

use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Materialises the effective use-only flag and access end date of every
 * grant that reaches a recipient copy onto that copy, as `secrets.use_only`
 * and `secrets.access_expires_at` (sharing-use-only-and-expiring-shares D1),
 * so every read path and client sees them without a join.
 *
 * ShareGrantReader lists the grants reaching one recipient for one source
 * secret; ShareRestrictionRules::combine() decides: the most generous grant
 * wins.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The resolver reads every grant reaching a copy, writes the copy
 *   and asks the pending share quarantine scan whether it may (GHSA-m5p9-6pw7-pf3g); each collaborator is one of those.
 */
class ShareRestrictionResolver {

	/**
	 * Share rows read per page by resolveAll().
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * The grants reaching a recipient copy.
	 *
	 * @var ShareGrantReader
	 */
	private ShareGrantReader $grants;

	/**
	 * Constructor for ShareRestrictionResolver.
	 *
	 * @param SecretMapper           $secretMapper      The secret mapper (sources and copies)
	 * @param ShareTargetMapper      $shareTargetMapper The share-target mapper
	 * @param GroupShareMapper       $groupShareMapper  The group-share mapper
	 * @param TeamFolderQueryService $teamFolders       The team-folder read side (covering memberships)
	 * @param IGroupManager          $groupManager      The Nextcloud group manager
	 * @param LoggerInterface|null   $logger            The logger (skipped cross-user copies)
	 * @param ShareQuarantineScanner|null $quarantineScan Holds back the rows the pending scan catches or cannot judge
	 * @param ShareGrantReader|null  $grants            The grant reader (one built on the mappers above when null)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private ShareTargetMapper $shareTargetMapper,
		GroupShareMapper $groupShareMapper,
		TeamFolderQueryService $teamFolders,
		IGroupManager $groupManager,
		private ?LoggerInterface $logger = null,
		private ?ShareQuarantineScanner $quarantineScan = null,
		?ShareGrantReader $grants = null,
	) {
		$this->grants = ($grants ?? new ShareGrantReader(
			secretMapper: $secretMapper,
			groupShareMapper: $groupShareMapper,
			teamFolders: $teamFolders,
			groupManager: $groupManager,
		));
	}//end __construct()

	/**
	 * The effective restriction of one share row's recipient copy.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return ShareRestriction
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function effectiveFor(ShareTarget $target): ShareRestriction {
		return (new ShareRestrictionRules())->combine(grants: $this->grants->grantsFor(target: $target));
	}//end effectiveFor()

	/**
	 * Recompute one share row's recipient copy and write it when it changed.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return bool Whether the copy was written
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveTarget(ShareTarget $target): bool {
		$copy = $this->heldCopy(target: $target);
		if ($copy === null) {
			return false;
		}

		// The copy of a row the pending quarantine scan holds back is left
		// untouched too. One it cannot judge is resolved again once the scan
		// completes, so a skipped change of its restriction is not lost.
		$held = $this->quarantineScan?->heldBack(row: $target);
		if ($held !== null) {
			$this->logger?->warning(
				'Skipped a restriction resolve for a copy the pending quarantine scan holds back',
				['app' => 'keepiq', 'shareId' => $target->getId(), 'secretId' => $target->getSecretId()]
			);
			if ($held === ShareQuarantineScanner::HELD_UNJUDGED) {
				$this->quarantineScan?->holdBackResolve();
			}

			return false;
		}

		return $this->write(target: $target, copy: $copy);
	}//end resolveTarget()

	/**
	 * Resolve the copy the server minted for a share row in this same request.
	 * Such a row cannot be a plant, so the pending quarantine scan does not
	 * judge it: a use-only or expiring share never leaves a full copy behind
	 * because judging failed.
	 *
	 * @param ShareTarget $target The share row, minted and linked in this request
	 *
	 * @return bool Whether the copy was written
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveMinted(ShareTarget $target): bool {
		$copy = $this->heldCopy(target: $target);
		if ($copy === null) {
			return false;
		}

		return $this->write(target: $target, copy: $copy);
	}//end resolveMinted()

	/**
	 * Recompute a list of share rows for an owner's request that may be
	 * refused: a row the pending quarantine scan cannot judge refuses it, so
	 * the caller rolls back rather than leave a copy on its old restriction.
	 * A row the scan quarantines is left out.
	 *
	 * @param array<int,ShareTarget> $targets The share rows
	 * @param string                 $refusal What waits for the scan (ShareQuarantineScanner::refuseUnjudged())
	 *
	 * @return int The number of copies written
	 *
	 * @throws \OCA\Keepiq\Exception\ShareScanPendingException When the scan cannot judge one of the rows
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function resolveTargetsOrRefuse(array $targets, string $refusal): int {
		$written = 0;
		foreach ($targets as $target) {
			$copy = $this->heldCopy(target: $target);
			if ($copy === null || $this->quarantineScan?->refuseUnjudged(row: $target, refusal: $refusal) === true) {
				continue;
			}

			if ($this->write(target: $target, copy: $copy) === true) {
				++$written;
			}
		}

		return $written;
	}//end resolveTargetsOrRefuse()

	/**
	 * Recompute every live recipient copy, a page at a time.
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function resolveAll(): int {
		$written = 0;
		$after   = '';
		$full    = true;
		while ($full === true) {
			$page     = $this->shareTargetMapper->findPage(after: $after, limit: self::PAGE_SIZE);
			$written += $this->resolveTargets(targets: $page);
			$full     = (count($page) === self::PAGE_SIZE);
			if ($full === true) {
				$after = (string)$page[array_key_last($page)]->getId();
			}
		}

		return $written;
	}//end resolveAll()

	/**
	 * The copy a share row names, or null when it is gone or its target does
	 * not hold it. Defence in depth: a copy not genuinely held by the row's
	 * target (a poisoned or drifted `secret_id`) is left untouched, so
	 * resolving a restriction can never rewrite another user's secret.
	 *
	 * @param ShareTarget $target The share row
	 *
	 * @return Secret|null
	 */
	private function heldCopy(ShareTarget $target): ?Secret {
		try {
			$copy = $this->secretMapper->findById($target->getSecretId());
		} catch (DoesNotExistException) {
			return null;
		}

		if ($target->isHeldByTarget(copy: $copy) === false) {
			$this->logger?->warning(
				'Skipped a restriction resolve for a copy not held by its share target',
				['app' => 'keepiq', 'shareId' => $target->getId(), 'secretId' => $target->getSecretId()]
			);
			return null;
		}

		return $copy;
	}//end heldCopy()

	/**
	 * Write a share row's effective restriction onto its copy when it changed.
	 *
	 * @param ShareTarget $target The share row
	 * @param Secret      $copy   Its copy, held by its target
	 *
	 * @return bool Whether the copy was written
	 */
	private function write(ShareTarget $target, Secret $copy): bool {
		$effective = $this->effectiveFor(target: $target);
		$current   = new ShareRestriction(
			useOnly: ($copy->getUseOnly() === true),
			expiresAt: $copy->getAccessExpiresAt()
		);
		if ($effective->equals(other: $current) === true) {
			return false;
		}

		$copy->setUseOnly($effective->useOnly);
		$copy->setAccessExpiresAt($effective->expiresAt);
		$this->secretMapper->update($copy);

		return true;
	}//end write()

	/**
	 * Recompute every recipient copy of one source secret.
	 *
	 * @param string $sourceSecretId The source secret
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveSource(string $sourceSecretId): int {
		return $this->resolveTargets(targets: $this->shareTargetMapper->findBySourceSecret($sourceSecretId));
	}//end resolveSource()

	/**
	 * Recompute a list of share rows.
	 *
	 * @param array<int,ShareTarget> $targets The share rows
	 *
	 * @return int The number of copies written
	 *
	 * @spec openspec/changes/archive/2026-10-04-sharing-use-only-and-expiring-shares/tasks.md#task-1.2
	 */
	public function resolveTargets(array $targets): int {
		$written = 0;
		foreach ($targets as $target) {
			if ($this->resolveTarget(target: $target) === true) {
				++$written;
			}
		}

		return $written;
	}//end resolveTargets()
}//end class
