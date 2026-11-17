<?php

/**
 * Keepiq - AudiencePolicy tests
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Service\AudiencePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which audiences an assertion may name.
 */
#[CoversClass(AudiencePolicy::class)]
class AudiencePolicyTest extends TestCase {
	/**
	 * The canonical audience is accepted and is not deprecated use.
	 *
	 * @return void
	 */
	public function testTheCanonicalAudienceIsNotDeprecated(): void {
		$policy = new AudiencePolicy(logger: $this->createMock(LoggerInterface::class));

		$this->assertFalse($policy->assertNamesThisInstance(claims: ['aud' => 'keepiq']));
		$this->assertFalse($policy->assertNamesThisInstance(claims: ['aud' => ['other', 'keepiq', 'doriath']]));
	}//end testTheCanonicalAudienceIsNotDeprecated()

	/**
	 * Only the deprecated audience is accepted, as deprecated use.
	 *
	 * @return void
	 */
	public function testTheDeprecatedAudienceAloneIsDeprecatedUse(): void {
		$policy = new AudiencePolicy(logger: $this->createMock(LoggerInterface::class));

		$this->assertTrue($policy->assertNamesThisInstance(claims: ['aud' => 'doriath']));
		$this->assertTrue($policy->assertNamesThisInstance(claims: ['aud' => ['other', 'doriath']]));
	}//end testTheDeprecatedAudienceAloneIsDeprecatedUse()

	/**
	 * Claims that name no accepted audience.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refusedAudiences(): array {
		return [
			'missing' => [null],
			'empty string' => [''],
			'foreign' => ['other-app'],
			'number' => [42],
			'empty list' => [[]],
			'malformed member' => [['keepiq', 7]],
			'empty member' => [['keepiq', '']],
		];
	}//end refusedAudiences()

	/**
	 * A claim naming no accepted audience is refused.
	 *
	 * @param mixed $aud The aud claim.
	 *
	 * @return void
	 */
	#[DataProvider('refusedAudiences')]
	public function testAWrongAudienceIsRefused(mixed $aud): void {
		$policy = new AudiencePolicy(logger: $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Wrong audience');

		$policy->assertNamesThisInstance(claims: ($aud === null ? [] : ['aud' => $aud]));
	}//end testAWrongAudienceIsRefused()

	/**
	 * The deprecation warning names the issuer and both audiences.
	 *
	 * @return void
	 */
	public function testDeprecatedUseIsLoggedWithTheIssuer(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->stringContains('deprecated audience'),
			[
				'deprecated' => 'doriath',
				'canonical' => 'keepiq',
				'version' => '1.0.0',
				'iss' => 'https://idp.example',
			]
		);

		(new AudiencePolicy(logger: $logger))->reportDeprecatedUse(claims: ['iss' => 'https://idp.example']);
	}//end testDeprecatedUseIsLoggedWithTheIssuer()

	/**
	 * Without an issuer the warning says unknown.
	 *
	 * @return void
	 */
	public function testAMissingIssuerIsReportedAsUnknown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			$this->anything(),
			$this->callback(static fn (array $context): bool => $context['iss'] === 'unknown')
		);

		(new AudiencePolicy(logger: $logger))->reportDeprecatedUse(claims: []);
	}//end testAMissingIssuerIsReportedAsUnknown()
}//end class
