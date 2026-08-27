<?php

/**
 * Keepiq UserAddedToGroupListener
 *
 * Listens for Nextcloud's UserAddedEvent and, for every secret already
 * shared with the joined group, notifies the secret owner that a new
 * member has appeared so the owner can approve the share.
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
use OCP\Group\Events\UserAddedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatch GroupShareService::handleNewGroupMember on UserAddedEvent.
 *
 * @implements IEventListener<UserAddedEvent>
 *
 * @spec openspec/changes/implement-user-sharing/tasks.md#8.1
 */
class UserAddedToGroupListener implements IEventListener {
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
	 * Handle the UserAddedEvent.
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-new-group-member-owner-notification
	 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
	 */
	public function handle(Event $event): void {
		if ($event instanceof UserAddedEvent === false) {
			return;
		}

		try {
			$count = $this->groupShareService->handleNewGroupMember(
				userId: $event->getUser()->getUID(),
				groupId: $event->getGroup()->getGID()
			);
			if ($count > 0) {
				$this->logger->info(
					'Keepiq: dispatched ' . $count . ' new-member notifications for '
					. $event->getUser()->getUID() . ' joining ' . $event->getGroup()->getGID(),
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: UserAddedToGroupListener failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}

		// Team-folder branch (team-folder-sharing §3.1): notify each
		// affected team-folder owner so they can approve the join.
		try {
			$joinCount = $this->teamFolderService->handleGroupMemberJoin(
				userId: $event->getUser()->getUID(),
				groupId: $event->getGroup()->getGID()
			);
			if ($joinCount > 0) {
				$this->logger->info(
					'Keepiq: dispatched ' . $joinCount . ' team-folder join requests for '
					. $event->getUser()->getUID() . ' joining ' . $event->getGroup()->getGID(),
					['app' => 'keepiq']
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: team-folder join handling failed: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
		}
	}//end handle()
}//end class
