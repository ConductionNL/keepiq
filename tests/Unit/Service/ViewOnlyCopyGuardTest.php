<?php

/**
 * Unit tests for ViewOnlyCopyGuard.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Exception\ViewOnlyCopyException;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\TeamFolderService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A copy's value is changed only by a holder who writes its source
 * (keepiq#1287).
 */
class ViewOnlyCopyGuardTest extends TestCase {

	private ShareTargetMapper&MockObject $targets;

	private SecretMapper&MockObject $secrets;

	private SecretDelegationMapper&MockObject $delegations;

	private TeamFolderService&MockObject $teamFolders;

	/** @var array<int,GroupShare> The group shares of `src`; bob is a member of `admin` */
	private array $groupShares = [];

	private ViewOnlyCopyGuard $guard;

	/**
	 * Bob holds copy `copy` of alice's `src`; he is no delegate and has no grade.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$source = new Secret();
		$source->setId('src');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');

		$target = new ShareTarget();
		$target->setSourceSecretId('src');
		$target->setSecretId('copy');
		$target->setTargetUserId('bob');

		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->targets->method('findByRecipientSecret')->willReturn($target);
		$this->targets->method('findBySourceSecretAndTargetUser')->willReturn($target);
		$copy = $this->copy();
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->willReturnCallback(
			static fn (string $id): Secret => ($id === 'copy' ? $copy : $source)
		);
		$this->delegations = $this->createMock(SecretDelegationMapper::class);
		$this->delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException(''));
		$this->teamFolders = $this->createMock(TeamFolderService::class);
		$this->buildGuard();
	}//end setUp()

	/**
	 * Bob's row.
	 *
	 * @return Secret
	 */
	private function copy(): Secret {
		$copy = new Secret();
		$copy->setId('copy');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		return $copy;
	}//end copy()

