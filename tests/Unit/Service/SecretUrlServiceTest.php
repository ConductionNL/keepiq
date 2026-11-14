<?php

/**
 * Unit tests for SecretUrlService: the cap, the normalised list, the owner
 * check and the list attached to serialised secrets.
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
 *
 * @spec openspec/changes/vault-website-addresses/specs/website-addresses/spec.md#requirement-a-login-carries-several-website-addresses
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\SecretUrlService;
use PHPUnit\Framework\TestCase;

/**
 * SecretUrlService over a mocked mapper.
 *
 * @covers \OCA\Keepiq\Service\SecretUrlService
 * @uses   \OCA\Keepiq\Db\Secret
 */
class SecretUrlServiceTest extends TestCase {

	/**
	 * A login owned by the given user.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner
	 *
	 * @return Secret
	 */
	private function secret(string $ownerType='user', string $ownerId='alice'): Secret {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);

		return $secret;
	}//end secret()

	/**
	 * The list is trimmed, without empties or repeats, in the order given.
	 *
	 * @return void
	 */
	public function testTheListIsTrimmedWithoutEmptiesOrRepeats(): void {
		$service = new SecretUrlService($this->createMock(SecretUrlMapper::class));

		$this->assertSame(
			['https://login.example.net', 'https://example.org'],
			$service->normalise(urls: [' https://login.example.net ', '', 'https://example.org', 'HTTPS://LOGIN.EXAMPLE.NET'])
		);
		$this->assertSame([], $service->normalise(urls: null));
		$this->assertSame([], $service->normalise(urls: []));
	}//end testTheListIsTrimmedWithoutEmptiesOrRepeats()

	/**
	 * Twenty addresses are stored; twenty-one are refused.
	 *
	 * @return void
	 */
	public function testMoreThanTwentyAddressesAreRefused(): void {
		$service = new SecretUrlService($this->createMock(SecretUrlMapper::class));
		$twenty = array_map(static fn (int $i): string => 'https://site' . $i . '.example', range(1, 20));

		$this->assertCount(20, $service->normalise(urls: $twenty));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A login carries at most 20 extra addresses');
		$service->normalise(urls: array_merge($twenty, ['https://one-more.example']));
	}//end testMoreThanTwentyAddressesAreRefused()

	/**
	 * A list that is not a list of text is refused.
	 *
	 * @return void
	 */
	public function testAnEntryThatIsNotTextIsRefused(): void {
		$service = new SecretUrlService($this->createMock(SecretUrlMapper::class));

		$this->expectException(InvalidArgumentException::class);
		$service->normalise(urls: ['https://a.example', ['nested']]);
	}//end testAnEntryThatIsNotTextIsRefused()

	/**
	 * An address longer than 512 characters is refused.
	 *
	 * @return void
	 */
	public function testAnOverlongAddressIsRefused(): void {
		$service = new SecretUrlService($this->createMock(SecretUrlMapper::class));

		$this->expectException(InvalidArgumentException::class);
		$service->normalise(urls: ['https://' . str_repeat('a', 510) . '.example']);
	}//end testAnOverlongAddressIsRefused()

	/**
	 * The owner's list replaces the stored one.
	 *
	 * @return void
	 */
	public function testTheOwnersListReplacesTheStoredOne(): void {
		$mapper = $this->createMock(SecretUrlMapper::class);
		$mapper->expects($this->once())->method('replaceForSecret')->with('s1', 'alice', ['https://login.example.net']);

		$stored = (new SecretUrlService($mapper))->replace(secret: $this->secret(), userId: 'alice', urls: ['https://login.example.net']);

		$this->assertSame(['https://login.example.net'], $stored);
	}//end testTheOwnersListReplacesTheStoredOne()

	/**
	 * Someone else's login, or an application's, is refused and nothing is written.
	 *
	 * @return void
	 */
	public function testANonOwnerIsRefusedAndNothingIsWritten(): void {
		$mapper = $this->createMock(SecretUrlMapper::class);
		$mapper->expects($this->never())->method('replaceForSecret');
		$service = new SecretUrlService($mapper);

		foreach ([$this->secret(ownerId: 'bob'), $this->secret(ownerType: 'application', ownerId: 'alice')] as $secret) {
			try {
				$service->replace(secret: $secret, userId: 'alice', urls: []);
				$this->fail('not refused');
			} catch (ForbiddenException $e) {
				$this->assertSame('Only the owner of a login changes its addresses', $e->getMessage());
			}
		}
	}//end testANonOwnerIsRefusedAndNothingIsWritten()

	/**
	 * Every serialised secret gets its list; one without extra addresses an empty list.
	 *
	 * @return void
	 */
	public function testSerialisedSecretsGetTheirAddresses(): void {
		$mapper = $this->createMock(SecretUrlMapper::class);
		$mapper->expects($this->once())->method('findUrlsBySecretIds')->with(['s1', 's2'])->willReturn(['s1' => ['https://login.example.net']]);

		$items = (new SecretUrlService($mapper))->withUrls(items: [['id' => 's1', 'name' => 'Example'], ['id' => 's2', 'name' => 'Other']]);

		$this->assertSame(
			[['id' => 's1', 'name' => 'Example', 'additionalUrls' => ['https://login.example.net']], ['id' => 's2', 'name' => 'Other', 'additionalUrls' => []]],
			$items
		);
	}//end testSerialisedSecretsGetTheirAddresses()
}//end class
