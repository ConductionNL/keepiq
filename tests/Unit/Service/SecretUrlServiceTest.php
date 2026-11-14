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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretUrlMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\SecretUrlService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * SecretUrlService over a mocked mapper.
 *
 * @covers \OCA\Keepiq\Service\SecretUrlService
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Service\JudgedShareRows
 * @uses   \OCA\Keepiq\Service\ShareRowVerdict
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
	 * A share row from the source s1 to a recipient's copy.
	 *
	 * @param string $id       The row
	 * @param string $copyId   The copy it names
	 * @param string $targetId The recipient
	 *
	 * @return ShareTarget
	 */
	private function shareRow(string $id, string $copyId, string $targetId): ShareTarget {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId('s1');
		$row->setSecretId($copyId);
		$row->setTargetUserId($targetId);

		return $row;
	}//end shareRow()

	/**
	 * The owner's new list reaches every recipient copy whose row is not
	 * held back and whose target holds it; a held-back row, a row naming
	 * someone else's secret and a copy that is gone are left alone.
	 *
	 * @return void
	 */
	public function testTheOwnersNewListReachesEveryRecipientCopy(): void {
		$rows = [
			$this->shareRow(id: 'r1', copyId: 'c1', targetId: 'bob'),
			$this->shareRow(id: 'r2', copyId: 'c2', targetId: 'carol'),
			$this->shareRow(id: 'r3', copyId: 'c3', targetId: 'dave'),
			$this->shareRow(id: 'r4', copyId: 'c4', targetId: 'erin'),
		];
		$judge = $this->createMock(ShareRowJudge::class);
		$judge->method('forCopy')->willReturn(new ShareRowVerdict(state: ShareRowVerdict::NONE));
		$judge->method('rowsOfSource')->with('s1')->willReturn(
			new JudgedShareRows(rows: $rows, judge: static fn (ShareTarget $row): ?string => ($row->getId() === 'r2' ? 'quarantined' : null))
		);
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			function (string $id): Secret {
				if ($id === 'c4') {
					throw new DoesNotExistException('gone');
				}

				// c3 names a secret its row's target does not hold.
				$copy = $this->secret(ownerId: ['c1' => 'bob', 'c2' => 'carol', 'c3' => 'mallory'][$id]);
				$copy->setId($id);

				return $copy;
			}
		);
		$mapper = $this->createMock(SecretUrlMapper::class);
		$written = [];
		$mapper->method('replaceForSecret')->willReturnCallback(
			function (string $secretId, string $ownerId, array $urls) use (&$written): void {
				$written[] = [$secretId, $ownerId, $urls];
			}
		);
		$service = new SecretUrlService(mapper: $mapper, rowJudge: $judge, secretMapper: $secrets);

		$service->replace(secret: $this->secret(), userId: 'alice', urls: ['https://login.example.net']);

		$this->assertSame(
			[
				['s1', 'alice', ['https://login.example.net']],
				['c1', 'bob', ['https://login.example.net']],
			],
			$written
		);
	}//end testTheOwnersNewListReachesEveryRecipientCopy()

	/**
	 * The holder of a received copy does not change its list: it follows the owner's.
	 *
	 * @return void
	 */
	public function testTheHolderOfAReceivedCopyIsRefused(): void {
		$judge = $this->createMock(ShareRowJudge::class);
		$judge->method('forCopy')->willReturn(new ShareRowVerdict(state: ShareRowVerdict::TRUSTED, row: $this->shareRow(id: 'r1', copyId: 's1', targetId: 'alice')));
		$judge->expects($this->never())->method('rowsOfSource');
		$mapper = $this->createMock(SecretUrlMapper::class);
		$mapper->expects($this->never())->method('replaceForSecret');
		$service = new SecretUrlService(mapper: $mapper, rowJudge: $judge, secretMapper: $this->createMock(SecretMapper::class));

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('The addresses of a shared login are changed by its owner');
		$service->replace(secret: $this->secret(), userId: 'alice', urls: ['https://example.net']);
	}//end testTheHolderOfAReceivedCopyIsRefused()

	/**
	 * A new copy gets its source's list under its own id and holder.
	 *
	 * @return void
	 */
	public function testANewCopyGetsItsSourcesList(): void {
		$mapper = $this->createMock(SecretUrlMapper::class);
		$mapper->method('findUrlsBySecretIds')->with(['s1'])->willReturn(['s1' => ['https://login.example.net']]);
		$mapper->expects($this->once())->method('replaceForSecret')->with('c1', 'bob', ['https://login.example.net']);
		$copy = $this->secret(ownerId: 'bob');
		$copy->setId('c1');

		$this->assertSame(['https://login.example.net'], (new SecretUrlService($mapper))->copyOnto(source: $this->secret(), copy: $copy));
	}//end testANewCopyGetsItsSourcesList()

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
