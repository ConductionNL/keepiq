<?php

/**
 * Keepiq UserRemovedFromGroupListener
 *
 * Listens for Nextcloud's UserRemovedEvent and revokes the group-derived
 * ShareTargets for the departing user (direct shares stay intact).
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use OCA\Keepiq\Kernel\Contracts\GroupShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\TeamFolderServiceInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * Dispatch GroupShareService::handleMemberLeave on UserRemovedEvent.
 *
 * @implements IEventListener<UserRemovedEvent>
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#8.2
 */
class UserRemovedFromGroupListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param GroupShareServiceInterface $groupShareService The group-share service
	 * @param TeamFolderServiceInterface $teamFolderService The team-folder service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private GroupShareServiceInterface $groupShareService,
		private TeamFolderServiceInterface $teamFolderService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the UserRemovedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-member-leaves-group-auto-revocation
	 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
	 */
	public function handle(Event $event): void {
		if ($event instanceof UserRemovedEvent === false) {
			return;
		}

		$relay = new GroupMembershipRelay(logger: $this->logger, event: $event);
		$relay->run(
			step: new MembershipStep(
				done: 'Keepiq: revoked {count} group-derived shares for {user} leaving {group}',
				failure: 'Keepiq: UserRemovedFromGroupListener failed: '
			),
			work: fn (string $userId, string $groupId): int => $this->groupShareService->handleMemberLeave(userId: $userId, groupId: $groupId)
		);

		// Team-folder branch (team-folder-sharing §3.2): auto-revoke the
		// departing user's folder-derived shares unless another
		// membership still covers them. Direct shares stay intact.
		$relay->run(
			step: new MembershipStep(
				done: 'Keepiq: revoked {count} team-folder-derived shares for {user} leaving {group}',
				failure: 'Keepiq: team-folder leave handling failed: '
			),
			work: fn (string $userId, string $groupId): int => $this->teamFolderService->handleGroupMemberLeave(userId: $userId, groupId: $groupId)
		);
	}//end handle()
}//end class
