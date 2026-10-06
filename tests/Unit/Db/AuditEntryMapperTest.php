<?php

/**
 * Database-backed tests for AuditEntryMapper::findFiltered().
 *
 * A compromise force-revoke skips its containment on a retry only when this
 * query finds a complete `suite.compromise_contained` entry for the suite
 * since its migration started (keepiq#1189). The filters live in the SQL, so a
 * mocked IQueryBuilder would only echo its own arguments; these tests run the
 * real query, the way SecretRequestMapperTest does, and skip on a checkout
 * without a database. CI runs them against pgsql.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuditEntryMapper::findFiltered().
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-compromise-force-revoke-contains-the-account
 */
class AuditEntryMapperTest extends TestCase {

	/**
	 * The live database connection, or null when this suite runs without Nextcloud.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The mapper under test.
	 *
	 * @var AuditEntryMapper|null
	 */
	private ?AuditEntryMapper $mapper = null;

	/**
	 * A suite id unique to the running test, so real rows never match.
	 *
	 * @var string
	 */
	private string $suiteId = '';

	/**
	 * Resolve a real connection, or skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);

		if ($this->db->tableExists('keepiq_audit_log') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->mapper = new AuditEntryMapper(db: $this->db);
		$this->suiteId = 'test-suite-' . bin2hex(random_bytes(8));
	}//end setUp()

	/**
	 * Remove every row this test inserted, whether it passed or failed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->db !== null) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('keepiq_audit_log')
				->where($delete->expr()->like('object_id', $delete->createNamedParameter($this->suiteId . '%')));
			$delete->executeStatement();
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Insert one audit row.
	 *
	 * @param string   $eventType  The event type
	 * @param string   $objectId   The object id
	 * @param DateTime $occurredAt When it happened
	 *
	 * @return AuditEntry
	 */
	private function insertEntry(string $eventType, string $objectId, DateTime $occurredAt): AuditEntry {
		$entry = new AuditEntry();
		$entry->setOccurredAt($occurredAt);
		$entry->setActorType('user');
		$entry->setActorId('admin');
		$entry->setEventType($eventType);
		$entry->setObjectType('suite');
		$entry->setObjectId($objectId);
		$entry->setObjectName('');
		$entry->setMetadata((string)json_encode(['failed' => 0]));

		return $this->mapper->insert($entry);
	}//end insertEntry()

	/**
	 * The filters the containment skip uses.
	 *
	 * @param DateTime $from The migration's start
	 *
	 * @return array<string,mixed>
	 */
	private function containmentFilters(DateTime $from): array {
		return [
			'eventType' => AuditEventTypes::SUITE_COMPROMISE_CONTAINED,
			'objectType' => 'suite',
			'objectId' => $this->suiteId,
			'from' => $from,
		];
	}//end containmentFilters()

	/**
	 * A containment recorded before the migration started does not count.
	 *
	 * @return void
	 */
	public function testAnEntryBeforeTheMigrationIsExcluded(): void {
		$this->insertEntry(AuditEventTypes::SUITE_COMPROMISE_CONTAINED, $this->suiteId, new DateTime('-2 days'));

		$found = $this->mapper->findFiltered(filters: $this->containmentFilters(new DateTime('-1 day')), limit: 1);

		$this->assertSame([], $found);
	}//end testAnEntryBeforeTheMigrationIsExcluded()

	/**
	 * Another event type, or another suite's containment, does not count.
	 *
	 * @return void
	 */
	public function testOtherEventsAndSuitesAreExcluded(): void {
		$this->insertEntry(AuditEventTypes::SUITE_REVOKED, $this->suiteId, new DateTime());
		$this->insertEntry(AuditEventTypes::SUITE_COMPROMISE_CONTAINED, $this->suiteId . '-other', new DateTime());

		$found = $this->mapper->findFiltered(filters: $this->containmentFilters(new DateTime('-1 day')), limit: 1);

		$this->assertSame([], $found);
	}//end testOtherEventsAndSuitesAreExcluded()

	/**
	 * The newest matching containment is returned, so the latest attempt decides.
	 *
	 * @return void
	 */
	public function testTheNewestMatchingEntryIsReturned(): void {
		$this->insertEntry(AuditEventTypes::SUITE_COMPROMISE_CONTAINED, $this->suiteId, new DateTime('-2 hours'));
		$newest = $this->insertEntry(AuditEventTypes::SUITE_COMPROMISE_CONTAINED, $this->suiteId, new DateTime('-1 hour'));

		$found = $this->mapper->findFiltered(filters: $this->containmentFilters(new DateTime('-1 day')), limit: 1);

		$this->assertCount(1, $found);
		$this->assertSame($newest->getId(), $found[0]->getId());
	}//end testTheNewestMatchingEntryIsReturned()
}//end class