	/**
	 * Each value field on its own is refused for a holder who only views.
	 *
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public static function viewOnlyChanges(): array {
		return [
			'a Viewer changes the key' => ['read', 'key'],
			'a direct recipient changes the login' => [null, 'login'],
			'a Viewer changes the extra fields' => ['read', 'additionalFields'],
			'a Viewer changes the TOTP seed' => ['read', 'totp'],
			'a direct recipient changes the passkeys' => [null, 'passkeys'],
		];
	}//end viewOnlyChanges()

	/**
	 * A holder without a write grade may not change the copy's value.
	 *
	 * @param string|null $grade The holder's team-folder grade, if any
	 * @param string $field The value field changed
	 *
	 * @return void
	 */
	#[DataProvider('viewOnlyChanges')]
	public function testAHolderWhoOnlyViewsMayNotChangeTheValue(?string $grade, string $field): void {
		$this->teamFolders->method('resolveGrade')->willReturn($grade);

		try {
			$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['name', $field], userId: 'bob');
			$this->fail('A holder who only views must not change the value');
		} catch (ViewOnlyCopyException $e) {
			$this->assertSame(Secret::VIEW_ONLY_REFUSAL, $e->getMessage());
			$this->assertSame('view_only', $e->policyCode());
		}
	}//end testAHolderWhoOnlyViewsMayNotChangeTheValue()

	/**
	 * Filing, renaming or retyping a copy leaves its value alone, so a holder
	 * who only views may do it, and the sharing graph is not read.
	 *
	 * @return void
	 */
	public function testAChangeThatLeavesTheValueAlonePasses(): void {
		$this->targets->expects($this->never())->method('findByRecipientSecret');

		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['name', 'url', 'folderId', 'typeId'], userId: 'bob');
		$this->guard->assertMayChangeValue(row: $this->copy(), fields: [], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testAChangeThatLeavesTheValueAlonePasses()

	/**
	 * Holders who write the source may change their copy's value.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public static function writerGrades(): array {
		return [
			'an Editor' => ['write'],
			'a Manager' => ['manage'],
		];
	}//end writerGrades()

	/**
	 * A team-folder Editor or Manager writes the source.
	 *
	 * @param string $grade The holder's team-folder grade
	 *
	 * @return void
	 */
	#[DataProvider('writerGrades')]
	public function testAWriteGradeHolderMayChangeTheValue(string $grade): void {
		$this->teamFolders->method('resolveGrade')->willReturn($grade);

		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key', 'login', 'additionalFields'], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testAWriteGradeHolderMayChangeTheValue()

	/**
	 * A delegate of the source writes it as its owner does.
	 *
	 * @return void
	 */
	public function testADelegateMayChangeTheValue(): void {
		$this->delegations = $this->createMock(SecretDelegationMapper::class);
		$this->delegations->method('findActiveBySecretAndUser')->willReturn(new SecretDelegation());
		$this->buildGuard();

		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testADelegateMayChangeTheValue()

	/**
	 * A member of a group the source is shared with in full writes it for
	 * every copy (sharing-group).
	 *
	 * @return void
	 */
	public function testAMemberOfAFullLiveGroupShareMayChangeTheValue(): void {
		$this->groupShares = [$this->groupShare()];

		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testAMemberOfAFullLiveGroupShareMayChangeTheValue()

	/**
	 * Group shares that give a member no write.
	 *
	 * @return array<string,array{0:bool,1:DateTime|null}>
	 */
	public static function groupSharesThatOnlyShow(): array {
		return [
			'a use-only group share' => [true, null],
			'an expired group share' => [false, new DateTime('-1 hour')],
		];
	}//end groupSharesThatOnlyShow()

	/**
	 * A member of a use-only or expired group share holds a view-only copy.
	 *
	 * @param bool $useOnly Whether the group share is use-only
	 * @param DateTime|null $expiresAt When the group share expires
	 *
	 * @return void
	 */
	#[DataProvider('groupSharesThatOnlyShow')]
	public function testAMemberOfAGroupShareThatOnlyShowsMayNotChangeTheValue(bool $useOnly, ?DateTime $expiresAt): void {
		$groupShare = $this->groupShare();
		$groupShare->setUseOnly($useOnly);
		$groupShare->setExpiresAt($expiresAt);
		$this->groupShares = [$groupShare];

		$this->expectException(ViewOnlyCopyException::class);
		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
	}//end testAMemberOfAGroupShareThatOnlyShowsMayNotChangeTheValue()

	/**
	 * A share of `src` with the group `admin`.
	 *
	 * @return GroupShare
	 */
	private function groupShare(): GroupShare {
		$groupShare = new GroupShare();
		$groupShare->setId('gs-admin');
		$groupShare->setSecretId('src');
		$groupShare->setGroupId('admin');
		return $groupShare;
	}//end groupShare()

	/**
	 * A row that is no copy is its owner's to change.
	 *
	 * @return void
	 */
	public function testARowThatIsNoCopyPasses(): void {
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->targets->method('findByRecipientSecret')->willThrowException(new DoesNotExistException(''));
		$this->buildGuard();
		$this->secrets->expects($this->never())->method('findById');

		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
		$this->addToAssertionCount(1);
	}//end testARowThatIsNoCopyPasses()

	/**
	 * A copy with more than one share row has no single source to judge it by.
	 *
	 * @return void
	 */
	public function testACopyWithTwoShareRowsIsNotFound(): void {
		$this->targets = $this->createMock(ShareTargetMapper::class);
		$this->targets->method('findByRecipientSecret')->willThrowException(new MultipleObjectsReturnedException(''));
		$this->buildGuard();

		$this->expectException(NotFoundException::class);
		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
	}//end testACopyWithTwoShareRowsIsNotFound()

	/**
	 * A copy whose source is gone is not found.
	 *
	 * @return void
	 */
	public function testACopyWhoseSourceIsGoneIsNotFound(): void {
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->willThrowException(new DoesNotExistException(''));
		$this->buildGuard();

		$this->expectException(NotFoundException::class);
		$this->guard->assertMayChangeValue(row: $this->copy(), fields: ['key'], userId: 'bob');
	}//end testACopyWhoseSourceIsGoneIsNotFound()

	/**
	 * Rebuild the guard on the current mocks.
	 *
	 * @return void
	 */
	private function buildGuard(): void {
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturnCallback(fn (): array => $this->groupShares);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $userId, string $groupId): bool => ($userId === 'bob' && $groupId === 'admin')
		);
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$auth = new ShareAuthorizationService(
			secretMapper: $this->secrets,
			delegationMapper: $this->delegations,
			suiteMapper: $suites,
			teamFolderService: $this->teamFolders,
			shareTargetMapper: $this->targets,
			groupShareMapper: $groupShares,
			groupManager: $groups,
		);
		$this->guard = new ViewOnlyCopyGuard(
			targets: $this->targets,
			secrets: $this->secrets,
			contexts: new ShareWriteContext(targets: $this->targets, suites: $suites, auth: $auth),
		);
	}//end buildGuard()
}//end class
