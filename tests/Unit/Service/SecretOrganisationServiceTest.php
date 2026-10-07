<?php

/**
 * Unit tests for favourites, tags and last used (vault-favourites-tags-and-last-used).
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\SecretOrganisationService;
use OCA\Keepiq\Service\SecretTagNormaliser;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only the holder of a row may star it, tag it or mark it used; anyone else
 * gets a not-found and nothing is written.
 */
class SecretOrganisationServiceTest extends TestCase {
	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var SecretTagMapper&MockObject */
	private SecretTagMapper $tags;

	private SecretOrganisationService $service;

	/**
	 * Wire the real service; s-alice is alice's row, s-bob is bob's, s-app an application's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->tags   = $this->createMock(SecretTagMapper::class);
		$rows         = [
			's-alice' => $this->row(id: 's-alice', ownerType: 'user', ownerId: 'alice'),
			's-bob' => $this->row(id: 's-bob', ownerType: 'user', ownerId: 'bob'),
			's-app' => $this->row(id: 's-app', ownerType: 'application', ownerId: 'alice'),
		];
		$this->mapper->method('findById')->willReturnCallback(
			static function (string $id) use ($rows): Secret {
				if (isset($rows[$id]) === false) {
					throw new DoesNotExistException('missing');
				}

				return $rows[$id];
			}
		);
		$this->service = new SecretOrganisationService(
			mapper: $this->mapper,
			tagMapper: $this->tags,
			normaliser: new SecretTagNormaliser(),
		);
	}//end setUp()

	/**
	 * Build a secret row.
	 *
	 * @param string $id        The id
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function row(string $id, string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end row()

	/**
	 * The holder stars their own row, and only that row.
	 *
	 * @return void
	 */
	public function testHolderStarsTheirOwnRow(): void {
		$this->mapper->expects($this->once())->method('setFavourite')->with('s-alice', 'alice', true)->willReturn(1);

		$this->assertSame(['id' => 's-alice', 'favourite' => true], $this->service->setFavourite('s-alice', 'alice', true));
	}//end testHolderStarsTheirOwnRow()

	/**
	 * Another user's row, an application's row and a missing row are all
	 * not found, for every action, and nothing is written.
	 *
	 * @return void
	 */
	public function testNobodyElseCanStarTagOrMarkUsed(): void {
		$this->mapper->expects($this->never())->method('setFavourite');
		$this->mapper->expects($this->never())->method('markUsed');
		$this->tags->expects($this->never())->method('replaceForSecret');

		$refused = 0;
		foreach (['s-bob', 's-app', 's-missing'] as $id) {
			foreach ([
				fn () => $this->service->setFavourite($id, 'alice', true),
				fn () => $this->service->setTags($id, 'alice', ['x']),
				fn () => $this->service->markUsed($id, 'alice'),
			] as $action) {
				try {
					$action();
				} catch (NotFoundException) {
					$refused++;
				}
			}
		}

		$this->assertSame(9, $refused);
	}//end testNobodyElseCanStarTagOrMarkUsed()

	/**
	 * Setting tags normalises them and replaces the holder's set.
	 *
	 * @return void
	 */
	public function testSetTagsNormalisesAndReplaces(): void {
		$this->tags->expects($this->once())->method('replaceForSecret')->with('s-alice', 'alice', ['on call', 'finance']);

		$this->assertSame(['on call', 'finance'], $this->service->setTags('s-alice', 'alice', [' On Call ', 'FINANCE', 'on call']));
	}//end testSetTagsNormalisesAndReplaces()

	/**
	 * Twenty-one tags are refused before anything is written.
	 *
	 * @return void
	 */
	public function testTooManyTagsWriteNothing(): void {
		$this->tags->expects($this->never())->method('replaceForSecret');

		$this->expectException(InvalidArgumentException::class);
		$this->service->setTags('s-alice', 'alice', array_map(static fn (int $i): string => 't'.$i, range(1, 21)));
	}//end testTooManyTagsWriteNothing()

	/**
	 * Marking used stamps the holder's row with the current time.
	 *
	 * @return void
	 */
	public function testMarkUsedStampsTheHoldersRow(): void {
		$before = new DateTime('-1 second');
		$this->mapper->expects($this->once())->method('markUsed')->with(
			's-alice',
			'alice',
			$this->callback(static fn (DateTime $at): bool => $at >= $before)
		)->willReturn(1);

		$this->service->markUsed('s-alice', 'alice');
	}//end testMarkUsedStampsTheHoldersRow()

	/**
	 * The tag list is the holder's own, by owner id.
	 *
	 * @return void
	 */
	public function testListTagsIsTheHoldersOwn(): void {
		$this->tags->expects($this->once())->method('countByOwner')->with('alice')
			->willReturn([['tag' => 'finance', 'count' => 3]]);

		$this->assertSame([['tag' => 'finance', 'count' => 3]], $this->service->listTags('alice'));
	}//end testListTagsIsTheHoldersOwn()
}//end class
