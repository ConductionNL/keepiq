<?php

/**
 * Keepiq site image settings
 *
 * The two admin templates that make the browser load an image from a third
 * party: the site icon (`favicon_service_url`, placeholder `{domain}`) and
 * the site preview (`site_preview_service_url`, placeholder `{url}`). Both are
 * empty by default, and an empty template means no request is made
 * (vault-website-addresses D4). A set template must be an https address that
 * holds its placeholder.
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
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use OCA\Keepiq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * Reads and validates the site icon and site preview templates.
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
 */
class SiteImageSettings {

	/**
	 * Each template's app config key and the placeholder it must hold.
	 *
	 * @var array<string,string>
	 */
	public const PLACEHOLDERS = [
		'favicon_service_url' => '{domain}',
		'site_preview_service_url' => '{url}',
	];

	/**
	 * The longest template accepted.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 2048;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Both templates, '' when not set.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
	 */
	public function read(): array {
		$settings = [];
		foreach (array_keys(self::PLACEHOLDERS) as $key) {
			$settings[$key] = $this->appConfig->getValueString(AppIdentity::APP_ID, $key, '');
		}

		return $settings;
	}//end read()

	/**
	 * Store the templates present in the input; '' clears one. Every given
	 * template is checked before any is written.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a template is not an https address with its placeholder
	 *
	 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-site-icons-and-previews-are-opt-in-per-instance
	 */
	public function update(array $data): void {
		$valid = [];
		foreach (self::PLACEHOLDERS as $key => $placeholder) {
			if (array_key_exists($key, $data) === true) {
				$valid[$key] = self::checked(key: $key, value: $data[$key], placeholder: $placeholder);
			}
		}

		foreach ($valid as $key => $template) {
			$this->appConfig->setValueString(AppIdentity::APP_ID, $key, $template);
		}
	}//end update()

	/**
	 * One template, trimmed, or '' to clear it.
	 *
	 * @param string $key         The setting
	 * @param mixed  $value       The input
	 * @param string $placeholder The placeholder it must hold
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not an https address with its placeholder
	 */
	private static function checked(string $key, mixed $value, string $placeholder): string {
		if ($value === null) {
			return '';
		}

		if (is_string($value) === false) {
			throw new InvalidArgumentException($key . ' must be text');
		}

		$template = trim($value);
		if ($template === '') {
			return '';
		}

		if (strlen($template) > self::MAX_LENGTH || str_contains($template, $placeholder) === false) {
			throw new InvalidArgumentException($key . ' must contain ' . $placeholder . ' and be at most ' . self::MAX_LENGTH . ' characters');
		}

		$host = parse_url(str_replace($placeholder, 'x', $template), PHP_URL_HOST);
		if (str_starts_with(strtolower($template), 'https://') === false || is_string($host) === false || $host === '') {
			throw new InvalidArgumentException($key . ' must be an https address');
		}

		return $template;
	}//end checked()
}//end class
