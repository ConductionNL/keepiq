<?php

/**
 * Unit tests for RecentlyUsedService.
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
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\RecentlyUsedService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The Recently used widget source: one row per secret, newest first, only
 * secrets the caller still owns. The real AuditService sits between the
 * service and the audit mapper, so the query it asks for is the one that runs.
 *
 * @spec openspec/specs/vault-recently-used/spec.md#requirement-recently-used-on-the-dashboard
 */
class RecentlyUsedServiceTest extends TestCase {

	/**
	 * The audit entry mapper under the real AuditService.
	 *
	 * @var AuditEntryMapper&MockObject
	 */
	private AuditEntryMapper $auditMapper;

	/**
	 * The secret mapper.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secretMapper;

	/**
	 * The service under test.
	 *
	 * @var RecentlyUsedService
	 */
	private RecentlyUsedService $service;

	/**
	 * Build the service over the real AuditService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->auditMapper  = $this->createMock(AuditEntryMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->service      = new RecentlyUsedService(
			auditService: new AuditService(mapper: $this->auditMapper),
			secretMapper: $this->secretMapper,
		);
	}//end setUp()

	/**
	 * A secret.read audit row.
	 *
	 * @param string $secretId The secret id
	 * @param string $when     The read time
	 *
	 * @return AuditEntry
	 */
	private function read(string $secretId, string $when): AuditEntry {
		$entry = new AuditEntry();
		$entry->setEventType('secret.read');
		$entry->setObjectType('secret');
		$entry->setObjectId($secretId);
		$entry->setObjectName('old name of ' . $secretId);
		$entry->setOccurredAt(new DateTime($when));
		return $entry;
	}//end read()

	/**
	 * A secret owned by the given user.
	 *
	 * @param string $id    The secret id
	 * @param string $owner The owner uid
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $owner = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Secret ' . $id);
		$secret->setTypeId('type-login');
		$secret->setOwnerType('user');
		$secret->setOwnerId($owner);
		return $secret;
	}//end secret()

	/**
	 * Resolve findById from a map; a missing id throws like the real mapper.
	 *
	 * @param array<string,Secret> $secrets The secrets by id
	 *
	 * @return void
	 */
	private function withSecrets(array $secrets): void {
		$this->secretMapper->method('findById')->willReturnCallback(
			function (string $id) use ($secrets): Secret {
				if (isset($secrets[$id]) === false) {
					throw new DoesNotExistException('gone');
				}
				return $secrets[$id];
			}
		);
	}//end withSecrets()

	/**
	 * Three secrets read, one twice: three rows, the twice-read one once, newest first.
	 *
	 * @return void
	 */
	public function testDistinctSecretsNewestFirst(): void {
		$this->auditMapper->expects($this->once())
			->method('findRecentReadsByActor')
			->with('alice', RecentlyUsedService::SCAN_WINDOW)
			->willReturn(
				[
					$this->read('s-b', '2026-09-29 12:00:00'),
					$this->read('s-a', '2026-09-29 11:00:00'),
					$this->read('s-b', '2026-09-29 10:00:00'),
					$this->read('s-c', '2026-09-29 09:00:00'),
				]
			);
		$this->withSecrets(
			[
				's-a' => $this->secret('s-a'),
				's-b' => $this->secret('s-b'),
				's-c' => $this->secret('s-c'),
			]
		);

		$rows = $this->service->forUser('alice');

		$this->assertSame(['s-b', 's-a', 's-c'], array_column($rows, 'id'));
		$this->assertSame('Secret s-b', $rows[0]['name']);
		$this->assertSame('type-login', $rows[0]['typeId']);
		$this->assertSame((new DateTime('2026-09-29 12:00:00'))->format(DATE_ATOM), $rows[0]['lastUsedAt']);
	}//end testDistinctSecretsNewestFirst()

	/**
	 * A deleted, a transferred and a tombstoned secret are not listed.
	 *
	 * @return void
	 */
	public function testSecretsNoLongerHeldAreDropped(): void {
		$tombstoned = $this->secret('s-tomb');
		$tombstoned->setTombstonedAt(new DateTime('2026-09-28'));
		$this->auditMapper->method('findRecentReadsByActor')->willReturn(
			[
				$this->read('s-deleted', '2026-09-29 12:00:00'),
				$this->read('s-moved', '2026-09-29 11:00:00'),
				$this->read('s-tomb', '2026-09-29 10:30:00'),
				$this->read('s-kept', '2026-09-29 10:00:00'),
			]
		);
		$this->withSecrets(
			[
				's-moved' => $this->secret('s-moved', 'bob'),
				's-tomb' => $tombstoned,
				's-kept' => $this->secret('s-kept'),
			]
		);

		$this->assertSame(['s-kept'], array_column($this->service->forUser('alice'), 'id'));
	}//end testSecretsNoLongerHeldAreDropped()

	/**
	 * At most five rows, and each secret is looked up once.
	 *
	 * @return void
	 */
	public function testCapsAtFiveRows(): void {
		$entries = [];
		$secrets = [];
		for ($i = 0; $i < 8; $i++) {
			$entries[]         = $this->read('s-' . $i, '2026-09-29 12:0' . $i . ':00');
			$entries[]         = $this->read('s-' . $i, '2026-09-29 11:0' . $i . ':00');
			$secrets['s-' . $i] = $this->secret('s-' . $i);
		}
		$this->auditMapper->method('findRecentReadsByActor')->willReturn($entries);
		$this->secretMapper->expects($this->exactly(5))->method('findById')->willReturnCallback(
			fn (string $id): Secret => $secrets[$id]
		);

		$this->assertCount(5, $this->service->forUser('alice'));
	}//end testCapsAtFiveRows()

	/**
	 * No reads: an empty list, so the widget shows its empty state.
	 *
	 * @return void
	 */
	public function testNoReadsIsEmpty(): void {
		$this->auditMapper->method('findRecentReadsByActor')->willReturn([]);

		$this->assertSame([], $this->service->forUser('alice'));
	}//end testNoReadsIsEmpty()
}//end class
