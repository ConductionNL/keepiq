<?php

/**
 * Keepiq app identity
 *
 * @category Kernel
 * @package  OCA\Keepiq\Kernel
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

namespace OCA\Keepiq\Kernel;

/**
 * The app id and the app-level version markers, so code that only needs
 * them does not depend on the Application bootstrap class.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class AppIdentity {

	/**
	 * The Nextcloud app id.
	 *
	 * @var string
	 */
	public const APP_ID = 'keepiq';

	/**
	 * The app version by which every pre-rename compatibility shim is gone.
	 *
	 * The doriath -> keepiq rename left a handful of published identifiers
	 * carrying the old codename: the assertion audience, the discovery path
	 * and the envelope format name. Each is accepted or announced in parallel
	 * with its replacement so no consumer needs a flag day — but only while
	 * the app is pre-stable. This app has never shipped a stable release, so
	 * there is no released contract to preserve and no reason to carry a dead
	 * codename past 1.0.0; the shims are removed before the first stable
	 * release, not deferred to a future apiVersion.
	 *
	 * `apiVersion` therefore stays at 1 throughout. The "breaking changes
	 * MUST ship as a new apiVersion" rule in the secret-store-api spec binds
	 * from the first stable release onward, which is exactly the point these
	 * shims stop existing.
	 *
	 * @var string
	 */
	public const PRE_STABLE_COMPAT_REMOVED_IN = '1.0.0';

	/**
	 * The first Keepiq release that stores the `totp` and `passkeys` value fields.
	 * A federation partner older than this cannot hold them, and is refused rather than sent a copy without them.
	 *
	 * @var string
	 */
	public const TOTP_PASSKEYS_FIELDS_SINCE = '0.3.12';
}//end class
