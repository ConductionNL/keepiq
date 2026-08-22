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

use OCA\Keepiq\Service\GroupShareService;
use OCA\Keepiq\Service\TeamFolderService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

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
	 * @param GroupShareService $groupShareService The group-share service
	 * @param TeamFolderService $teamFolderService The team-folder service
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private GroupShareService $groupShareService,
		private TeamFolderService $teamFolderService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle the UserRemovedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		if ($event instanceof UserRemovedEvent === false) {
			return;
		}

		try {
			$count = $this->groupShareService->handleMemberLeave(
				userId: $event->getUser()->getUID(),
				groupId: $event->getGroup()->getGID()
			);
			if ($count > 0) {
				$this->logger->info(
					'Keepiq: revoked ' . $count . ' group-derived shares for '
					. $event->getUser()->getUID() . ' leaving ' . $event->getGroup()->getGID(),
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: UserRemovedFromGroupListener failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}

		// Team-folder branch (team-folder-sharing §3.2): auto-revoke the
		// departing user's folder-derived shares unless another
		// membership still covers them. Direct shares stay intact.
		try {
			$teamCount = $this->teamFolderService->handleGroupMemberLeave(
				userId: $event->getUser()->getUID(),
				groupId: $event->getGroup()->getGID()
			);
			if ($teamCount > 0) {
				$this->logger->info(
					'Keepiq: revoked ' . $teamCount . ' team-folder-derived shares for '
					. $event->getUser()->getUID() . ' leaving ' . $event->getGroup()->getGID(),
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: team-folder leave handling failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end handle()
}//end class
