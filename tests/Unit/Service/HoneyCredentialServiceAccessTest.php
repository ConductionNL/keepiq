<?php

/**
 * Unit tests for the owner-or-admin guards of HoneyCredentialService.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyAlertMapper;
use OCA\Keepiq\Db\HoneyFlag;
use OCA\Keepiq\Db\HoneyFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\HoneyCredentialService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Flag, unflag, inspect, list and acknowledge paths of the honey service.
 */
#[CoversClass(HoneyCredentialService::class)]
#[UsesClass(HoneyFlag::class)]
#[UsesClass(HoneyAlert::class)]
#[UsesClass(Secret::class)]
class HoneyCredentialServiceAccessTest extends TestCase {
	private HoneyCredentialService $service;

	private HoneyFlagMapper&MockObject $flagMapper;

	private HoneyAlertMapper&MockObject $alertMapper;

	private SecretMapper&MockObject $secretMapper;

	/**
	 * Build the service over mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->flagMapper = $this->createMock(originalClassName: HoneyFlagMapper::class);
		$this->alertMapper = $this->createMock(originalClassName: HoneyAlertMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->service = new HoneyCredentialService(
			flagMapper: $this->flagMapper,
			alertMapper: $this->alertMapper,
			secretMapper: $this->secretMapper,
		);
	}//end setUp()

	/**
	 * A flag on secret-1 owned by the given user.
	 *
	 * @param string $ownerId The flag owner
	 *
	 * @return HoneyFlag
	 */
	private function flagOf(string $ownerId): HoneyFlag {
		$flag = new HoneyFlag();
		$flag->setId('flag-1');
		$flag->setSecretId('secret-1');
		$flag->setOwnerId($ownerId);

		return $flag;
	}//end flagOf()

	/**
	 * An alert on secret-1.
	 *
	 * @return HoneyAlert
	 */
	private function alert(): HoneyAlert {
		$alert = new HoneyAlert();
		$alert->setId('alert-1');
		$alert->setSecretId('secret-1');

		return $alert;
	}//end alert()

	/**
	 * A repeated flag updates the existing row's note instead of inserting.
	 *
	 * @return void
	 */
	public function testFlagAgainUpdatesTheNote(): void {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($secret);
		$existing = $this->flagOf(ownerId: 'alice');
		$this->flagMapper->method('findBySecretId')->with('secret-1')->willReturn($existing);
		$this->flagMapper->expects($this->never())->method('insert');
		$this->flagMapper->expects($this->once())->method('update')->with($existing)->willReturnArgument(0);

		$result = $this->service->flag(secretId: 'secret-1', actorId: 'alice', isAdmin: false, note: 'moved');

		$this->assertSame($existing, $result);
		$this->assertSame('moved', $result->getNote());
	}//end testFlagAgainUpdatesTheNote()

