<?php

/**
 * Keepiq JWK Factory Adapter
 *
 * A thin injectable seam over web-token/jwt-library's JWKFactory, whose key
 * construction entry points are static factory methods with no instance API
 * (vendor/web-token/jwt-library/KeyManagement/JWKFactory.php declares
 * `public static function createFromKey()` and siblings; the class is never
 * instantiated).
 *
 * Wrapping it keeps JwtAuthService free of a hard-wired static call and lets
 * a test substitute the JWK construction step of the assertion-exchange path.
 *
 * @category Support
 * @package  OCA\Keepiq\Support
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

namespace OCA\Keepiq\Support;

use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Builds JWKs through instance methods.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The delegation below is the ONE place
 * in the app that reaches Jose\Component\KeyManagement\JWKFactory. The library
 * exposes JWK construction exclusively as static factory methods — there is no
 * instance API to call and nothing to construct — so the static access cannot
 * be removed, only confined to a documented, injectable adapter.
 */
class JwkFactoryAdapter {
	/**
	 * Build a JWK from a PEM-encoded key.
	 *
	 * @param string $key The PEM-encoded key
	 *
	 * @return JWK
	 */
	public function createFromKey(string $key): JWK {
		return JWKFactory::createFromKey($key);
	}//end createFromKey()
}//end class
