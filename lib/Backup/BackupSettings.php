<?php

/**
 * Keepiq Backup Settings
 *
 * The scheduled backup settings (admin-scheduled-vault-backups D3): on or
 * off (default off), the interval in hours (default 24, minimum 1), the
 * number of archives to keep (default 7) and an optional recipient public
 * key. Read into the admin settings payload and validated on every save;
 * a bad value is refused before anything in the group is written.
 *
 * @category Backup
 * @package  OCA\Keepiq\Backup
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

namespace OCA\Keepiq\Backup;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and validates the backup settings.
 */
class BackupSettings {
	public const ENABLED = 'backup_enabled';
	public const INTERVAL = 'backup_interval_hours';
	public const RETENTION = 'backup_retention_count';
	public const PUBLIC_KEY = 'backup_recipient_public_key';

	public const INTERVAL_DEFAULT = 24;
	public const INTERVAL_MAX = 8760;
	public const RETENTION_DEFAULT = 7;
	public const RETENTION_MAX = 365;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config
	 * @param ArchiveCipher $cipher Validates the recipient key
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private ArchiveCipher $cipher,
	) {
	}//end __construct()

	/**
	 * The current backup settings.
	 *
	 * @return array{backup_enabled:bool,backup_interval_hours:int,backup_retention_count:int,backup_recipient_public_key:string}
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.1
	 */
	public function read(): array {
		$appId = Application::APP_ID;

		return [
			self::ENABLED => $this->appConfig->getValueBool($appId, self::ENABLED, false),
			self::INTERVAL => $this->appConfig->getValueInt($appId, self::INTERVAL, self::INTERVAL_DEFAULT),
			self::RETENTION => $this->appConfig->getValueInt($appId, self::RETENTION, self::RETENTION_DEFAULT),
			self::PUBLIC_KEY => $this->appConfig->getValueString($appId, self::PUBLIC_KEY, ''),
		];
	}//end read()

	/**
	 * Validate and store the backup keys present in an admin save.
	 *
	 * @param array<string,mixed> $data The admin-settings input
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On an out-of-range value or an unusable key
	 *
	 * @spec openspec/changes/admin-scheduled-vault-backups/tasks.md#2.1
	 */
	public function update(array $data): void {
		$appId = Application::APP_ID;
		$writes = [];

		if (array_key_exists(self::ENABLED, $data) === true) {
			$enabled = filter_var($data[self::ENABLED], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			if ($enabled === null) {
				throw new InvalidArgumentException(self::ENABLED . ' must be true or false');
			}

			$writes[] = fn () => $this->appConfig->setValueBool($appId, self::ENABLED, $enabled);
		}

		foreach ([self::INTERVAL => self::INTERVAL_MAX, self::RETENTION => self::RETENTION_MAX] as $key => $max) {
			if (array_key_exists($key, $data) === false) {
				continue;
			}

			$value = filter_var($data[$key], FILTER_VALIDATE_INT);
			if ($value === false || $value < 1 || $value > $max) {
				throw new InvalidArgumentException($key . ' must be between 1 and ' . $max);
			}

			$writes[] = fn () => $this->appConfig->setValueInt($appId, $key, $value);
		}

		if (array_key_exists(self::PUBLIC_KEY, $data) === true) {
			$pem = trim((string)$data[self::PUBLIC_KEY]);
			if ($pem !== '' && $this->cipher->isValidPublicKey(pem: $pem) === false) {
				throw new InvalidArgumentException(self::PUBLIC_KEY . ' must be an RSA public key or certificate in PEM form');
			}

			$writes[] = fn () => $this->appConfig->setValueString($appId, self::PUBLIC_KEY, $pem);
		}

		foreach ($writes as $write) {
			$write();
		}
	}//end update()
}//end class
