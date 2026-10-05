<?php

/**
 * Keepiq MCP registrar
 *
 * @category AppInfo
 * @package  OCA\Keepiq\AppInfo
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

namespace OCA\Keepiq\AppInfo;

use OCA\Keepiq\Mcp\KeepiqScannableServices;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers Keepiq's MCP opt-in: the IMcpScannableServices::keepiq alias that
 * tells OpenRegister's scanner which classes carry #[McpTool].
 *
 * The alias is registered on every instance and is inert without
 * OpenRegister. Both arguments are strings, so registering it autoloads
 * nothing: `KeepiqScannableServices` (which implements an OpenRegister
 * interface) and the tool classes load only when OpenRegister's scanner
 * resolves the alias, which can only happen while OpenRegister runs and its
 * own autoload prefix is registered. Without OpenRegister nobody asks, and
 * Keepiq has no MCP surface (ADR-006).
 *
 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
 */
class McpRegistrar {

	/**
	 * The alias OpenRegister enumerates, IMcpScannableServices::<appId>.
	 *
	 * @var string
	 */
	public const ALIAS = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::keepiq';

	/**
	 * Register the inert scannable-services alias.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(self::ALIAS, KeepiqScannableServices::class);
	}//end register()
}//end class
