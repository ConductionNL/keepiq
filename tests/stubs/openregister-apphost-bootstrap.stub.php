<?php

/**
 * Analysis-only stub for OpenRegister's AppHost entry point.
 *
 * @category Stub
 * @package  OCA\Keepiq\Tests\Stubs
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\AppHost;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Declaration-only stub for `OCA\OpenRegister\AppHost\Bootstrap`.
 *
 * The real class lives in the openregister sibling app (ADR-040). Static
 * analysis needs its signature because Application::register() calls it inside
 * a closure handed to OpenRegisterAutoloader::bootstrapAppHost(), which checks
 * at runtime that the class is loadable. This stub is never loaded at runtime or
 * by the unit tests.
 */
class Bootstrap {
	/**
	 * Wire the AppHost plumbing for a leaf app.
	 *
	 * @param IRegistrationContext $context The registration context
	 * @param string               $appId   The leaf app id
	 * @param array<string,mixed>  $options Wiring options, e.g. 'namespace'
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context, string $appId, array $options = []): void {
	}//end register()
}//end class
