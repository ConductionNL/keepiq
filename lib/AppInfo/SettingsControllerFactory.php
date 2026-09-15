<?php

/**
 * Keepiq SettingsController factory
 *
 * Builds Keepiq's concrete SettingsController for the container registration
 * in {@see DomainOverrideRegistrar}. It lives in its own class so the registrar
 * keeps its coupling under the PHPMD limit now the controller also takes the
 * integriq connection reporter (adopt-connection-registry).
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Controller\SettingsController;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\SettingsService;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;

/**
 * Container factory for SettingsController.
 *
 * Every constructor argument is spelled out by name. The reporter matters most:
 * the controller's default for it is null, so a factory that forgot it would
 * still build, and the breach check refresh would stop without a sound.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 */
final class SettingsControllerFactory {

	/**
	 * Build the controller from the container.
	 *
	 * @param ContainerInterface $container The app container.
	 *
	 * @return SettingsController
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function __invoke(ContainerInterface $container): SettingsController {
		return new SettingsController(
			request: $container->get(IRequest::class),
			settingsService: $container->get(SettingsService::class),
			userSession: $container->get(IUserSession::class),
			connectionReporter: $container->get(ConnectionReporter::class),
		);
	}//end __invoke()
}//end class
