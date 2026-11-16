<?php

/**
 * Keepiq setup check registrar
 *
 * @category SetupCheck
 * @package  OCA\Keepiq\SetupCheck
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

namespace OCA\Keepiq\SetupCheck;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the setup checks.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class SetupCheckRegistrar {

	/**
	 * Register the setup checks.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// The admin overview warns while the share quarantine scan is pending.
		$context->registerSetupCheck(ShareQuarantineScanPending::class);
	}//end register()
}//end class
