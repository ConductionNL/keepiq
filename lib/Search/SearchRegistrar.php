<?php

/**
 * Keepiq search registrar
 *
 * @category Search
 * @package  OCA\Keepiq\Search
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

namespace OCA\Keepiq\Search;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the unified search provider.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class SearchRegistrar {

	/**
	 * Register the unified search provider for secrets.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// The Nextcloud unified search provider for secrets. It queries
		// unencrypted name/url metadata only and needs no vault session
		// (ADR-003).
		$context->registerSearchProvider(SecretSearchProvider::class);
	}//end register()
}//end class
