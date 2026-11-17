<?php

/**
 * Unit tests for the skip, hold-back, refusal and paging paths of ShareRestrictionResolver.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareGrantReader;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRestriction;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRestrictionRules;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A resolve leaves copies it may not touch alone and pages through every row.
 */
#[CoversClass(ShareRestrictionResolver::class)]
#[UsesClass(ShareRestriction::class)]
#[UsesClass(ShareRestrictionRules::class)]
#[UsesClass(ShareTarget::class)]
#[UsesClass(Secret::class)]
class ShareRestrictionResolverPathsTest extends TestCase {

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $targets;

	/** @var ShareQuarantineScanner&MockObject */
	private ShareQuarantineScanner $scanner;

	/** @var ShareGrantReader&MockObject */
	private ShareGrantReader $grants;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var array<string,Secret> */
	private array $copies = [];

	/**
	 * Builds the mocks; every copy is looked up in $copies.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->scanner = $this->createMock(ShareQuarantineScanner::class);
		$this->grants  = $this->createMock(ShareGrantReader::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		$this->secrets->method('findById')->willReturnCallback(
			function (string $id): Secret {
				if (isset($this->copies[$id]) === false) {
					throw new DoesNotExistException('no copy');
				}

				return $this->copies[$id];
			}
		);
	}//end setUp()

	/**
	 * The resolver under test.
	 *
	 * @return ShareRestrictionResolver
	 */
	private function resolver(): ShareRestrictionResolver {
		return new ShareRestrictionResolver(
			secretMapper: $this->secrets,
			shareTargetMapper: $this->targets,
			groupShareMapper: $this->createMock(GroupShareMapper::class),
			teamFolders: $this->createMock(TeamFolderQueryService::class),
			groupManager: $this->createMock(IGroupManager::class),
			quarantineScan: $this->scanner,
			logger: $this->logger,
			grants: $this->grants,
		);
	}//end resolver()

	/**
	 * A share row for bob whose copy is $copyId, with a copy owned by $owner when given.
	 *
	 * @param string      $id     The row id
	 * @param string      $copyId The copy id
	 * @param string|null $owner  The copy's owner, or null for no copy
	 *
	 * @return ShareTarget
	 */
	private function row(string $id, string $copyId, ?string $owner = 'bob'): ShareTarget {
		$target = new ShareTarget();
		$target->setId($id);
		$target->setSourceSecretId('src');
		$target->setTargetUserId('bob');
		$target->setSecretId($copyId);
		if ($owner !== null) {
			$copy = new Secret();
			$copy->setId($copyId);
			$copy->setOwnerType('user');
			$copy->setOwnerId($owner);
			$copy->setUseOnly(false);
			$this->copies[$copyId] = $copy;
		}

		return $target;
	}//end row()

	/**
	 * A use-only grant, so an unrestricted copy needs a write.
	 *
	 * @return void
	 */
	private function useOnlyGrant(): void {
		$this->grants->method('grantsFor')->willReturn([new ShareRestriction(useOnly: true)]);
	}//end useOnlyGrant()

	/**
	 * A row whose copy is gone is not resolved and nothing is written.
	 *
	 * @return void
	 */
	public function testAMissingCopyIsNotResolved(): void {
		$this->useOnlyGrant();
		$this->secrets->expects($this->never())->method('update');
		$this->scanner->expects($this->never())->method('heldBack');

		$this->assertFalse($this->resolver()->resolveTarget(target: $this->row('t1', 'gone', null)));
	}//end testAMissingCopyIsNotResolved()

	/**
	 * A copy owned by someone other than the row's recipient is skipped with a warning.
	 *
	 * @return void
	 */
	public function testACopyNotHeldByTheRecipientIsSkippedWithAWarning(): void {
		$this->useOnlyGrant();
		$this->secrets->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('warning')->with(
			'Skipped a restriction resolve for a copy not held by its share target',
			['app' => 'keepiq', 'shareId' => 't1', 'secretId' => 'copy-1']
		);

		$this->assertFalse($this->resolver()->resolveTarget(target: $this->row('t1', 'copy-1', 'mallory')));
	}//end testACopyNotHeldByTheRecipientIsSkippedWithAWarning()

	/**
	 * A copy the pending scan cannot judge yet is skipped and the resolve is recorded for later.
	 *
	 * @return void
	 */
	public function testAnUnjudgedCopyIsSkippedAndItsResolveHeldBack(): void {
		$this->useOnlyGrant();
		$this->scanner->method('heldBack')->willReturn(ShareQuarantineScanner::HELD_UNJUDGED);
		$this->scanner->expects($this->once())->method('holdBackResolve');
		$this->secrets->expects($this->never())->method('update');
		$this->logger->expects($this->once())->method('warning')->with(
			'Skipped a restriction resolve for a copy the pending quarantine scan holds back',
			['app' => 'keepiq', 'shareId' => 't1', 'secretId' => 'copy-1']
		);

		$this->assertFalse($this->resolver()->resolveTarget(target: $this->row('t1', 'copy-1')));
	}//end testAnUnjudgedCopyIsSkippedAndItsResolveHeldBack()

