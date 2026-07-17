<?php

/**
 * Unit tests for UserRemovedFromGroupListener.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Listener
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

namespace OCA\Doriath\Tests\Unit\Listener;

use OCA\Doriath\Listener\UserRemovedFromGroupListener;
use OCA\Doriath\Service\GroupShareService;
use OCA\Doriath\Service\TeamFolderService;
use OCP\EventDispatcher\Event;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IGroup;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for UserRemovedFromGroupListener.
 */
class UserRemovedFromGroupListenerTest extends TestCase
{
    /**
     * Test the listener dispatches to GroupShareService.handleMemberLeave.
     *
     * @return void
     */
    public function testHandleDispatchesForMatchingEvent(): void
    {
        $service     = $this->createMock(GroupShareService::class);
        $teamService = $this->createMock(TeamFolderService::class);
        $logger      = $this->createMock(LoggerInterface::class);
        $listener    = new UserRemovedFromGroupListener(
            groupShareService: $service,
            teamFolderService: $teamService,
            logger: $logger
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('bob');
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn('engineering');
        $event = new UserRemovedEvent(group: $group, user: $user);

        $service->expects($this->once())
            ->method('handleMemberLeave')
            ->with('bob', 'engineering')
            ->willReturn(2);

        // Team-folder branch fires alongside the group-share branch.
        $teamService->expects($this->once())
            ->method('handleGroupMemberLeave')
            ->with(userId: 'bob', groupId: 'engineering')
            ->willReturn(1);

        $listener->handle($event);
    }//end testHandleDispatchesForMatchingEvent()

    /**
     * Test the listener no-ops on unrelated events.
     *
     * @return void
     */
    public function testHandleIgnoresUnrelatedEvents(): void
    {
        $service     = $this->createMock(GroupShareService::class);
        $teamService = $this->createMock(TeamFolderService::class);
        $logger      = $this->createMock(LoggerInterface::class);
        $listener    = new UserRemovedFromGroupListener(
            groupShareService: $service,
            teamFolderService: $teamService,
            logger: $logger
        );

        $service->expects($this->never())->method('handleMemberLeave');
        $teamService->expects($this->never())->method('handleGroupMemberLeave');

        $listener->handle($this->createMock(Event::class));
    }//end testHandleIgnoresUnrelatedEvents()
}//end class
