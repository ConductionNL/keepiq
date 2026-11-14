<?php

/**
 * Unit tests for the owner-scoped paths of RotationFlagService (dismiss, batch flag, head proof).
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
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\CredentialFields;
use OCA\Keepiq\Service\RotationFlagService;
use OCA\Keepiq\Service\RotationRaise;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Dismissal is owner-scoped, audited, and refuses everyone else alike.
 */
#[CoversClass(RotationFlagService::class)]
#[UsesClass(RotationFlag::class)]
#[UsesClass(Secret::class)]
#[UsesClass(AuditEvent::class)]
#[UsesClass(AuditEventFactory::class)]
#[UsesClass(CredentialFields::class)]
#[UsesClass(RotationRaise::class)]
class RotationFlagServiceOwnershipTest extends TestCase {
	private RotationFlagMapper&MockObject $flagMapper;

	private SecretMapper&MockObject $secretMapper;

	private IEventDispatcher&MockObject $dispatcher;

	private RotationFlagService $service;

	/**
	 * Wire the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->flagMapper = $this->createMock(originalClassName: RotationFlagMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->dispatcher = $this->createMock(originalClassName: IEventDispatcher::class);
		$this->service = new RotationFlagService(
			flagMapper: $this->flagMapper,
			secretMapper: $this->secretMapper,
			eventDispatcher: $this->dispatcher,
		);
	}//end setUp()

	/**
	 * An open flag on secret-1.
	 *
	 * @return RotationFlag
	 */
	private function openFlag(): RotationFlag {
		$flag = new RotationFlag();
		$flag->setId('flag-1');
		$flag->setSecretId('secret-1');
		$flag->setReason('user_flagged');
		$flag->setStatus('open');

		return $flag;
	}//end openFlag()

	/**
	 * A secret with the given owner.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner id
	 *
	 * @return Secret
	 */
	private function secretOf(string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);

		return $secret;
	}//end secretOf()

	/**
	 * The owner dismisses: status, resolution stamp and an audit row with the reason.
	 *
	 * @return void
	 */
	public function testOwnerDismissResolvesAndAudits(): void {
		$flag = $this->openFlag();
		$this->flagMapper->method('findById')->with('flag-1')->willReturn($flag);
		$this->secretMapper->method('findById')->with('secret-1')->willReturn($this->secretOf(ownerType: 'user', ownerId: 'alice'));
		$this->flagMapper->expects($this->once())->method('update')->with($flag)->willReturnArgument(0);
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->with(
			$this->callback(static function (AuditEvent $event): bool {
				return $event->getEventType() === AuditEventTypes::SECRET_ROTATION_DISMISSED
					&& $event->getActorType() === AuditEvent::ACTOR_USER
					&& $event->getActorId() === 'alice'
					&& $event->getObjectType() === 'secret'
					&& $event->getObjectId() === 'secret-1'
					&& $event->getMetadata() === ['reason' => 'user_flagged'];
			})
		);

		$this->service->dismiss(flagId: 'flag-1', userId: 'alice');

		$this->assertSame('dismissed', $flag->getStatus());
		$this->assertNotNull($flag->getResolvedAt());
	}//end testOwnerDismissResolvesAndAudits()

	/**
	 * A missing flag, a missing secret, a stranger and a same-id group all read "Flag not found".
	 *
	 * @return array<string,array{bool,bool,string,string}>
	 */
	public static function refusedDismissals(): array {
		return [
			'flag missing' => [false, true, 'user', 'alice'],
			'secret missing' => [true, false, 'user', 'alice'],
			'another user' => [true, true, 'user', 'bob'],
			'group secret with same id' => [true, true, 'group', 'alice'],
		];
	}//end refusedDismissals()

	/**
	 * Every refusal is indistinguishable and changes nothing.
	 *
	 * @param bool $flagExists Whether the flag row exists
	 * @param bool $secretExists Whether the secret exists
	 * @param string $ownerType The secret's owner type
	 * @param string $ownerId The secret's owner id
	 *
	 * @return void
	 *
	 * @dataProvider refusedDismissals
	 */
	public function testDismissRefusalsAreIndistinguishable(bool $flagExists, bool $secretExists, string $ownerType, string $ownerId): void {
		if ($flagExists === true) {
			$this->flagMapper->method('findById')->willReturn($this->openFlag());
		} else {
			$this->flagMapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		}

		if ($secretExists === true) {
			$this->secretMapper->method('findById')->willReturn($this->secretOf(ownerType: $ownerType, ownerId: $ownerId));
		} else {
			$this->secretMapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		}

		$this->flagMapper->expects($this->never())->method('update');
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Flag not found');
		$this->service->dismiss(flagId: 'flag-1', userId: 'alice');
	}//end testDismissRefusalsAreIndistinguishable()

	/**
	 * Open flags are listed for the owner straight from the mapper.
	 *
	 * @return void
	 */
	public function testOpenFlagsComeFromTheOwnersRows(): void {
		$flags = [$this->openFlag()];
		$this->flagMapper->expects($this->once())->method('findOpenForOwner')->with('alice')->willReturn($flags);

		$this->assertSame($flags, $this->service->openFlags(userId: 'alice'));
	}//end testOpenFlagsComeFromTheOwnersRows()

	/**
	 * Deleting a secret's flags delegates to the mapper.
	 *
	 * @return void
	 */
	public function testDeleteForSecretDelegates(): void {
		$this->flagMapper->expects($this->once())->method('deleteBySecret')->with('secret-1');

		$this->service->deleteForSecret(secretId: 'secret-1');
	}//end testDeleteForSecretDelegates()

	/**
	 * A flag raised for a secret that vanished freezes no proof point.
	 *
	 * @return void
	 */
	public function testNewFlagOnAMissingSecretFreezesNoProofPoint(): void {
		$this->flagMapper->method('findBySecret')->willThrowException(new DoesNotExistException('none'));
		$this->secretMapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		$this->flagMapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$flag = $this->service->flag(secretId: 'secret-1', reason: 'user_flagged', flaggedBy: 'alice');

		$this->assertNull($flag->getKeyUpdatedAtAtFlag());
		$this->assertSame('open', $flag->getStatus());
		$this->assertSame('alice', $flag->getFlaggedBy());
	}//end testNewFlagOnAMissingSecretFreezesNoProofPoint()

	/**
	 * An already-open flag is returned as is, without an audit row.
	 *
	 * @return void
	 */
	public function testAnOpenFlagIsNotRaisedAgain(): void {
		$flag = $this->openFlag();
		$flag->setFlaggedAt(new DateTime('2026-01-01'));
		$this->flagMapper->method('findBySecret')->willReturn($flag);
		$this->flagMapper->expects($this->never())->method('update');
		$this->flagMapper->expects($this->never())->method('insert');
		$this->dispatcher->expects($this->never())->method('dispatchTyped');

		$this->assertSame($flag, $this->service->flag(secretId: 'secret-1', reason: 'suite_compromise'));
		$this->assertSame('user_flagged', $flag->getReason());
	}//end testAnOpenFlagIsNotRaisedAgain()
}//end class