	/**
	 * A quarantined copy is skipped but no later resolve is recorded.
	 *
	 * @return void
	 */
	public function testAQuarantinedCopyIsSkippedWithoutRecordingAResolve(): void {
		$this->useOnlyGrant();
		$this->scanner->method('heldBack')->willReturn(ShareQuarantineScanner::HELD_QUARANTINED);
		$this->scanner->expects($this->never())->method('holdBackResolve');
		$this->secrets->expects($this->never())->method('update');

		$this->assertFalse($this->resolver()->resolveTarget(target: $this->row('t1', 'copy-1')));
	}//end testAQuarantinedCopyIsSkippedWithoutRecordingAResolve()

	/**
	 * A copy that already carries its effective restriction is not written again.
	 *
	 * @return void
	 */
	public function testACopyAlreadyMatchingIsNotWritten(): void {
		$this->grants->method('grantsFor')->willReturn([]);
		$this->secrets->expects($this->never())->method('update');

		$this->assertFalse($this->resolver()->resolveTarget(target: $this->row('t1', 'copy-1')));
	}//end testACopyAlreadyMatchingIsNotWritten()

	/**
	 * A freshly minted copy is resolved without asking the quarantine scan.
	 *
	 * @return void
	 */
	public function testAMintedCopyIsResolvedWithoutTheScan(): void {
		$this->useOnlyGrant();
		$this->scanner->expects($this->never())->method('heldBack');
		$row = $this->row('t1', 'copy-1');
		$this->secrets->expects($this->once())->method('update')->with($this->identicalTo($this->copies['copy-1']));

		$this->assertTrue($this->resolver()->resolveMinted(target: $row));
		$this->assertTrue($this->copies['copy-1']->getUseOnly());
	}//end testAMintedCopyIsResolvedWithoutTheScan()

	/**
	 * A minted row without a copy is not resolved.
	 *
	 * @return void
	 */
	public function testAMintedRowWithoutACopyIsNotResolved(): void {
		$this->useOnlyGrant();
		$this->secrets->expects($this->never())->method('update');

		$this->assertFalse($this->resolver()->resolveMinted(target: $this->row('t1', 'gone', null)));
	}//end testAMintedRowWithoutACopyIsNotResolved()

	/**
	 * Rows the scan refuses, and rows without a copy, are skipped; the others are counted.
	 *
	 * @return void
	 */
	public function testResolveOrRefuseCountsOnlyTheRowsItWrote(): void {
		$this->useOnlyGrant();
		$refused  = $this->row('t1', 'copy-1');
		$missing  = $this->row('t2', 'gone', null);
		$accepted = $this->row('t3', 'copy-3');
		$this->scanner->method('refuseUnjudged')->willReturnCallback(
			fn (ShareTarget $row, string $refusal): bool => ($row->getId() === 't1' && $refusal === 'refused for now')
		);
		$this->secrets->expects($this->once())->method('update')->with($this->identicalTo($this->copies['copy-3']));

		$this->assertSame(
			1,
			$this->resolver()->resolveTargetsOrRefuse(targets: [$refused, $missing, $accepted], refusal: 'refused for now')
		);
		$this->assertFalse($this->copies['copy-1']->getUseOnly());
	}//end testResolveOrRefuseCountsOnlyTheRowsItWrote()

	/**
	 * A full page is followed by the next one after its last id; a short page ends the walk.
	 *
	 * @return void
	 */
	public function testResolveAllPagesUntilAShortPage(): void {
		$this->useOnlyGrant();
		$this->scanner->method('heldBack')->willReturn(null);
		$first = [];
		for ($i = 1; $i <= 500; $i++) {
			$first[] = $this->row('r'.$i, 'gone-'.$i, null);
		}

		$second = [$this->row('s1', 'copy-s1')];
		$calls  = [];
		$this->targets->method('findPage')->willReturnCallback(
			function (string $after, int $limit) use (&$calls, $first, $second): array {
				$calls[] = [$after, $limit];
				return ($after === '') ? $first : $second;
			}
		);
		$this->secrets->expects($this->once())->method('update');

		$this->assertSame(1, $this->resolver()->resolveAll());
		$this->assertSame([['', 500], ['r500', 500]], $calls);
	}//end testResolveAllPagesUntilAShortPage()

	/**
	 * Resolving a source resolves every row the source has.
	 *
	 * @return void
	 */
	public function testResolveSourceResolvesItsRows(): void {
		$this->useOnlyGrant();
		$this->scanner->method('heldBack')->willReturn(null);
		$this->targets->expects($this->once())->method('findBySourceSecret')->with('src')->willReturn(
			[$this->row('t1', 'copy-1'), $this->row('t2', 'copy-2')]
		);
		$this->secrets->expects($this->exactly(2))->method('update');

		$this->assertSame(2, $this->resolver()->resolveSource(sourceSecretId: 'src'));
	}//end testResolveSourceResolvesItsRows()

	/**
	 * The effective restriction combines every grant: use-only only when all are, the latest end.
	 *
	 * @return void
	 */
	public function testTheEffectiveRestrictionCombinesTheGrants(): void {
		$early = new DateTime('2030-01-01T00:00:00Z');
		$late  = new DateTime('2031-01-01T00:00:00Z');
		$this->grants->method('grantsFor')->willReturn(
			[new ShareRestriction(useOnly: true, expiresAt: $early), new ShareRestriction(useOnly: false, expiresAt: $late)]
		);

		$effective = $this->resolver()->effectiveFor(target: $this->row('t1', 'copy-1'));

		$this->assertFalse($effective->useOnly);
		$this->assertSame($late, $effective->expiresAt);
	}//end testTheEffectiveRestrictionCombinesTheGrants()
}//end class
