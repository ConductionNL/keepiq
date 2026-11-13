<?php

/**
 * A quarantined team-folder row is never released, not even with --force,
 * once its folder is gone or no longer covers its recipient; a row the
 * folder still grants is released as before.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Db\TeamFolder;
use OCA\Keepiq\Db\TeamFolderMapper;
use OCA\Keepiq\Service\ShareQuarantineService;
use OCA\Keepiq\Service\TeamFolderMembershipResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A row `{S_a, bob, V}` from team folder tf-1, quarantined.
 */
class ShareQuarantineReleaseTest extends TestCase {

	/**
	 * The quarantined row.
	 *
	 * @var ShareTarget
	 */
	private ShareTarget $row;

	/**
	 * What the live lookup for the row's pair answers, or null for none.
	 *
	 * @var (callable(): ShareTarget)|null
	 */
	private $liveLookup = null;

	/**
	 * Seed the row.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->row = new ShareTarget();
		$this->row->setId('row-V');
		$this->row->setSourceSecretId('S_a');
		$this->row->setTargetUserId('bob');
		$this->row->setSecretId('V');
		$this->row->setTeamFolderId('tf-1');
		$this->row->setQuarantinedAt(new DateTime());
	}//end setUp()

	/**
	 * A row whose team folder is gone is not released, even when forced.
	 *
	 * @return void
	 */
	public function testARowOfAFolderThatIsGoneIsNotReleasedEvenWhenForced(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no longer exists');
		$this->service(folderExists: false, members: ['bob'])->release(id: 'row-V', force: true);
	}//end testARowOfAFolderThatIsGoneIsNotReleasedEvenWhenForced()

	/**
	 * A row whose recipient the folder no longer covers is not released,
	 * even when forced.
	 *
	 * @return void
	 */
	public function testARowOfAFormerMemberIsNotReleasedEvenWhenForced(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('no longer a member');
		$this->service(folderExists: true, members: ['carol'])->release(id: 'row-V', force: true);
	}//end testARowOfAFormerMemberIsNotReleasedEvenWhenForced()

	/**
	 * A row whose recipient a group still covers but whose account is
	 * disabled, as an offboarded leaver's is, is not released, even when
	 * forced: the fan-out never shares to a disabled account either.
	 *
	 * @return void
	 */
	public function testARowOfADisabledMemberIsNotReleasedEvenWhenForced(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('disabled');
		$this->service(folderExists: true, members: ['bob'], disabled: true)->release(id: 'row-V', force: true);
	}//end testARowOfADisabledMemberIsNotReleasedEvenWhenForced()

	/**
	 * A row the folder still grants is released when forced, as before.
	 *
	 * @return void
	 */
	public function testARowTheFolderStillGrantsIsReleased(): void {
		$released = $this->service(folderExists: true, members: ['bob'])->release(id: 'row-V', force: true);

		$this->assertNull($released->getQuarantinedAt());
	}//end testARowTheFolderStillGrantsIsReleased()

	/**
	 * Golden: a row whose pair is live again, once or more, is never released,
	 * even when forced; it is dropped instead.
	 *
	 * @return void
	 */
	public function testARowWhosePairIsSharedAgainIsNotReleased(): void {
		$answers = [
			'one live row' => static fn (): ShareTarget => new ShareTarget(),
			'several live rows' => static fn (): ShareTarget => throw new MultipleObjectsReturnedException('two'),
		];
		foreach ($answers as $name => $answer) {
			$this->liveLookup = $answer;
			try {
				$this->service(folderExists: true, members: ['bob'])->release(id: 'row-V', force: true);
				$this->fail($name . ' was released');
			} catch (InvalidArgumentException $exception) {
				$this->assertSame('The source is shared with this recipient again; drop this row instead', $exception->getMessage());
				$this->assertNotNull($this->row->getQuarantinedAt());
			}
		}
	}//end testARowWhosePairIsSharedAgainIsNotReleased()

	/**
	 * The quarantine service over the one row and a folder that does or does
	 * not exist and covers the given members.
	 *
	 * @param bool         $folderExists Whether tf-1 exists
	 * @param list<string> $members      The users tf-1 covers
	 * @param bool         $disabled     Whether the recipient's account is disabled
	 *
	 * @return ShareQuarantineService
	 */
	private function service(bool $folderExists, array $members, bool $disabled = false): ShareQuarantineService {
		$quarantined = $this->createMock(QuarantinedShareTargetMapper::class);
		$quarantined->method('findQuarantinedById')->willReturn($this->row);
		$quarantined->method('update')->willReturnArgument(0);
		$live = $this->createMock(ShareTargetMapper::class);
		$live->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (): ShareTarget => ($this->liveLookup ?? static fn (): ShareTarget => throw new DoesNotExistException('none'))()
		);
		$folders = $this->createMock(TeamFolderMapper::class);
		if ($folderExists === true) {
			$folders->method('findById')->willReturn(new TeamFolder());
		} else {
			$folders->method('findById')->willThrowException(new DoesNotExistException('gone'));
		}

		$memberships = $this->createMock(TeamFolderMembershipResolver::class);
		$memberships->method('effectiveUsers')->willReturn($members);
		$memberships->method('isDisabledAccount')->willReturn($disabled);

		return new ShareQuarantineService(
			quarantined: $quarantined,
			mapper: $live,
			secretMapper: $this->createMock(SecretMapper::class),
			groupShareMapper: $this->createMock(GroupShareMapper::class),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
			teamFolders: $folders,
			memberships: $memberships,
		);
	}//end service()
}//end class
