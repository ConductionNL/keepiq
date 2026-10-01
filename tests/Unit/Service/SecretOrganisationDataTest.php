<?php

/**
 * Favourites, tags and last used in the entity, the GDPR package and the
 * delete cascades (vault-favourites-tags-and-last-used).
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\GdprService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCA\Keepiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The metadata belongs to the holder: exported to them, deleted with them.
 */
class SecretOrganisationDataTest extends TestCase {
	/**
	 * A row of alice's.
	 *
	 * @param string $id The id
	 *
	 * @return Secret
	 */
	private function row(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		return $secret;
	}//end row()

	/**
	 * Both serialisations carry the star and the last-used time, and a new
	 * row is not a favourite.
	 *
	 * @return void
	 */
	public function testEntitySerialisesFavouriteAndLastUsed(): void {
		$secret = $this->row('s-1');
		$this->assertFalse($secret->jsonSerialize()['favourite']);
		$this->assertNull($secret->jsonSerialize()['lastUsedAt']);

		$secret->setIsFavourite(true);
		$secret->setLastUsedAt(new DateTime('2026-10-01T10:00:00+00:00'));

		$this->assertTrue($secret->jsonSerialize()['favourite']);
		$this->assertSame('2026-10-01T10:00:00+00:00', $secret->jsonSerialize()['lastUsedAt']);
		$this->assertTrue($secret->jsonSerializeBlocked('revoked')['favourite']);
		$this->assertSame('2026-10-01T10:00:00+00:00', $secret->jsonSerializeBlocked('revoked')['lastUsedAt']);
	}//end testEntitySerialisesFavouriteAndLastUsed()

	/**
	 * The GDPR package lists the holder's stars, tags and last-used times,
	 * and only for rows that carry any.
	 *
	 * @return void
	 */
	public function testGdprPackageCarriesTheOrganisation(): void {
		$starred = $this->row('s-1');
		$starred->setIsFavourite(true);
		$starred->setLastUsedAt(new DateTime('2026-10-01T10:00:00+00:00'));
		$plain = $this->row('s-2');

		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByOwner')->willReturn([$starred, $plain]);
		$tagMapper = $this->createMock(SecretTagMapper::class);
		$tagMapper->expects($this->once())->method('findTagsBySecretIds')->with('alice', ['s-1', 's-2'])
			->willReturn(['s-1' => ['finance', 'on call']]);
		$suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$suiteMapper->method('findByOwner')->willReturn([]);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getUserPreferences')->willReturn([]);

		$service = new GdprService(
			secretMapper: $secretMapper,
			shareMapper: $this->createMock(ShareTargetMapper::class),
			delegationMapper: $this->createMock(SecretDelegationMapper::class),
			linkShareMapper: $this->createMock(LinkShareMapper::class),
			requestMapper: $this->createMock(SecretRequestMapper::class),
			suiteMapper: $suiteMapper,
			settingsService: $settings,
			tagMapper: $tagMapper,
		);

		$this->assertSame(
			[
				[
					'secretId' => 's-1',
					'favourite' => true,
					'tags' => ['finance', 'on call'],
					'lastUsedAt' => '2026-10-01T10:00:00+00:00',
				],
			],
			$service->collectMetadata('alice')['organisation']
		);
	}//end testGdprPackageCarriesTheOrganisation()

	/**
	 * A secret's child-data purge drops its tags; an account's purge drops
	 * every tag the holder set.
	 *
	 * @return void
	 */
	public function testCascadesDropTags(): void {
		$secretMapper = $this->createMock(SecretMapper::class);
		$secretMapper->method('findByOwner')->willReturn([]);
		$tagMapper = $this->createMock(SecretTagMapper::class);
		$tagMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
		$tagMapper->expects($this->once())->method('deleteByOwner')->with('alice');

		$cleaner = new SecretChildDataCleaner(secretMapper: $secretMapper, tagMapper: $tagMapper);
		$this->assertTrue($cleaner->hasCascades());

		$cleaner->purgeForSecret('s-1');
		$cleaner->purgeForOwnerUser('alice');
	}//end testCascadesDropTags()
}//end class
