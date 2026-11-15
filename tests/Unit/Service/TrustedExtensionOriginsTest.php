<?php

/**
 * Unit tests for TrustedExtensionOrigins.
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
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCA\Keepiq\Service\TrustedExtensionOrigins;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The trusted extension origins are exact, admin-extendable and fail closed.
 */
class TrustedExtensionOriginsTest extends TestCase {
	private const CHROME = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

	private const FIREFOX = 'moz-extension://abcdefghijklmnopabcdefghijklmnopabcdefghijklmnopabcdefghijklmnop';

	/**
	 * Origins over the given stored settings.
	 *
	 * @param bool $enabled The switch
	 * @param string $origins The stored admin list
	 *
	 * @return TrustedExtensionOrigins
	 */
	private function origins(bool $enabled = true, string $origins = '[]'): TrustedExtensionOrigins {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->with('keepiq', TrustedExtensionOrigins::ENABLED_KEY, true)->willReturn($enabled);
		$appConfig->method('getValueString')->with('keepiq', TrustedExtensionOrigins::ORIGINS_KEY, '[]')->willReturn($origins);

		return new TrustedExtensionOrigins(appConfig: $appConfig);
	}//end origins()

	/**
	 * The trusted list is the pinned list plus the admin list.
	 *
	 * @return void
	 */
	public function testTrustsThePinnedAndTheAdminOrigins(): void {
		$origins = $this->origins(origins: json_encode([self::CHROME, self::FIREFOX, self::CHROME]));

		$this->assertSame(
			array_values(array_unique(array_merge(TrustedExtensionOrigins::PINNED_STORE_ORIGINS, [self::CHROME, self::FIREFOX]))),
			$origins->trusted()
		);
		$origins->assertTrusted(origin: self::FIREFOX);
		$this->assertSame([self::CHROME, self::FIREFOX], $origins->adminOrigins());
	}//end testTrustsThePinnedAndTheAdminOrigins()

	/**
	 * Every pinned origin is itself an exact extension origin.
	 *
	 * @return void
	 */
	public function testThePinnedOriginsAreExactOrigins(): void {
		$this->assertSame(TrustedExtensionOrigins::PINNED_STORE_ORIGINS, $this->origins()->validate(entries: TrustedExtensionOrigins::PINNED_STORE_ORIGINS));
	}//end testThePinnedOriginsAreExactOrigins()

	/**
	 * Switched off, or an unreadable admin value, refuses every origin.
	 *
	 * @return array<string,array{0: bool, 1: string, 2: string}>
	 */
	public static function closedSettings(): array {
		return [
			'switched off' => [false, '["' . self::CHROME . '"]', 'Extension passkeys are switched off'],
			'not JSON' => [true, '{', 'The trusted extension origins setting is unreadable'],
			'not a list' => [true, '{"a":"' . self::CHROME . '"}', 'The trusted extension origins setting is unreadable'],
			'an invalid entry' => [true, '["' . self::CHROME . '","https://evil.test"]', 'The trusted extension origins setting is unreadable'],
		];
	}//end closedSettings()

	/**
	 * A closed setting refuses even an origin it lists.
	 *
	 * @param bool $enabled The switch
	 * @param string $stored The stored admin list
	 * @param string $reason The refusal
	 *
	 * @return void
	 */
	#[DataProvider('closedSettings')]
	public function testFailsClosed(bool $enabled, string $stored, string $reason): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage($reason);
		$this->origins(enabled: $enabled, origins: $stored)->assertTrusted(origin: self::CHROME);
	}//end testFailsClosed()

	/**
	 * An origin not on the list is refused.
	 *
	 * @return void
	 */
	public function testRefusesAnOriginNotOnTheList(): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage('The extension origin is not trusted');
		$this->origins(origins: '["' . self::CHROME . '"]')->assertTrusted(origin: 'chrome-extension://ponmlkjihgfedcbaponmlkjihgfedcba');
	}//end testRefusesAnOriginNotOnTheList()

	/**
	 * Only exact extension origins can be saved; the refusal names the entry.
	 *
	 * @return array<string,array{0: mixed, 1: string}>
	 */
	public static function invalidEntries(): array {
		return [
			'a wildcard' => [['chrome-extension://*'], 'Not an exact extension origin: chrome-extension://*'],
			'a path' => [[self::CHROME . '/popup.html'], 'Not an exact extension origin: ' . self::CHROME . '/popup.html'],
			'a trailing slash' => [[self::CHROME . '/'], 'Not an exact extension origin: ' . self::CHROME . '/'],
			'letters outside a to p' => [['chrome-extension://abcdefghijklmnopabcdefghijklmnoz'], 'Not an exact extension origin: chrome-extension://abcdefghijklmnopabcdefghijklmnoz'],
			'a short Firefox origin' => [['moz-extension://abcdefghijklmnop'], 'Not an exact extension origin: moz-extension://abcdefghijklmnop'],
			'a web origin' => [['https://cloud.example.com'], 'Not an exact extension origin: https://cloud.example.com'],
			'a trailing newline' => [[self::CHROME . "\n"], 'Not an exact extension origin'],
			'not a string' => [[42], 'Not an exact extension origin: integer'],
			'not a list' => ['chrome-extension://x', 'extension_passkey_origins must be a list of extension origins'],
		];
	}//end invalidEntries()

	/**
	 * An invalid entry is refused.
	 *
	 * @param mixed $entries The submitted value
	 * @param string $message The refusal
	 *
	 * @return void
	 */
	#[DataProvider('invalidEntries')]
	public function testRefusesAnInvalidEntry(mixed $entries, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->origins()->validate(entries: $entries);
	}//end testRefusesAnInvalidEntry()
}//end class
