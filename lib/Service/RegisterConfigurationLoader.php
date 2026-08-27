<?php

/**
 * Keepiq Register Configuration Loader
 *
 * Reads `lib/Settings/keepiq_register.json`, deep-merges every modular
 * fragment from `lib/Settings/register.d/*.json` (ADR-037), and hands the
 * result to OpenRegister's `ConfigurationService::importFromApp()` using
 * the ADR-022 4-arg signature.
 *
 * Extracted from SettingsService: this is file I/O plus a cross-app
 * import, and shares nothing with reading or writing a settings key.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Loads and imports the bundled OpenRegister register configuration.
 */
class RegisterConfigurationLoader {
	/**
	 * Constructor for the RegisterConfigurationLoader.
	 *
	 * @param IAppManager $appManager The app manager (OpenRegister presence)
	 * @param ContainerInterface $container The container (OpenRegister ConfigurationService)
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the load behaviour carries the spec anchors.
	 */
	public function __construct(
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load configuration from keepiq_register.json via OpenRegister.
	 *
	 * Reads the bundled register configuration file from
	 * `lib/Settings/keepiq_register.json`, parses it, and passes it
	 * to OpenRegister's `ConfigurationService::importFromApp()` using
	 * the ADR-022 4-arg signature (appId, data, version, force).
	 * Mirrors the learniq / procest / decidesk pattern.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string,mixed> Result with success flag, message, and version.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $force is not consumed by this method
	 *   as a branch: it is the fourth argument of OpenRegister's ADR-022
	 *   ConfigurationService::importFromApp(appId, data, version, force) signature and is
	 *   passed straight through. The parameter and its default exist because that foreign
	 *   signature requires the value; the same shape ships in learniq / procest / decidesk.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-6
	 */
	public function loadConfiguration(bool $force = false): array {
		if ($this->appManager->isInstalled('openregister') === false) {
			$this->logger->warning('Keepiq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		try {
			$configLoad = $this->loadRegisterConfigData();
			if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
				return $configLoad;
			}

			$configData = $configLoad['data'];
			$configVersion = $configLoad['version'];

			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			$result = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force
			);

			if (empty($result) === false) {
				$this->logger->info('Keepiq: register configuration imported successfully');
				return [
					'success' => true,
					'message' => 'Configuration imported successfully.',
					'version' => ($result['version'] ?? 'unknown'),
				];
			}

			return [
				'success' => false,
				'message' => 'Import returned an empty result.',
			];
		} catch (Throwable $e) {
			$this->logger->error(
				'Keepiq: configuration import failed',
				['exception' => $e->getMessage()]
			);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try
	}//end loadConfiguration()

	/**
	 * Load and parse the keepiq_register.json configuration file.
	 *
	 * Reads the monolith register file, then deep-merges every modular
	 * fragment from lib/Settings/register.d/*.json (ADR-037) so concurrent
	 * OpenSpec change builds drop disjoint fragment files instead of editing
	 * the shared monolith. Returns the parsed data array on success, or an
	 * error result array on failure (same shape as the public load method so
	 * callers can return it).
	 *
	 * @return array<string,mixed> Either ['data' => array, 'version' => string]
	 *                             or ['success' => false, 'message' => string]
	 */
	private function loadRegisterConfigData(): array {
		$configPath = __DIR__ . '/../Settings/keepiq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Keepiq: keepiq_register.json not found at ' . $configPath);
			return [
				'success' => false,
				'message' => 'Configuration file keepiq_register.json not found.',
			];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Keepiq: failed to read keepiq_register.json');
			return [
				'success' => false,
				'message' => 'Failed to read configuration file.',
			];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Keepiq: failed to parse keepiq_register.json: ' . json_last_error_msg());
			return [
				'success' => false,
				'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
			];
		}

		// ADR-037: merge modular register fragments from Settings/register.d/*.json.
		// Each OpenSpec change drops its own fragment file instead of editing this
		// monolith, so concurrent builds touch disjoint files (no merge conflicts).
		// OpenAPI `components.schemas` / `paths` are keyed objects, so disjoint
		// fragments union cleanly by key.
		$fragmentSig = $this->mergeRegisterFragments(configData: $configData);

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		$version = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$version .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return [
			'data' => $configData,
			'version' => $version,
		];

	}//end loadRegisterConfigData()

	/**
	 * Merge every readable, well-formed fragment from Settings/register.d
	 * onto the accumulated config and return their signature.
	 *
	 * @param array<mixed> $configData The accumulated config, merged in place.
	 *
	 * @return string The concatenated `name:md5;` signature of every merged fragment.
	 */
	private function mergeRegisterFragments(array &$configData): string {
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === false) {
			return $fragmentSig;
		}

		$fragmentFiles = glob($fragmentDir . '/*.json');
		sort($fragmentFiles);
		foreach ($fragmentFiles as $fragmentFile) {
			$fragmentContent = file_get_contents($fragmentFile);
			if ($fragmentContent === false) {
				continue;
			}

			$fragmentData = json_decode($fragmentContent, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$this->logger->warning(
					'Keepiq: skipping malformed register fragment ' . basename($fragmentFile)
					. ': ' . json_last_error_msg()
				);
				continue;
			}

			$configData = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
			$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
		}//end foreach

		return $fragmentSig;
	}//end mergeRegisterFragments()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`, `paths`) are
	 * merged by key union (recursing on shared keys); list arrays are concatenated;
	 * scalars in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$bothArrays = (is_array($value) === true
				&& isset($base[$key]) === true
				&& is_array($base[$key]) === true);

			if ($bothArrays === false) {
				// Scalar (or new key): the overlay value wins.
				$base[$key] = $value;
				continue;
			}

			$baseIsList = ($base[$key] === [] || array_keys($base[$key]) === range(0, (count($base[$key]) - 1)));
			$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
			if ($baseIsList === true && $overlayIsList === true) {
				// Two lists: concatenate.
				$base[$key] = array_merge($base[$key], $value);
				continue;
			}

			// Two associative arrays: recurse.
			$base[$key] = self::deepMergeConfig(base: $base[$key], overlay: $value);
		}//end foreach

		return $base;
	}//end deepMergeConfig()
}//end class
