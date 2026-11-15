<?php

/**
 * Unit tests for the bounds the admin settings writes enforce.
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

use InvalidArgumentException;
use OCA\Keepiq\Service\AdminSettingsService;
use OCP\IAppConfig;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Every accepted value is stored with its type, every out-of-bounds value is
 * refused with its message before anything of its group is written.
 *
 * @covers \OCA\Keepiq\Service\AdminSettingsService
 * @uses \OCA\Keepiq\Service\PasswordPolicyService
 * @uses \OCA\Keepiq\Service\TrustedExtensionOrigins
 */
final class AdminSettingsValidationTest extends TestCase {

	/**
	 * Every key written, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $written = [];

	/**
	 * The service over an app config that records writes and answers defaults.
	 *
	 * @return AdminSettingsService
	 */
	private function service(): AdminSettingsService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnArgument(2);
		$appConfig->method('getValueString')->willReturnArgument(2);
		$appConfig->method('getValueBool')->willReturnArgument(2);
		$appConfig->method('getValueArray')->willReturnArgument(2);
		$record = function (string $app, string $key, mixed $value): bool {
			$this->written[$key] = $value;
			return true;
		};
		$appConfig->method('setValueInt')->willReturnCallback($record);
		$appConfig->method('setValueString')->willReturnCallback($record);
		$appConfig->method('setValueBool')->willReturnCallback($record);

