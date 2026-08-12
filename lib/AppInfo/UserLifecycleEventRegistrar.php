<?php

/**
 * Doriath user-lifecycle event registrar
 *
 * Binds the listeners that keep the sharing graph and the vault in step with
 * Nextcloud's own account and group-membership churn.
 *
 * @category AppInfo
 * @package  OCA\Doriath\AppInfo
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

namespace OCA\Doriath\AppInfo;

use OCA\Doriath\Listener\UserAddedToGroupListener;
use OCA\Doriath\Listener\UserDeletedListener;
use OCA\Doriath\Listener\UserRemovedFromGroupListener;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Wires the Nextcloud account/group lifecycle listener graph.
 *
 * These three listeners are the only ones bound to events Doriath does not
 * own: they are the seam where a change made in Nextcloud's user
 * administration has to be mirrored into the vault. Grouping them keeps that
 * seam visible in one place, so a new core event is added next to the
 * existing ones rather than buried in a general listener list.
 */
final class UserLifecycleEventRegistrar {
	/**
	 * Bind the account and group-membership listeners to their core events.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// Implement-user-sharing §8 — sharing-graph reactions to group
		// membership churn.
		$context->registerEventListener(
			event: UserAddedEvent::class,
			listener: UserAddedToGroupListener::class
		);
		$context->registerEventListener(
			event: UserRemovedEvent::class,
			listener: UserRemovedFromGroupListener::class
		);

		// Secret-export-gdpr D4 — cascade-delete all of a user's Doriath data
		// when their Nextcloud account is removed, so vault data never outlives
		// its account. The cascade is idempotent and shares its implementation
		// with the in-app GDPR Art. 17 deletion flow.
		$context->registerEventListener(
			event: UserDeletedEvent::class,
			listener: UserDeletedListener::class
		);

	}//end register()
}//end class
