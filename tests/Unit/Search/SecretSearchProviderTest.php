<?php

/**
 * Unit tests for SecretSearchProvider.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Search
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

namespace OCA\Keepiq\Tests\Unit\Search;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Search\SecretSearchProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The unified-search provider: identity, ordering, the empty-term short
 * circuit and the shape of each result entry (name, URL or "Secret" as
 * subline, a path deep link into the SPA).
 */
#[CoversClass(SecretSearchProvider::class)]
#[UsesClass(Secret::class)]
class SecretSearchProviderTest extends TestCase {
	private SecretMapper&MockObject $mapper;
	private IURLGenerator&MockObject $urlGenerator;
	private SecretSearchProvider $provider;

	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'T:'.$text);
		$this->urlGenerator->method('linkToRouteAbsolute')->with('keepiq.dashboard.page')->willReturn('https://cloud/apps/keepiq/');
		$this->urlGenerator->method('imagePath')->with('keepiq', 'app.svg')->willReturn('/apps/keepiq/img/app.svg');

		$this->provider = new SecretSearchProvider(mapper: $this->mapper, urlGenerator: $this->urlGenerator, l10n: $l10n);
	}

	private function query(string $term): ISearchQuery {
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);

		return $query;
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	private static function secret(string $id, string $name, ?string $url): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName($name);
		$secret->setUrl($url);

		return $secret;
	}

	public function testIdentity(): void {
		$this->assertSame('keepiq-secrets', $this->provider->getId());
		$this->assertSame('T:Secrets', $this->provider->getName());
	}

	public function testOrderIsFirstInsideKeepiqAndLaterElsewhere(): void {
		$this->assertSame(-1, $this->provider->getOrder('keepiq.dashboard.page', []));
		$this->assertSame(45, $this->provider->getOrder('files.view.index', []));
		$this->assertSame(45, $this->provider->getOrder('keepiqx.page.index', []));
	}

	public function testABlankTermReturnsNothingWithoutQuerying(): void {
		$this->mapper->expects($this->never())->method('findForUnifiedSearch');

		$result = $this->provider->search($this->user(), $this->query("  \t "))->jsonSerialize();

		$this->assertSame('T:Secrets', $result['name']);
		$this->assertSame([], $result['entries']);
		$this->assertFalse($result['isPaginated']);
	}

	public function testTheTrimmedTermIsSearchedForTheUserWithTheLimit(): void {
		$this->mapper->expects($this->once())->method('findForUnifiedSearch')->with('alice', 'router', 20)->willReturn([]);

		$result = $this->provider->search($this->user(), $this->query('  router '))->jsonSerialize();

		$this->assertSame([], $result['entries']);
	}

	public function testEntriesCarryNameSublineAndDeepLink(): void {
		$this->mapper->method('findForUnifiedSearch')->willReturn([
			self::secret('s-1', 'Router', 'https://router.local'),
			self::secret('s-2', 'Wifi', null),
			self::secret('s-3', 'Bank', ''),
		]);

		$entries = array_map(
			static fn ($entry): array => $entry->jsonSerialize(),
			$this->provider->search($this->user(), $this->query('r'))->jsonSerialize()['entries']
		);

		$this->assertCount(3, $entries);
		$this->assertSame(
			[
				'thumbnailUrl' => '/apps/keepiq/img/app.svg',
				'title' => 'Router',
				'subline' => 'https://router.local',
				'resourceUrl' => 'https://cloud/apps/keepiq/secrets/s-1',
				'icon' => '',
				'rounded' => false,
				'attributes' => [],
			],
			$entries[0]
		);
		$this->assertSame('T:Secret', $entries[1]['subline']);
		$this->assertSame('https://cloud/apps/keepiq/secrets/s-2', $entries[1]['resourceUrl']);
		$this->assertSame('T:Secret', $entries[2]['subline']);
	}
}
