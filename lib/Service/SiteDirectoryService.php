<?php

/**
 * Keepiq Site Directory Service
 *
 * Keeps one copy of the public site directory the password health report
 * matches logins against: the sites that offer one-time codes (2FA Directory)
 * and the sites that offer passkeys (Passkeys Directory). The server fetches
 * both lists whole, so the directories' operators never learn which sites any
 * user has, keeps only each domain and its documentation link, and stores the
 * result in app data. Nothing from a vault is read or sent.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fetches, stores and serves the site directory copy.
 *
 * @spec openspec/changes/health-site-security-checks/tasks.md#task-2.2
 */
class SiteDirectoryService {

	/**
	 * The admin switch, off by default (design D3).
	 */
	public const CONFIG_KEY = 'site_directory_enabled';

	/**
	 * Sites with one-time codes: the 2FA Directory API v4 (MIT, design D2a).
	 */
	public const TOTP_URL = 'https://api.2fa.directory/v4/totp.json';

	/**
	 * Sites with passkeys: the Passkeys Directory API v1 (CC BY 4.0, design D2a).
	 */
	public const PASSKEY_URL = 'https://passkeys-api.2fa.directory/v1/supported.json';

	/**
	 * The app data folder holding the copy.
	 */
	public const FOLDER = 'site-directory';

	/**
	 * The stored copy's file name.
	 */
	public const FILE = 'directory.json';

	/**
	 * Seconds one directory download may take.
	 */
	private const TIMEOUT = 30;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig      Reads the admin switch
	 * @param IClientService  $clientService  Downloads the directories
	 * @param IAppDataFactory $appDataFactory Stores the copy
	 * @param ITimeFactory    $time           Stamps the copy
	 * @param LoggerInterface $logger         Logs a failed refresh
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private IClientService $clientService,
		private IAppDataFactory $appDataFactory,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether an administrator switched the site directory on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/health-site-security-checks/tasks.md#task-2.2
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool(AppIdentity::APP_ID, self::CONFIG_KEY, false);
	}//end isEnabled()

	/**
	 * Download both directories and replace the stored copy.
	 *
	 * Does nothing, and asks nobody, while the switch is off. When a download
	 * fails or answers something that is not a directory, the last good copy
	 * stays as it is.
	 *
	 * @return bool Whether a new copy was stored.
	 *
	 * @spec openspec/changes/health-site-security-checks/tasks.md#task-2.2
	 */
	public function refresh(): bool {
		if ($this->isEnabled() === false) {
			return false;
		}

		try {
			$copy = [
				'fetchedAt' => $this->time->getDateTime('now')->format(DATE_ATOM),
				'totp' => $this->documentationByDomain(entries: $this->download(url: self::TOTP_URL)),
				'passkeys' => $this->documentationByDomain(entries: $this->download(url: self::PASSKEY_URL)),
			];
		} catch (Throwable $exception) {
			// The message names only the directory's public URL; nothing of a user is involved.
			$this->logger->warning(
				'Keepiq: site directory refresh failed, the last copy is kept: ' . $exception->getMessage(),
				['app' => AppIdentity::APP_ID]
			);
			return false;
		}

		$this->file(create: true)->putContent((string)json_encode($copy, JSON_UNESCAPED_SLASHES));

		return true;
	}//end refresh()

	/**
	 * The stored copy as JSON, or null while there is none.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/health-site-security-checks/tasks.md#task-2.2
	 */
	public function current(): ?string {
		try {
			$content = $this->file(create: false)->getContent();
		} catch (NotFoundException $exception) {
			return null;
		}

		if ($content === '') {
			return null;
		}

		return $content;
	}//end current()

	/**
	 * One directory, decoded.
	 *
	 * @param string $url The directory's URL
	 *
	 * @return array<mixed> The entries keyed by domain.
	 *
	 * @throws RuntimeException When the answer is not a JSON object.
	 */
	private function download(string $url): array {
		$response = $this->clientService->newClient()->get($url, ['timeout' => self::TIMEOUT]);
		$entries  = json_decode((string)$response->getBody(), true);
		if (is_array($entries) === false || $entries === [] || array_is_list($entries) === true) {
			throw new RuntimeException('the answer of ' . $url . ' is not a site directory');
		}

		return $entries;
	}//end download()

	/**
	 * Each domain of a directory with its documentation link, the only facts the report shows.
	 *
	 * @param array<mixed> $entries The directory's entries keyed by domain
	 *
	 * @return array<string, string> Lowercase domain to documentation URL (empty when none).
	 */
	private function documentationByDomain(array $entries): array {
		$domains = [];
		foreach ($entries as $domain => $entry) {
			$domain = strtolower(trim((string)$domain));
			if ($domain === '' || preg_match('/^[a-z0-9.-]+$/', $domain) !== 1) {
				continue;
			}

			$documentation = '';
			if (is_array($entry) === true && is_string($entry['documentation'] ?? null) === true
				&& str_starts_with($entry['documentation'], 'https://') === true
			) {
				$documentation = $entry['documentation'];
			}

			$domains[$domain] = $documentation;
		}

		ksort($domains);

		return $domains;
	}//end documentationByDomain()

	/**
	 * The copy's file in app data.
	 *
	 * @param bool $create Create the folder and the file when missing
	 *
	 * @return ISimpleFile
	 *
	 * @throws NotFoundException When it is missing and $create is false.
	 */
	private function file(bool $create): ISimpleFile {
		$folder = $this->folder(create: $create);
		if ($create === true && $folder->fileExists(self::FILE) === false) {
			return $folder->newFile(self::FILE);
		}

		return $folder->getFile(self::FILE);
	}//end file()

	/**
	 * The copy's folder in app data.
	 *
	 * @param bool $create Create it when missing
	 *
	 * @return ISimpleFolder
	 *
	 * @throws NotFoundException When it is missing and $create is false.
	 */
	private function folder(bool $create): ISimpleFolder {
		$appData = $this->appDataFactory->get(AppIdentity::APP_ID);
		try {
			return $appData->getFolder(self::FOLDER);
		} catch (NotFoundException $exception) {
			if ($create === false) {
				throw $exception;
			}

			return $appData->newFolder(self::FOLDER);
		}
	}//end folder()
}//end class