	/**
	 * An admin may flag a secret another user owns; the flag keeps that owner.
	 *
	 * @return void
	 */
	public function testAdminFlagsAnotherUsersSecretUnderTheirOwnership(): void {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));
		$this->flagMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$flag = $this->service->flag(secretId: 'secret-1', actorId: 'root', isAdmin: true);

		$this->assertSame('alice', $flag->getOwnerId());
		$this->assertSame('root', $flag->getCreatedBy());
		$this->assertSame('secret-1', $flag->getSecretId());
		$this->assertNull($flag->getNote());
	}//end testAdminFlagsAnotherUsersSecretUnderTheirOwnership()

	/**
	 * A group-owned secret is not "owned" by a user with the same id.
	 *
	 * @return void
	 */
	public function testFlagRefusesAGroupSecretWithAMatchingId(): void {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType('group');
		$secret->setOwnerId('alice');
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->flagMapper->expects($this->never())->method('findBySecretId');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the owner or an admin may flag a honey secret');
		$this->service->flag(secretId: 'secret-1', actorId: 'alice', isAdmin: false);
	}//end testFlagRefusesAGroupSecretWithAMatchingId()

	/**
	 * The owner unflags: the flag row is deleted.
	 *
	 * @return void
	 */
	public function testOwnerUnflagDeletesTheFlag(): void {
		$flag = $this->flagOf(ownerId: 'alice');
		$this->flagMapper->method('findBySecretId')->with('secret-1')->willReturn($flag);
		$this->flagMapper->expects($this->once())->method('delete')->with($flag);

		$this->service->unflag(secretId: 'secret-1', actorId: 'alice', isAdmin: false);
	}//end testOwnerUnflagDeletesTheFlag()

	/**
	 * An admin may unflag another user's decoy.
	 *
	 * @return void
	 */
	public function testAdminUnflagsAnotherUsersFlag(): void {
		$flag = $this->flagOf(ownerId: 'alice');
		$this->flagMapper->method('findBySecretId')->willReturn($flag);
		$this->flagMapper->expects($this->once())->method('delete')->with($flag);

		$this->service->unflag(secretId: 'secret-1', actorId: 'root', isAdmin: true);
	}//end testAdminUnflagsAnotherUsersFlag()

	/**
	 * A stranger may not unflag, and nothing is deleted.
	 *
	 * @return void
	 */
	public function testStrangerUnflagIsRefused(): void {
		$this->flagMapper->method('findBySecretId')->willReturn($this->flagOf(ownerId: 'alice'));
		$this->flagMapper->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the owner or an admin may unflag a honey secret');
		$this->service->unflag(secretId: 'secret-1', actorId: 'mallory', isAdmin: false);
	}//end testStrangerUnflagIsRefused()

	/**
	 * Unflagging an unflagged secret surfaces the missing row.
	 *
	 * @return void
	 */
	public function testUnflagOfAnUnflaggedSecretThrows(): void {
		$this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));
		$this->flagMapper->expects($this->never())->method('delete');

		$this->expectException(DoesNotExistException::class);
		$this->service->unflag(secretId: 'secret-1', actorId: 'alice', isAdmin: true);
	}//end testUnflagOfAnUnflaggedSecretThrows()

	/**
	 * An unflagged secret has no flag for anyone, admin included.
	 *
	 * @return void
	 */
	public function testGetFlagOfAnUnflaggedSecretIsNull(): void {
		$this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));

		$this->assertNull($this->service->getFlag(secretId: 'secret-1', actorId: 'mallory', isAdmin: false));
	}//end testGetFlagOfAnUnflaggedSecretIsNull()

	/**
	 * Owner and admin see the flag; a stranger is refused.
	 *
	 * @return void
	 */
	public function testGetFlagIsOwnerOrAdminOnly(): void {
		$flag = $this->flagOf(ownerId: 'alice');
		$this->flagMapper->method('findBySecretId')->willReturn($flag);

		$this->assertSame($flag, $this->service->getFlag(secretId: 'secret-1', actorId: 'alice', isAdmin: false));
		$this->assertSame($flag, $this->service->getFlag(secretId: 'secret-1', actorId: 'root', isAdmin: true));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the owner or an admin may inspect a honey flag');
		$this->service->getFlag(secretId: 'secret-1', actorId: 'mallory', isAdmin: false);
	}//end testGetFlagIsOwnerOrAdminOnly()

	/**
	 * Admins list every alert, without consulting the owner's flags.
	 *
	 * @return void
	 */
	public function testAdminListsAllAlerts(): void {
		$alerts = [$this->alert()];
		$this->alertMapper->expects($this->once())->method('findAll')->willReturn($alerts);
		$this->alertMapper->expects($this->never())->method('findByFlagIds');
		$this->flagMapper->expects($this->never())->method('findByOwner');

		$this->assertSame($alerts, $this->service->listAlerts(actorId: 'root', isAdmin: true));
	}//end testAdminListsAllAlerts()

	/**
	 * An owner lists only the alerts of their own flags.
	 *
	 * @return void
	 */
	public function testOwnerListsAlertsOfTheirFlags(): void {
		$second = $this->flagOf(ownerId: 'alice');
		$second->setId('flag-2');
		$this->flagMapper->method('findByOwner')->with('alice')->willReturn([$this->flagOf(ownerId: 'alice'), $second]);
		$alerts = [$this->alert()];
		$this->alertMapper->expects($this->never())->method('findAll');
		$this->alertMapper->expects($this->once())->method('findByFlagIds')
			->with(['flag-1', 'flag-2'])
			->willReturn($alerts);

		$this->assertSame($alerts, $this->service->listAlerts(actorId: 'alice', isAdmin: false));
	}//end testOwnerListsAlertsOfTheirFlags()

	/**
	 * The decoy owner acknowledges: the alert is stamped with them.
	 *
	 * @return void
	 */
	public function testOwnerAcknowledgeStampsTheAlert(): void {
		$alert = $this->alert();
		$this->alertMapper->method('findById')->with('alert-1')->willReturn($alert);
		$this->flagMapper->method('findBySecretId')->with('secret-1')->willReturn($this->flagOf(ownerId: 'alice'));
		$this->alertMapper->expects($this->once())->method('update')->with($alert)->willReturnArgument(0);

		$result = $this->service->acknowledge(alertId: 'alert-1', actorId: 'alice', isAdmin: false);

		$this->assertSame('alice', $result->getAcknowledgedBy());
		$this->assertNotNull($result->getAcknowledgedAt());
	}//end testOwnerAcknowledgeStampsTheAlert()

	/**
	 * An admin acknowledges without the flag being consulted.
	 *
	 * @return void
	 */
	public function testAdminAcknowledgeSkipsTheFlagLookup(): void {
		$alert = $this->alert();
		$this->alertMapper->method('findById')->willReturn($alert);
		$this->flagMapper->expects($this->never())->method('findBySecretId');
		$this->alertMapper->expects($this->once())->method('update')->willReturnArgument(0);

		$this->assertSame('root', $this->service->acknowledge(alertId: 'alert-1', actorId: 'root', isAdmin: true)->getAcknowledgedBy());
	}//end testAdminAcknowledgeSkipsTheFlagLookup()

	/**
	 * Once the flag is gone, only an admin may act on the orphaned alert.
	 *
	 * @return void
	 */
	public function testOrphanedAlertIsRefusedToTheFormerOwner(): void {
		$this->alertMapper->method('findById')->willReturn($this->alert());
		$this->flagMapper->method('findBySecretId')->willThrowException(new DoesNotExistException('none'));
		$this->alertMapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Only the decoy owner or an admin may act on this alert');
		$this->service->acknowledge(alertId: 'alert-1', actorId: 'alice', isAdmin: false);
	}//end testOrphanedAlertIsRefusedToTheFormerOwner()

	/**
	 * Snoozing for zero hours still snoozes for at least one hour.
	 *
	 * @return void
	 */
	public function testSnoozeIsAtLeastOneHour(): void {
		$alert = $this->alert();
		$this->alertMapper->method('findById')->willReturn($alert);
		$this->alertMapper->method('update')->willReturnArgument(0);

		$before = time();
		$result = $this->service->snooze(alertId: 'alert-1', actorId: 'root', isAdmin: true, hours: 0);

		$until = $result->getSnoozedUntil()->getTimestamp();
		$this->assertGreaterThanOrEqual($before + 3600, $until);
		$this->assertLessThan($before + 3600 + 60, $until);
	}//end testSnoozeIsAtLeastOneHour()
}//end class
