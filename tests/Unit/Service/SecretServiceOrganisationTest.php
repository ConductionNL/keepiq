<?php

/**
 * SecretService with favourites, tags and last used (vault-favourites-tags-and-last-used).
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTagMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Opening a value stamps last used; the list carries tags and filters; a delete drops the tags.
 */
class SecretServiceOrganisationTest extends TestCase {
	/** @var SecretMapper&MockObject */
	private SecretMapper $mapper;

	/** @var SecretTagMapper&MockObject */
	private SecretTagMapper $tags;

	private SecretService $service;

	/**
	 * Wire the real service; s-1 is alice's.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(SecretMapper::class);
		$this->tags   = $this->createMock(SecretTagMapper::class);

		$this->service = new SecretService(
			mapper: $this->mapper,
			typeService: $this->createMock(SecretTypeService::class),
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			tagMapper: $this->tags,
		);

		$secret = new Secret();
		$secret->setId('s-1');
		$secret->setName('Router');
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		$this->mapper->method('findById')->willReturn($secret);
	}//end setUp()

	/**
	 * Opening the value stamps last used on the holder's row.
	 *
	 * @return void
	 */
	public function testGetStampsLastUsed(): void {
		$this->mapper->expects($this->once())->method('markUsed')->with('s-1', 'alice', $this->anything());

		$this->service->get('s-1', 'alice');
	}//end testGetStampsLastUsed()

	/**
	 * Another user opening it is refused and stamps nothing.
	 *
	 * @return void
	 */
	public function testAnotherUserStampsNothing(): void {
		$this->mapper->expects($this->never())->method('markUsed');

		$this->expectException(ForbiddenException::class);
		$this->service->get('s-1', 'bob');
	}//end testAnotherUserStampsNothing()

	/**
	 * Listing passes the favourite and tag filters, stamps nothing, and
	 * carries each row's tags.
	 *
	 * @return void
	 */
	public function testListFiltersCarriesTagsAndStampsNothing(): void {
		$row = new Secret();
		$row->setId('s-1');
		$row->setOwnerType('user');
		$row->setOwnerId('alice');
		$args = [];
		$this->mapper->method('findByOwner')->willReturnCallback(
			function (...$given) use (&$args, $row): array {
				$args = $given;
				return [$row];
			}
		);
		$this->mapper->method('countByOwner')->willReturn(1);
		$this->mapper->expects($this->never())->method('markUsed');
		$this->tags->method('findTagsBySecretIds')->with('alice', ['s-1'])->willReturn(['s-1' => ['finance']]);

		$result = $this->service->list('alice', null, 'last_used_at', 'desc', 1, 50, null, SecretMapper::STATE_LIVE, true, 'finance');

		$this->assertTrue($args[9]);
		$this->assertSame('finance', $args[10]);
		$this->assertSame(['finance'], $result['items'][0]['tags']);
	}//end testListFiltersCarriesTagsAndStampsNothing()

	/**
	 * A secret deleted for good leaves no tag rows behind.
	 *
	 * @return void
	 */
	public function testDeleteDropsTheTags(): void {
		$this->tags->expects($this->once())->method('deleteBySecret')->with('s-1');

		$this->service->delete('s-1', 'alice');
	}//end testDeleteDropsTheTags()
}//end class
