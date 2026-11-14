<?php

/**
 * Keepiq notification registrar
 *
 * @category Notification
 * @package  OCA\Keepiq\Notification
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/app-shell/spec.md
 */

declare(strict_types=1);

namespace OCA\Keepiq\Notification;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the notifier.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class NotificationRegistrar {

	/**
	 * Register the notifier.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// The notifier responsible for rendering sharing, secret-request and
		// application-management notification subjects.
		$context->registerNotifierService(KeepiqNotifier::class);
	}//end register()
}//end class
