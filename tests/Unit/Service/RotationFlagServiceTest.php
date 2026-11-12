<?php

/**
 * Keepiq - RotationFlagService::flag tests
 *
 * Golden outcomes of raising a rotation flag: an open flag is returned as it
 * is, a resolved one is reopened with the new reason and proof point, and a
 * secret without a flag gets a new open one; both writes are audited with the
 * raising user, or the system.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\RotationFlagService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for raising a rotation flag.
 */
class RotationFlagServiceTest extends TestCase {
	/**
	 * @var RotationFlagMapper&MockObject
	 */
	private RotationFlagMapper&MockObject $flagMapper;

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * The audit events dispatched.
	 *
	 * @var list<object>
	 */
	private array $events = [];

	/**
	 * The service under test.
	 *
	 * @var RotationFlagService
	 */
	private RotationFlagService $service;

	/**
	 * Build the service over mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->flagMapper   = $this->createMock(RotationFlagMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setKeyUpdatedAt(new DateTime('2026-01-02T00:00:00Z'));
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->flagMapper->method('update')->willReturnArgument(0);
		$this->flagMapper->method('insert')->willReturnArgument(0);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->events[] = $event;
		});
		$this->service = new RotationFlagService(
			flagMapper: $this->flagMapper,
			secretMapper: $this->secretMapper,
			eventDispatcher: $dispatcher,
		);
	}//end setUp()

	/**
	 * A resolved flag is reopened with the new reason, by the raising user.
	 *
	 * @return void
	 */
	public function testAResolvedFlagIsReopened(): void {
		$existing = new RotationFlag();
		$existing->setId('flag-1');
		$existing->setSecretId('sec-1');
		$existing->setStatus('resolved');
		$existing->setReason('policy_expiry');
		$existing->setResolvedAt(new DateTime('2026-01-01'));
		$this->flagMapper->method('findBySecret')->willReturn($existing);
		$this->flagMapper->expects($this->once())->method('update');
		$this->flagMapper->expects($this->never())->method('insert');

		$flag = $this->service->flag(secretId: 'sec-1', reason: 'user_flagged', flaggedBy: 'alice');

		$this->assertSame('flag-1', $flag->getId());
		$this->assertSame(['user_flagged', 'open', 'alice', null], [$flag->getReason(), $flag->getStatus(), $flag->getFlaggedBy(), $flag->getResolvedAt()]);
		$this->assertNotNull($flag->getFlaggedAt());
		$this->assertEquals(new DateTime('2026-01-02T00:00:00Z'), $flag->getKeyUpdatedAtAtFlag());
		$this->assertCount(1, $this->events);
		$this->assertSame(
			['alice', AuditEventTypes::SECRET_ROTATION_FLAGGED, 'secret', 'sec-1', ['reason' => 'user_flagged']],
			[$this->events[0]->getActorId(), $this->events[0]->getEventType(), $this->events[0]->getObjectType(), $this->events[0]->getObjectId(), $this->events[0]->getMetadata()]
		);
	}//end testAResolvedFlagIsReopened()

	/**
	 * A secret without a flag gets a new open one, raised by the system.
	 *
	 * @return void
	 */
	public function testANewFlagIsRaisedByTheSystem(): void {
		$this->flagMapper->method('findBySecret')->willThrowException(new DoesNotExistException('none'));
		$this->flagMapper->expects($this->once())->method('insert');

		$flag = $this->service->flag(secretId: 'sec-1', reason: 'policy_expiry');

		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$flag->getId());
		$this->assertSame(['sec-1', 'policy_expiry', 'open', null], [$flag->getSecretId(), $flag->getReason(), $flag->getStatus(), $flag->getFlaggedBy()]);
		$this->assertNotNull($flag->getFlaggedAt());
		$this->assertEquals(new DateTime('2026-01-02T00:00:00Z'), $flag->getKeyUpdatedAtAtFlag());
		$this->assertSame('system', $this->events[0]->getActorId());
		$this->assertSame(['reason' => 'policy_expiry'], $this->events[0]->getMetadata());
	}//end testANewFlagIsRaisedByTheSystem()
}//end class
