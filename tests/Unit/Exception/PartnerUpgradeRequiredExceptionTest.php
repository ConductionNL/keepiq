<?php

/**
 * Unit tests for PartnerUpgradeRequiredException.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Exception
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

namespace OCA\Keepiq\Tests\Unit\Exception;

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\PartnerUpgradeRequiredException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for PartnerUpgradeRequiredException.
 *
 * @covers \OCA\Keepiq\Exception\PartnerUpgradeRequiredException
 */
class PartnerUpgradeRequiredExceptionTest extends TestCase {
	/**
	 * By default the required version is the one the TOTP/passkey fields shipped in.
	 *
	 * @return void
	 */
	public function testDefaultsToTheTotpPasskeysRelease(): void {
		$exception = new PartnerUpgradeRequiredException();

		$this->assertSame(Application::TOTP_PASSKEYS_FIELDS_SINCE, $exception->getRequiredVersion());
		$this->assertStringContainsString(
			'stable release '.Application::TOTP_PASSKEYS_FIELDS_SINCE.' or later',
			$exception->getMessage()
		);
		$this->assertInstanceOf(RuntimeException::class, $exception);
	}//end testDefaultsToTheTotpPasskeysRelease()

	/**
	 * The message names the given version twice: as the floor and as the unstable build that does not count.
	 *
	 * @return void
	 */
	public function testMessageNamesTheGivenVersionAsFloorAndUnstableBuild(): void {
		$exception = new PartnerUpgradeRequiredException('1.2.3');

		$this->assertSame('1.2.3', $exception->getRequiredVersion());
		$this->assertSame(
			'The receiving organisation must upgrade Keepiq to the stable release 1.2.3 or later to receive '
			.'one-time codes and passkeys; a 1.2.3-unstable build does not count',
			$exception->getMessage()
		);
	}//end testMessageNamesTheGivenVersionAsFloorAndUnstableBuild()

	/**
	 * The machine-readable code is stable and the exception carries no numeric code.
	 *
	 * @return void
	 */
	public function testCodeConstantAndNumericCode(): void {
		$exception = new PartnerUpgradeRequiredException('2.0.0');

		$this->assertSame('partner_upgrade_required', PartnerUpgradeRequiredException::CODE);
		$this->assertSame(0, $exception->getCode());
		$this->assertNull($exception->getPrevious());
	}//end testCodeConstantAndNumericCode()
}//end class
