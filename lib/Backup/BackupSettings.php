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
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
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
	 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
	 */
	public function update(array $data): void {
		$appId = Application::APP_ID;
		$writes = [];

		if (array_key_exists(self::ENABLED, $data) === true) {
			$enabled = $this->validBool(key: self::ENABLED, value: $data[self::ENABLED]);
			$writes[] = fn () => $this->appConfig->setValueBool($appId, self::ENABLED, $enabled);
		}

		foreach ([self::INTERVAL => self::INTERVAL_MAX, self::RETENTION => self::RETENTION_MAX] as $key => $max) {
			if (array_key_exists($key, $data) === true) {
				$value = $this->validCount(key: $key, value: $data[$key], max: $max);
				$writes[] = fn () => $this->appConfig->setValueInt($appId, $key, $value);
			}
		}

		if (array_key_exists(self::PUBLIC_KEY, $data) === true) {
			$pem = $this->validKey(value: $data[self::PUBLIC_KEY]);
			$writes[] = fn () => $this->appConfig->setValueString($appId, self::PUBLIC_KEY, $pem);
		}

		foreach ($writes as $write) {
			$write();
		}
	}//end update()

	/**
	 * A boolean setting.
	 *
	 * @param string $key The key
	 * @param mixed $value The submitted value
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When it is not a boolean
	 */
	private function validBool(string $key, mixed $value): bool {
		$bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if ($bool === null) {
			throw new InvalidArgumentException($key . ' must be true or false');
		}

		return $bool;
	}//end validBool()

	/**
	 * A whole number from 1 to a maximum.
	 *
	 * @param string $key The key
	 * @param mixed $value The submitted value
	 * @param int $max The maximum
	 *
	 * @return int
	 *
	 * @throws InvalidArgumentException When out of range
	 */
	private function validCount(string $key, mixed $value, int $max): int {
		$count = filter_var($value, FILTER_VALIDATE_INT);
		if ($count === false || $count < 1 || $count > $max) {
			throw new InvalidArgumentException($key . ' must be between 1 and ' . $max);
		}

		return $count;
	}//end validCount()

	/**
	 * An RSA public key or certificate in PEM form, or '' to clear it.
	 *
	 * @param mixed $value The submitted value
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When it is not a usable key
	 */
	private function validKey(mixed $value): string {
		$pem = trim((string)$value);
		if ($pem !== '' && $this->cipher->isValidPublicKey(pem: $pem) === false) {
			throw new InvalidArgumentException(self::PUBLIC_KEY . ' must be an RSA public key or certificate in PEM form');
		}

		return $pem;
	}//end validKey()
}//end class
