<?php

/**
 * Keepiq Trusted Extension Origins
 *
 * The browser-extension origins whose WebAuthn ceremonies the server
 * accepts: the origins of Keepiq's official store builds, pinned here, plus
 * the exact origins an admin added. An admin can switch extension passkeys
 * off. Every read fails closed: switched off, or an admin value that does
 * not parse, refuses every extension ceremony, pinned origins included.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCP\IAppConfig;

/**
 * Reads, validates and checks the trusted extension origins.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
 */
class TrustedExtensionOrigins {
	/**
	 * The origins of Keepiq's published store builds (Chrome Web Store,
	 * Edge Add-ons, and the Firefox origin of `keepiq@conduction.nl`), each
	 * as the exact origin string the browser reports. Empty until the
	 * store listings are published; until then only admin-added origins pass.
	 *
	 * @var string[]
	 */
	public const PINNED_STORE_ORIGINS = [];

	/**
	 * The app config switch for extension passkeys (default on).
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'extension_passkeys_enabled';

	/**
	 * The app config key of the admin-added origins (a JSON list).
	 *
	 * @var string
	 */
	public const ORIGINS_KEY = 'extension_passkey_origins';

	/**
	 * An exact Chromium or Firefox extension origin, nothing before or after.
	 *
	 * @var string
	 */
	private const ORIGIN_PATTERN = '/^(chrome-extension:\/\/[a-p]{32}|moz-extension:\/\/[a-p]{64})$/D';

	/**
	 * Constructor for TrustedExtensionOrigins.
	 *
	 * @param IAppConfig $appConfig The app config
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Refuse unless the origin is trusted now.
	 *
	 * @param string $origin The client data origin
	 *
	 * @return void
	 *
	 * @throws PasskeyVerificationException When switched off, unreadable or not trusted
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-passkeys-fail-closed
	 */
	public function assertTrusted(string $origin): void {
		if (in_array($origin, $this->trusted(), true) === false) {
			throw new PasskeyVerificationException(message: 'The extension origin is not trusted');
		}
	}//end assertTrusted()

	/**
	 * The trusted origins: the pinned list plus the admin list.
	 *
	 * @return string[]
	 *
	 * @throws PasskeyVerificationException When switched off or the admin value does not parse
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-passkeys-fail-closed
	 */
	public function trusted(): array {
		if ($this->isEnabled() === false) {
			throw new PasskeyVerificationException(message: 'Extension passkeys are switched off');
		}

		try {
			$added = $this->parse(value: $this->appConfig->getValueString(Application::APP_ID, self::ORIGINS_KEY, '[]'));
		} catch (InvalidArgumentException $exception) {
			throw new PasskeyVerificationException(message: 'The trusted extension origins setting is unreadable', code: 0, previous: $exception);
		}

		return array_values(array_unique(array_merge(self::PINNED_STORE_ORIGINS, $added)));
	}//end trusted()

	/**
	 * Whether extension passkeys are switched on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-passkeys-fail-closed
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::ENABLED_KEY, true);
	}//end isEnabled()

	/**
	 * The admin-added origins as stored, for the settings view; an
	 * unreadable value reads as none.
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
	 */
	public function adminOrigins(): array {
		try {
			return $this->parse(value: $this->appConfig->getValueString(Application::APP_ID, self::ORIGINS_KEY, '[]'));
		} catch (InvalidArgumentException) {
			return [];
		}
	}//end adminOrigins()

	/**
	 * Validate admin entries: each must be one exact extension origin.
	 *
	 * @param mixed $entries The submitted list
	 *
	 * @return string[] The entries, deduplicated
	 *
	 * @throws InvalidArgumentException Naming the first invalid entry
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
	 */
	public function validate(mixed $entries): array {
		if (is_array($entries) === false || array_is_list($entries) === false) {
			throw new InvalidArgumentException(self::ORIGINS_KEY . ' must be a list of extension origins');
		}

		foreach ($entries as $entry) {
			if (is_string($entry) === false) {
				throw new InvalidArgumentException('Not an exact extension origin: ' . gettype($entry));
			}

			if (preg_match(self::ORIGIN_PATTERN, $entry) !== 1) {
				throw new InvalidArgumentException('Not an exact extension origin: ' . $entry);
			}
		}

		return array_values(array_unique($entries));
	}//end validate()

	/**
	 * Parse a stored JSON list and validate every entry.
	 *
	 * @param string $value The stored value
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When the value or an entry is invalid
	 */
	private function parse(string $value): array {
		try {
			$decoded = json_decode($value, true, 4, JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new InvalidArgumentException(self::ORIGINS_KEY . ' is not JSON', 0, $exception);
		}

		return $this->validate(entries: $decoded);
	}//end parse()
}//end class
