<?php

/**
 * Golden test of the admin settings payload.
 *
 * Records, for a scripted app config and three CA service answers, the
 * payload of getAdminSettings() (keys in order, values) and every app config
 * read in order, and compares it with golden/admin-settings-payload.json.
 * KEEPIQ_RECORD_GOLDEN=1 rewrites that file.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\AdminSettingsService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden record of getAdminSettings().
 *
 * @covers \OCA\Keepiq\Service\AdminSettingsService
 * @uses   \OCA\Keepiq\Service\PasswordPolicyService
 */
class AdminSettingsPayloadGoldenTest extends TestCase {
	private const GOLDEN = __DIR__.'/golden/admin-settings-payload.json';

	/** @var array<int,string> */
	private array $reads = [];

	/** @var array<int,string> */
	private array $debug = [];

	/**
	 * Build the service over a scripted app config and a CA service answer.
	 *
	 * @param string $ca One of "status", "no-method", "throws"
	 * @param int    $salt Varies every scripted value between runs
	 *
	 * @return AdminSettingsService
	 */
	private function service(string $ca, int $salt): AdminSettingsService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0) use ($salt): int {
				$this->reads[] = "int $app $key $default";
				return ($salt === 0) ? $default : (crc32($key) % 97) + $salt;
			}
		);
		$appConfig->method('getValueBool')->willReturnCallback(
			function (string $app, string $key, bool $default = false) use ($salt): bool {
				$this->reads[] = "bool $app $key ".var_export($default, true);
				return ($salt === 0) ? $default : ((crc32($key) + $salt) % 2 === 0);
			}
		);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') use ($salt): string {
				$this->reads[] = "string $app $key $default";
				if ($salt === 0) {
					return $default;
				}

				return ($key === 'expiry_reminder_days') ? '[14,'.$salt.']' : $key.'-'.$salt;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($ca === 'throws') {
			$container->method('get')->willThrowException(new RuntimeException('no CA'));
		} else if ($ca === 'no-method') {
			$container->method('get')->willReturn(new \stdClass());
		} else {
			$container->method('get')->willReturnCallback(
				function (string $id): object {
					$this->reads[] = "container $id";
					return new class {
						/**
						 * @return array<string,string>
						 */
						public function getStatus(): array {
							return ['status' => 'active', 'expires' => '2027-01-01'];
						}
					};
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(
			function (string $message): void {
				$this->debug[] = $message;
			}
		);

		return new AdminSettingsService(
			appConfig: $appConfig,
			container: $container,
			userSession: $this->createMock(IUserSession::class),
			logger: $logger,
		);
	}//end service()

	/**
	 * The payload, its key order and the reads match the golden record.
	 *
	 * @return void
	 */
	public function testPayloadMatchesGolden(): void {
		$got = [];
		foreach (['status', 'no-method', 'throws'] as $ca) {
			foreach ([0, 5] as $salt) {
				$this->reads = [];
				$this->debug = [];
				$payload = $this->service(ca: $ca, salt: $salt)->getAdminSettings();
				$got["$ca salt=$salt"] = [
					'keys'    => array_keys($payload),
					'payload' => $payload,
					'reads'   => $this->reads,
					'debug'   => $this->debug,
				];
			}
		}

		$json = json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))."\n";
		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			if (is_dir(dirname(self::GOLDEN)) === false) {
				mkdir(dirname(self::GOLDEN), 0777, true);
			}

			file_put_contents(self::GOLDEN, $json);
		}

		$this->assertFileExists(self::GOLDEN);
		$this->assertSame(file_get_contents(self::GOLDEN), $json);
	}//end testPayloadMatchesGolden()
}//end class
