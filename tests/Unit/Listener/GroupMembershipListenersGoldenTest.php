<?php

/**
 * Golden outcomes of the two group-membership listeners.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Listener\UserAddedToGroupListener;
use OCA\Keepiq\Listener\UserRemovedFromGroupListener;
use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins, per listener and per outcome of its two steps, which services are
 * called with which ids and what is logged, in order.
 *
 * @covers \OCA\Keepiq\Listener\UserAddedToGroupListener
 * @covers \OCA\Keepiq\Listener\UserRemovedFromGroupListener
 */
final class GroupMembershipListenersGoldenTest extends TestCase {
	/**
	 * Every service and logger call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The scenarios: the share step's and the team-folder step's result, an
	 * int count or 'throw'.
	 *
	 * @return array<string,array{0:int|string,1:int|string}>
	 */
	public static function outcomes(): array {
		return [
			'both counted'          => [2, 3],
			'nothing to do'         => [0, 0],
			'share step throws'     => ['throw', 1],
			'team step throws'      => [1, 'throw'],
			'both throw'            => ['throw', 'throw'],
		];
	}//end outcomes()

	/**
	 * A user joining a group.
	 *
	 * @param int|string $share The share step's result
	 * @param int|string $team  The team-folder step's result
	 *
	 * @dataProvider outcomes
	 *
	 * @return void
	 */
	public function testUserAdded(int|string $share, int|string $team): void {
		$listener = new UserAddedToGroupListener(
			$this->groupShares('handleNewGroupMember', $share),
			$this->teamFolders('handleGroupMemberJoin', $team),
			$this->logger()
		);

		$listener->handle(new UserAddedEvent($this->group(), $this->user()));

		self::assertSame(self::expected('added', $share, $team), $this->calls);
	}//end testUserAdded()

	/**
	 * A user leaving a group.
	 *
	 * @param int|string $share The share step's result
	 * @param int|string $team  The team-folder step's result
	 *
	 * @dataProvider outcomes
	 *
	 * @return void
	 */
	public function testUserRemoved(int|string $share, int|string $team): void {
		$listener = new UserRemovedFromGroupListener(
			$this->groupShares('handleMemberLeave', $share),
			$this->teamFolders('handleGroupMemberLeave', $team),
			$this->logger()
		);

		$listener->handle(new UserRemovedEvent($this->group(), $this->user()));

		self::assertSame(self::expected('removed', $share, $team), $this->calls);
	}//end testUserRemoved()

	/**
	 * Any other event touches nothing.
	 *
	 * @return void
	 */
	public function testOtherEventsAreIgnored(): void {
		foreach (['added', 'removed'] as $kind) {
			$this->calls = [];
			if ($kind === 'added') {
				$listener = new UserAddedToGroupListener($this->groupShares('handleNewGroupMember', 1), $this->teamFolders('handleGroupMemberJoin', 1), $this->logger());
				$listener->handle(new UserRemovedEvent($this->group(), $this->user()));
			} else {
				$listener = new UserRemovedFromGroupListener($this->groupShares('handleMemberLeave', 1), $this->teamFolders('handleGroupMemberLeave', 1), $this->logger());
				$listener->handle(new UserAddedEvent($this->group(), $this->user()));
			}

			$listener->handle(new Event());
			self::assertSame([], $this->calls, $kind);
		}
	}//end testOtherEventsAreIgnored()

	/**
	 * The expected calls for one listener and outcome.
	 *
	 * @param string     $kind  `added` or `removed`
	 * @param int|string $share The share step's result
	 * @param int|string $team  The team-folder step's result
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private static function expected(string $kind, int|string $share, int|string $team): array {
		$app = ['app' => 'keepiq'];
		if ($kind === 'added') {
			$calls = [['handleNewGroupMember', 'alice', 'staff']];
			$calls[] = match (true) {
				$share === 'throw' => ['warning', 'Keepiq: UserAddedToGroupListener failed: share boom', $app],
				$share > 0 => ['info', 'Keepiq: dispatched ' . $share . ' new-member notifications for alice joining staff', $app],
				default => null,
			};
			$calls[] = ['handleGroupMemberJoin', 'alice', 'staff'];
			$calls[] = match (true) {
				$team === 'throw' => ['warning', 'Keepiq: team-folder join handling failed: team boom', $app],
				$team > 0 => ['info', 'Keepiq: dispatched ' . $team . ' team-folder join requests for alice joining staff', $app],
				default => null,
			};
			return array_values(array_filter($calls));
		}

		$calls = [['handleMemberLeave', 'alice', 'staff']];
		$calls[] = match (true) {
			$share === 'throw' => ['warning', 'Keepiq: UserRemovedFromGroupListener failed: share boom', $app],
			$share > 0 => ['info', 'Keepiq: revoked ' . $share . ' group-derived shares for alice leaving staff', $app],
			default => null,
		};
		$calls[] = ['handleGroupMemberLeave', 'alice', 'staff'];
		$calls[] = match (true) {
			$team === 'throw' => ['warning', 'Keepiq: team-folder leave handling failed: team boom', $app],
			$team > 0 => ['info', 'Keepiq: revoked ' . $team . ' team-folder-derived shares for alice leaving staff', $app],
			default => null,
		};
		return array_values(array_filter($calls));
	}//end expected()

	/**
	 * A group-share service whose one method records and answers.
	 *
	 * @param string     $method The method the listener calls
	 * @param int|string $result A count, or 'throw'
	 *
	 * @return GroupShareService
	 */
	private function groupShares(string $method, int|string $result): GroupShareService {
		$service = $this->createMock(GroupShareService::class);
		$service->method($method)->willReturnCallback($this->answer($method, $result, 'share boom'));
		return $service;
	}//end groupShares()

	/**
	 * A team-folder service whose one method records and answers.
	 *
	 * @param string     $method The method the listener calls
	 * @param int|string $result A count, or 'throw'
	 *
	 * @return TeamFolderService
	 */
	private function teamFolders(string $method, int|string $result): TeamFolderService {
		$service = $this->createMock(TeamFolderService::class);
		$service->method($method)->willReturnCallback($this->answer($method, $result, 'team boom'));
		return $service;
	}//end teamFolders()

	/**
	 * A recording answer.
	 *
	 * @param string     $method  The method name
	 * @param int|string $result  A count, or 'throw'
	 * @param string     $failure The exception message on 'throw'
	 *
	 * @return \Closure
	 */
	private function answer(string $method, int|string $result, string $failure): \Closure {
		return function (string $userId, string $groupId) use ($method, $result, $failure): int {
			$this->calls[] = [$method, $userId, $groupId];
			if ($result === 'throw') {
				throw new RuntimeException($failure);
			}

			return $result;
		};
	}//end answer()

	/**
	 * A logger recording info and warning.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->calls[] = [$level, $message, $context];
			});
		}

		return $logger;
	}//end logger()

	/**
	 * The joining or leaving user.
	 *
	 * @return IUser
	 */
	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;
	}//end user()

	/**
	 * The group.
	 *
	 * @return IGroup
	 */
	private function group(): IGroup {
		$group = $this->createMock(IGroup::class);
		$group->method('getGID')->willReturn('staff');
		return $group;
	}//end group()
}//end class
