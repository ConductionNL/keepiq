<?php

/**
 * Keepiq Preferences Controller
 *
 * Per-user UI preferences, such as the walkthrough's completed version, for
 * the shared `@conduction/nextcloud-vue` shell. A port of the contract
 * OpenRegister's AppHost served for Keepiq: the same URL, response shape,
 * key normalisation and storage key, so values stored before ADR-006 are
 * read back unchanged.
 *
 * Values are UI state stored in plain text. They are never used for secret
 * material.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use OCA\Keepiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Serves GET and PUT /api/preferences/{key}.
 *
 * @spec openspec/specs/app-shell/spec.md#requirement-per-user-preferences-endpoint
 */
class PreferencesController extends Controller {
	/**
	 * Prefix of the stored user-value key.
	 *
	 * @var string
	 */
	private const KEY_PREFIX = 'pref_';

	/**
	 * Maximum length of a normalised key.
	 *
	 * @var int
	 */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * Constructor for PreferencesController.
	 *
	 * @param IRequest     $request     The HTTP request
	 * @param IConfig      $config      The config (user values)
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read one of the caller's preferences.
	 *
	 * @param string $key The preference key, normalised before use
	 *
	 * @return JSONResponse `{value: string|null}`, 400 on an invalid key
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-per-user-preferences-endpoint
	 */
	#[NoAdminRequired]
	public function getPreference(string $key): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->normaliseKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$value = $this->config->getUserValue($user->getUID(), Application::APP_ID, self::KEY_PREFIX . $safeKey, '');
		if ($value === '') {
			return new JSONResponse(data: ['value' => null]);
		}

		return new JSONResponse(data: ['value' => $value]);
	}//end getPreference()

	/**
	 * Store, or with an empty value delete, one of the caller's preferences.
	 *
	 * @param string $key   The preference key, normalised before use
	 * @param string $value The value; empty deletes the preference
	 *
	 * @return JSONResponse `{value: string|null}`, 400 on an invalid key
	 *
	 * @spec openspec/specs/app-shell/spec.md#requirement-per-user-preferences-endpoint
	 */
	#[NoAdminRequired]
	public function setPreference(string $key, string $value = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$safeKey = $this->normaliseKey(key: $key);
		if ($safeKey === '') {
			return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if ($value === '') {
			$this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::KEY_PREFIX . $safeKey);
			return new JSONResponse(data: ['value' => null]);
		}

		$this->config->setUserValue($user->getUID(), Application::APP_ID, self::KEY_PREFIX . $safeKey, $value);
		return new JSONResponse(data: ['value' => $value]);
	}//end setPreference()

	/**
	 * Lower-case the key, keep only `[a-z0-9-]` and cut it to 64 characters.
	 *
	 * The same rule the AppHost controller applied, so
	 * `walkthrough_completed_version` keeps mapping to the stored key
	 * `pref_walkthroughcompletedversion`.
	 *
	 * @param string $key The raw key
	 *
	 * @return string The normalised key, empty when nothing valid is left
	 */
	private function normaliseKey(string $key): string {
		$safe = (string)preg_replace('/[^a-z0-9-]/', '', strtolower($key));
		return substr($safe, 0, self::MAX_KEY_LENGTH);
	}//end normaliseKey()
}//end class
