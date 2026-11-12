<?php

/**
 * Keepiq Group Membership Relay
 *
 * Runs the steps of one group-membership event and logs each.
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use Closure;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs each step of one user joining or leaving a group with that user's
 * and group's ids, logs the count of a step that acted and logs a step that
 * failed, so one failing step never stops the next.
 *
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
 */
final class GroupMembershipRelay {
	/**
	 * Constructor for GroupMembershipRelay.
	 *
	 * @param LoggerInterface                  $logger The logger
	 * @param UserAddedEvent|UserRemovedEvent $event  The membership event
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly UserAddedEvent|UserRemovedEvent $event,
	) {
	}//end __construct()

	/**
	 * Run one step and log its outcome.
	 *
	 * @param MembershipStep $step What the step logs
	 * @param Closure        $work fn (string $userId, string $groupId): int, the count it acted on
	 *
	 * @return void
	 *
	 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-membership-propagation-with-group-membership
	 */
	public function run(MembershipStep $step, Closure $work): void {
		try {
			$count = $work($this->event->getUser()->getUID(), $this->event->getGroup()->getGID());
			if ($count > 0) {
				$this->logger->info($this->doneLine(step: $step, count: $count), ['app' => 'keepiq']);
			}
		} catch (Throwable $exception) {
			$this->logger->warning($step->failure . $exception->getMessage(), ['app' => 'keepiq']);
		}
	}//end run()

	/**
	 * The info line of a step that acted.
	 *
	 * @param MembershipStep $step  What the step logs
	 * @param int            $count The count it acted on
	 *
	 * @return string
	 */
	private function doneLine(MembershipStep $step, int $count): string {
		return strtr(
			$step->done,
			[
				'{count}' => (string)$count,
				'{user}'  => $this->event->getUser()->getUID(),
				'{group}' => $this->event->getGroup()->getGID(),
			]
		);
	}//end doneLine()
}//end class