		return new AdminSettingsService(
			appConfig: $appConfig,
			container: $this->createMock(originalClassName: ContainerInterface::class),
			userSession: $this->createMock(originalClassName: IUserSession::class),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end service()

	/**
	 * Accepted writes per area, with what is stored.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
	 */
	public static function acceptedWrites(): array {
		return [
			'password length bounds'  => ['policies', ['min_password_length' => '12'], ['min_password_length' => 12]],
			'password length top'     => ['policies', ['min_password_length' => 20], ['min_password_length' => 20]],
			'password score'          => ['policies', ['min_password_score' => '4'], ['min_password_score' => 4]],
			'session timeout'         => ['policies', ['default_session_timeout' => '30min'], ['default_session_timeout' => '30min']],
			'expiry off'              => ['policies', ['expiry_default_max_age_days' => 0], ['expiry_default_max_age_days' => 0]],
			'reminders kept positive' => ['policies', ['expiry_reminder_days' => ['30', 0, -1, 7]], ['expiry_reminder_days' => '[30,7]']],
			'version retention'       => ['policies', ['version_retention_count' => 1, 'version_retention_days' => 0], ['version_retention_count' => 1, 'version_retention_days' => 0]],
			'trash retention'         => ['policies', ['trash_retention_days' => 365], ['trash_retention_days' => 365]],
			'lease ttls and switches' => [
				'applications',
				['lease_default_ttl_seconds' => 60, 'lease_max_ttl_seconds' => '3600', 'lease_renewable' => 1, 'lease_revocation_blocks_refetch' => 0],
				['lease_default_ttl_seconds' => 60, 'lease_max_ttl_seconds' => 3600, 'lease_renewable' => true, 'lease_revocation_blocks_refetch' => false],
			],
			'audit retention'         => ['audit', ['audit_retention_days' => 30], ['audit_retention_days' => 30]],
			'attachment limits'       => ['general', ['attachment_max_bytes' => 1, 'attachment_user_quota_bytes' => '1048576'], ['attachment_max_bytes' => 1, 'attachment_user_quota_bytes' => 1048576]],
			'instance switches'       => [
				'general',
				['ca_auto_renew_enabled' => 1, 'breach_check_enabled' => 0, 'offline_cache_enabled' => true],
				['ca_auto_renew_enabled' => true, 'breach_check_enabled' => false, 'offline_cache_enabled' => true],
			],
		];
	}//end acceptedWrites()

	/**
	 * An accepted value is stored under its key with its type.
	 *
	 * @param string               $area     The settings area.
	 * @param array<string, mixed> $data     The submitted values.
	 * @param array<string, mixed> $expected What is stored.
	 *
	 * @return void
	 */
	#[DataProvider('acceptedWrites')]
	public function testAnAcceptedValueIsStored(string $area, array $data, array $expected): void {
		$this->service()->updateAreaSettings(area: $area, data: $data);

		$this->assertSame(expected: $expected, actual: array_intersect_key($this->written, $expected));
	}//end testAnAcceptedValueIsStored()

	/**
	 * Refused writes per area, with the refusal.
	 *
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusedWrites(): array {
		return [
			'password too short'   => ['policies', ['min_password_length' => 11], 'min_password_length must be between 12 and 20'],
			'password too long'    => ['policies', ['min_password_length' => 21], 'min_password_length must be between 12 and 20'],
			'weak score'           => ['policies', ['min_password_score' => 2], 'min_password_score must be 3 or 4'],
			'unknown timeout'      => ['policies', ['default_session_timeout' => '1h'], 'default_session_timeout must be one of: session, 10min, 30min'],
			'negative expiry'      => ['policies', ['expiry_default_max_age_days' => -1], 'expiry_default_max_age_days must be 0 (off) or positive'],
			'no reminder'          => ['policies', ['expiry_reminder_days' => [0, -3]], 'expiry_reminder_days needs at least one positive threshold'],
			'no versions kept'     => ['policies', ['version_retention_count' => 0], 'version_retention_count must be at least 1'],
			'negative version age' => ['policies', ['version_retention_days' => -1], 'version_retention_days must be 0 (unlimited) or positive'],
			'trash too short'      => ['policies', ['trash_retention_days' => 0], 'trash_retention_days must be between 1 and 365'],
			'trash too long'       => ['policies', ['trash_retention_days' => 366], 'trash_retention_days must be between 1 and 365'],
			'short default lease'  => ['applications', ['lease_default_ttl_seconds' => 59], 'lease_default_ttl_seconds must be at least 60 seconds'],
			'short max lease'      => ['applications', ['lease_max_ttl_seconds' => 1], 'lease_max_ttl_seconds must be at least 60 seconds'],
			'short audit trail'    => ['audit', ['audit_retention_days' => 29], 'audit_retention_days must be at least 30 days — below that the audit trail cannot serve incident investigation'],
			'empty attachment'     => ['general', ['attachment_max_bytes' => 0], 'attachment_max_bytes must be a positive byte count'],
			'empty quota'          => ['general', ['attachment_user_quota_bytes' => -5], 'attachment_user_quota_bytes must be a positive byte count'],
		];
	}//end refusedWrites()

	/**
	 * An out-of-bounds value is refused with its message and not stored.
	 *
	 * @param string               $area    The settings area.
	 * @param array<string, mixed> $data    The submitted values.
	 * @param string               $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('refusedWrites')]
	public function testAnOutOfBoundsValueIsRefused(string $area, array $data, string $message): void {
		try {
			$this->service()->updateAreaSettings(area: $area, data: $data);
			$this->fail('The write should be refused');
		} catch (InvalidArgumentException $e) {
			$this->assertSame(expected: $message, actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: array_intersect_key($this->written, $data));
	}//end testAnOutOfBoundsValueIsRefused()

	/**
	 * The whole-payload write runs every area's guard: one bad value stops the areas after it.
	 *
	 * @return void
	 */
	public function testTheWholePayloadWriteStopsAtTheFirstRefusal(): void {
		$service = $this->service();
		$service->updateAdminSettings(data: ['offline_edits_enabled' => true, 'audit_retention_days' => 90, 'lease_renewable' => true]);
		$this->assertSame(expected: [true, 90, true], actual: [$this->written['offline_edits_enabled'], $this->written['audit_retention_days'], $this->written['lease_renewable']]);

		$this->written = [];
		$this->expectException(InvalidArgumentException::class);
		try {
			$service->updateAdminSettings(data: ['device_approval_enabled' => true, 'min_password_score' => 1, 'audit_retention_days' => 90]);
		} finally {
			$this->assertSame(expected: ['device_approval_enabled' => true], actual: $this->written);
		}
	}//end testTheWholePayloadWriteStopsAtTheFirstRefusal()
}//end class
