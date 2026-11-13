<?php

/**
 * Unit tests for the keepiq.listEntries MCP tool.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Mcp
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

namespace OCA\Keepiq\Tests\Unit\Mcp;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Mcp\EntryMetadataTools;
use OCA\Keepiq\Mcp\McpToolContext;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * EntryMetadataTools: which read path is taken, with which arguments, and what is returned.
 *
 * @covers \OCA\Keepiq\Mcp\EntryMetadataTools
 * @uses   \OCA\Keepiq\Mcp\McpToolContext
 * @uses   \OCA\Keepiq\Mcp\MetadataAllowList
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 */
class EntryMetadataToolsTest extends TestCase {

	/** @var SecretService&MockObject */
	private SecretService $secrets;

	/** @var EncryptionSuiteMapper&MockObject */
	private EncryptionSuiteMapper $suites;

	/** @var list<AuditEvent> */
	private array $audits = [];

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secrets = $this->createMock(SecretService::class);
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
	}//end setUp()

	/**
	 * The tool for session user alice.
	 *
	 * @return EntryMetadataTools
	 */
	private function tools(): EntryMetadataTools {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->audits[] = $event;
		});

		return new EntryMetadataTools(
			secretService: $this->secrets,
			suiteMapper: $this->suites,
			context: new McpToolContext(userSession: $session, dispatcher: $dispatcher)
		);
	}//end tools()

	/**
	 * Without a query the list path runs with the folder and type filters, page 1, limit 200.
	 *
	 * @return void
	 */
	public function testListPathPassesFiltersAndLimit(): void {
		$this->suites->expects($this->once())->method('findActiveByOwner')->with('user', 'alice')->willReturn(new EncryptionSuite());
		$this->secrets->expects($this->never())->method('search');
		$this->secrets->expects($this->once())->method('list')
			->with('alice', 'f1', null, 'asc', 1, 200, 't1')
			->willReturn(['items' => [
				['id' => 'e1', 'name' => 'one', 'key' => 'CIPHER', 'folderId' => 'f1', 'typeId' => 't1'],
				['id' => 'e2', 'name' => 'two', 'login' => 'CIPHER', 'folderId' => 'f1', 'typeId' => 't1'],
			]]);

		$result = $this->tools()->listEntries(folderId: 'f1', typeId: 't1');

		$this->assertSame(
			[
				'entries' => [
					['id' => 'e1', 'name' => 'one', 'typeId' => 't1', 'folderId' => 'f1'],
					['id' => 'e2', 'name' => 'two', 'typeId' => 't1', 'folderId' => 'f1'],
				],
				'total' => 2,
			],
			$result
		);
		$this->assertSame(['tool' => 'listEntries', 'resultCount' => 2], $this->audits[0]->getMetadata());
	}//end testListPathPassesFiltersAndLimit()

	/**
	 * A blank query is no query: the list path runs, not search.
	 *
	 * @return void
	 */
	public function testBlankQueryUsesTheListPath(): void {
		$this->suites->method('findActiveByOwner')->willReturn(new EncryptionSuite());
		$this->secrets->expects($this->never())->method('search');
		$this->secrets->expects($this->once())->method('list')->willReturn(['items' => []]);

		$this->assertSame(['entries' => [], 'total' => 0], $this->tools()->listEntries(query: "  \t"));
	}//end testBlankQueryUsesTheListPath()

	/**
	 * A query searches with limit 200, then keeps only rows matching the type filter, re-indexed.
	 *
	 * @return void
	 */
	public function testSearchPathFiltersByTypeAndReindexes(): void {
		$this->suites->method('findActiveByOwner')->willReturn(new EncryptionSuite());
		$this->secrets->expects($this->never())->method('list');
		$this->secrets->expects($this->once())->method('search')->with('alice', 'db', 1, 200)->willReturn(['items' => [
			['id' => 'e1', 'name' => 'db one', 'typeId' => 'web'],
			['id' => 'e2', 'name' => 'db two', 'typeId' => 'db'],
			['id' => 'e3', 'name' => 'db three'],
		]]);

		$result = $this->tools()->listEntries(typeId: 'db', query: 'db');

		$this->assertSame([['id' => 'e2', 'name' => 'db two', 'typeId' => 'db']], $result['entries']);
		$this->assertSame(1, $result['total']);
	}//end testSearchPathFiltersByTypeAndReindexes()

	/**
	 * Without an active suite nothing is read, and the empty answer is still audited.
	 *
	 * @return void
	 */
	public function testNoActiveSuiteReadsNothingButAudits(): void {
		$this->suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		$this->secrets->expects($this->never())->method('list');
		$this->secrets->expects($this->never())->method('search');

		$this->assertSame(['entries' => [], 'total' => 0], $this->tools()->listEntries(query: 'x'));
		$this->assertCount(1, $this->audits);
		$this->assertSame(['tool' => 'listEntries', 'resultCount' => 0], $this->audits[0]->getMetadata());
	}//end testNoActiveSuiteReadsNothingButAudits()
}//end class
