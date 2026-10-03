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

use Closure;
use OCA\Keepiq\Mcp\KeepiqScannableServices;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers Keepiq's MCP opt-in: the IMcpScannableServices::keepiq alias that
 * tells OpenRegister's scanner which classes carry #[McpTool]. Only when
 * OpenRegister is present and enabled; otherwise nothing is registered and
 * Keepiq has no MCP surface at all.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
 */
class McpRegistrar {

	/**
	 * The alias OpenRegister enumerates, IMcpScannableServices::<appId>.
	 *
	 * @var string
	 */
	public const ALIAS = 'OCA\\OpenRegister\\Mcp\\IMcpScannableServices::keepiq';

	/**
	 * Constructor.
	 *
	 * @param Closure|null $openRegisterPresent Answers whether OpenRegister is
	 *                                          enabled; OpenRegisterAutoloader::register() when null.
	 *
	 * @return void
	 */
	public function __construct(private ?Closure $openRegisterPresent = null) {
	}//end __construct()

	/**
	 * Register the alias when OpenRegister is there.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return bool Whether the alias was registered
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) The prelude is static by design (see OpenRegisterAutoloader).
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
	 */
	public function register(IRegistrationContext $context): bool {
		$present = $this->openRegisterPresent ?? static fn (): bool => OpenRegisterAutoloader::register();
		if ($present() !== true) {
			return false;
		}

		$context->registerServiceAlias(self::ALIAS, KeepiqScannableServices::class);
		return true;
	}//end register()
}//end class
