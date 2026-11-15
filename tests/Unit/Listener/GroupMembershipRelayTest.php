<?php

/**
 * Unit tests for GroupMembershipRelay: the done line, a quiet zero and a logged failure.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Listener\GroupMembershipRelay;
use OCA\Keepiq\Listener\MembershipStep;
use OCP\Group\Events\UserAddedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One membership step: what it is handed and what it logs.
 *
 * @covers \OCA\Keepiq\Listener\GroupMembershipRelay
 * @uses   \OCA\Keepiq\Listener\MembershipStep
 */
class GroupMembershipRelayTest extends TestCase {

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A relay for `ann` joining `finance`.
	 *
	 * @return GroupMembershipRelay
	 */
	private function relay(): GroupMembershipRelay {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('ann');
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('finance');
		$event = $this->createMock(UserAddedEvent::class);
		$event->method('getUser')->willReturn($user);
		$event->method('getGroup')->willReturn($group);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}

		return new GroupMembershipRelay(logger: $logger, event: $event);
	}//end relay()

	/**
	 * The step's work gets the user and group, and its count fills the done line.
	 *
	 * @return void
	 */
	public function testTheWorkGetsUserAndGroupAndItsCountIsLogged(): void {
		$seen = [];
		$this->relay()->run(
			step: new MembershipStep(done: '{count} for {user} in {group}', failure: 'failed: '),
			work: static function (string $userId, string $groupId) use (&$seen): int {
				$seen = [$userId, $groupId];

				return 3;
			}
		);

		self::assertSame(['ann', 'finance'], $seen);
		self::assertSame([['info', '3 for ann in finance', ['app' => 'keepiq']]], $this->logged);
	}//end testTheWorkGetsUserAndGroupAndItsCountIsLogged()

	/**
	 * A step that acted on nothing logs nothing.
	 *
	 * @return void
	 */
	public function testAZeroCountLogsNothing(): void {
		$this->relay()->run(step: new MembershipStep(done: '{count}', failure: 'failed: '), work: static fn (): int => 0);

		self::assertSame([], $this->logged);
	}//end testAZeroCountLogsNothing()

	/**
	 * A failing step is logged as a warning with its message and does not throw.
	 *
	 * @return void
	 */
	public function testAFailingStepIsLoggedAndSwallowed(): void {
		$this->relay()->run(
			step: new MembershipStep(done: '{count}', failure: 'Keepiq: step failed: '),
			work: static fn (): int => throw new RuntimeException('share table locked')
		);

		self::assertSame([['warning', 'Keepiq: step failed: share table locked', ['app' => 'keepiq']]], $this->logged);
	}//end testAFailingStepIsLoggedAndSwallowed()
}//end class
