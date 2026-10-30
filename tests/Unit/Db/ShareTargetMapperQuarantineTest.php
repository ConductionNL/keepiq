<?php

/**
 * Database-backed tests that every share-target finder hides quarantined rows
 * (GHSA-m5p9-6pw7-pf3g).
 *
 * The filter lives in each finder's SQL, so these run the real queries, one
 * test per finder: dropping the `quarantined_at IS NULL` clause from any one
 * finder turns its test red. Each test seeds a quarantined row next to a live
 * one that matches the same lookup.
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
use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Tests for the quarantine filter of ShareTargetMapper and
 * BulkGrantShareTargetMapper, and for QuarantinedShareTargetMapper.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareTargetMapperQuarantineTest extends TestCase {
	use ShareTargetDatabase;

	/**
	 * The mapper under test.
	 *
	 * @var ShareTargetMapper
	 */
	private ShareTargetMapper $mapper;

	/**
	 * The bulk-grant mapper under test.
	 *
	 * @var BulkGrantShareTargetMapper
	 */
	private BulkGrantShareTargetMapper $bulk;

	/**
	 * Open the database and build the mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->openDatabase();
		$this->mapper = new ShareTargetMapper(db: $this->db);
		$this->bulk   = new BulkGrantShareTargetMapper(db: $this->db);
	}//end setUp()

	/**
	 * Roll back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$this->closeDatabase();
		parent::tearDown();
	}//end tearDown()

	/**
	 * A live row and a quarantined row with the same source, recipient and
	 * grants (only their copies differ), so every lookup matches both.
	 *
	 * @param array<string,string> $grants Optional groupShareId / teamFolderId / expiresAt
	 *
	 * @return array{live:ShareTarget,quarantined:ShareTarget,source:string,owner:string,recipient:string}
	 */
	private function pair(array $grants = []): array {
		$owner     = $this->user('owner');
		$recipient = $this->user('recipient');
		$now       = new DateTime();
		$source    = $this->insertSecret(ownerId: $owner, createdAt: $now)->getId();

		return [
			'live' => $this->insertShareTarget(
				sourceSecretId: $source,
				targetUserId: $recipient,
				secretId: $this->insertSecret(ownerId: $recipient, createdAt: $now)->getId(),
				createdBy: $owner,
				createdAt: $now,
				grants: $grants,
			),
			'quarantined' => $this->insertShareTarget(
				sourceSecretId: $source,
				targetUserId: $recipient,
				secretId: $this->insertSecret(ownerId: $recipient, createdAt: $now)->getId(),
				createdBy: $owner,
				createdAt: $now,
				quarantined: true,
				grants: $grants,
			),
			'source' => $source,
			'owner' => $owner,
			'recipient' => $recipient,
		];
	}//end pair()

	/**
	 * The ids of a list of rows.
	 *
	 * @param ShareTarget[] $rows The rows
	 *
	 * @return list<string>
	 */
	private function ids(array $rows): array {
		return array_values(array_map(static fn (ShareTarget $row): string => $row->getId(), $rows));
	}//end ids()

	/**
	 * findById() does not return a quarantined row.
	 *
	 * @return void
	 */
	public function testFindByIdHidesAQuarantinedRow(): void {
		$pair = $this->pair();
		$this->assertSame($pair['live']->getId(), $this->mapper->findById($pair['live']->getId())->getId());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findById($pair['quarantined']->getId());
	}//end testFindByIdHidesAQuarantinedRow()

	/**
	 * findBySourceSecret() lists only the live row.
	 *
	 * @return void
	 */
	public function testFindBySourceSecretHidesAQuarantinedRow(): void {
		$pair = $this->pair();
		$this->assertSame([$pair['live']->getId()], $this->ids($this->mapper->findBySourceSecret($pair['source'])));
	}//end testFindBySourceSecretHidesAQuarantinedRow()

	/**
	 * findByTargetUser() lists only the live row.
	 *
	 * @return void
	 */
	public function testFindByTargetUserHidesAQuarantinedRow(): void {
		$pair = $this->pair();
		$this->assertSame([$pair['live']->getId()], $this->ids($this->mapper->findByTargetUser($pair['recipient'])));
	}//end testFindByTargetUserHidesAQuarantinedRow()

	/**
	 * findBySourceSecretAndTargetUser() finds the one live row; without the
	 * filter the quarantined row makes the lookup ambiguous.
	 *
	 * @return void
	 */
	public function testFindBySourceSecretAndTargetUserHidesAQuarantinedRow(): void {
		$pair = $this->pair();
		$found = $this->mapper->findBySourceSecretAndTargetUser(
			sourceSecretId: $pair['source'],
			targetUserId: $pair['recipient']
		);
		$this->assertSame($pair['live']->getId(), $found->getId());
	}//end testFindBySourceSecretAndTargetUserHidesAQuarantinedRow()

	/**
	 * hasDirectShareBetween() does not count a quarantined direct row, so a
	 * planted row never exempts its creator from the vault-key proof.
	 *
	 * @return void
	 */
	public function testHasDirectShareBetweenIgnoresAQuarantinedRow(): void {
		$creator = $this->user('creator');
		$target  = $this->user('target');
		$now     = new DateTime();
		$this->insertShareTarget(
			sourceSecretId: $this->insertSecret(ownerId: $creator, createdAt: $now)->getId(),
			targetUserId: $target,
			secretId: $this->insertSecret(ownerId: $target, createdAt: $now)->getId(),
			createdBy: $creator,
			createdAt: $now,
			quarantined: true,
		);

		$this->assertFalse($this->mapper->hasDirectShareBetween(createdBy: $creator, targetUserId: $target));
	}//end testHasDirectShareBetweenIgnoresAQuarantinedRow()

	/**
	 * findByRecipientSecret() does not walk a quarantined row from its copy to
	 * its source.
	 *
	 * @return void
	 */
	public function testFindByRecipientSecretHidesAQuarantinedRow(): void {
		$pair = $this->pair();
		$this->assertSame($pair['live']->getId(), $this->mapper->findByRecipientSecret($pair['live']->getSecretId())->getId());

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findByRecipientSecret($pair['quarantined']->getSecretId());
	}//end testFindByRecipientSecretHidesAQuarantinedRow()

	/**
	 * findEndingBetween() lists only the live expired row, so the expiry job
	 * never revokes a quarantined row.
	 *
	 * @return void
	 */
	public function testFindEndingBetweenHidesAQuarantinedRow(): void {
		$pair  = $this->pair(grants: ['expiresAt' => '-1 hour']);
		$ended = $this->ids($this->mapper->findEndingBetween(from: null, to: new DateTime()));

		$this->assertContains($pair['live']->getId(), $ended);
		$this->assertNotContains($pair['quarantined']->getId(), $ended);
	}//end testFindEndingBetweenHidesAQuarantinedRow()

	/**
	 * BulkGrantShareTargetMapper::findByGroupShare() lists only the live row.
	 *
	 * @return void
	 */
	public function testFindByGroupShareHidesAQuarantinedRow(): void {
		$groupShareId = Uuid::uuid4()->toString();
		$pair = $this->pair(grants: ['groupShareId' => $groupShareId]);
		$this->assertSame([$pair['live']->getId()], $this->ids($this->bulk->findByGroupShare($groupShareId)));
	}//end testFindByGroupShareHidesAQuarantinedRow()

	/**
	 * BulkGrantShareTargetMapper::findByTeamFolder() lists only the live row.
	 *
	 * @return void
	 */
	public function testFindByTeamFolderHidesAQuarantinedRow(): void {
		$teamFolderId = Uuid::uuid4()->toString();
		$pair = $this->pair(grants: ['teamFolderId' => $teamFolderId]);
		$this->assertSame([$pair['live']->getId()], $this->ids($this->bulk->findByTeamFolder($teamFolderId)));
	}//end testFindByTeamFolderHidesAQuarantinedRow()

	/**
	 * BulkGrantShareTargetMapper::findByTeamFolderAndTargetUser() lists only
	 * the live row.
	 *
	 * @return void
	 */
	public function testFindByTeamFolderAndTargetUserHidesAQuarantinedRow(): void {
		$teamFolderId = Uuid::uuid4()->toString();
		$pair = $this->pair(grants: ['teamFolderId' => $teamFolderId]);
		$this->assertSame(
			[$pair['live']->getId()],
			$this->ids($this->bulk->findByTeamFolderAndTargetUser($teamFolderId, $pair['recipient']))
		);
	}//end testFindByTeamFolderAndTargetUserHidesAQuarantinedRow()

	/**
	 * The quarantine finders return the quarantined row and never a live one.
	 *
	 * @return void
	 */
	public function testTheQuarantineFindersReturnOnlyQuarantinedRows(): void {
		$pair        = $this->pair();
		$quarantined = new QuarantinedShareTargetMapper(db: $this->db);
		$all         = $this->ids($quarantined->findQuarantined());

		$this->assertContains($pair['quarantined']->getId(), $all);
		$this->assertNotContains($pair['live']->getId(), $all);
		$this->assertSame([$pair['quarantined']->getId()], $this->ids($quarantined->findQuarantinedBySourceSecret($pair['source'])));
		$this->assertSame([$pair['quarantined']->getId()], $this->ids($quarantined->findQuarantinedByTargetUser($pair['recipient'])));
		$this->assertSame(
			$pair['quarantined']->getId(),
			$quarantined->findQuarantinedById($pair['quarantined']->getId())->getId()
		);

		$this->expectException(DoesNotExistException::class);
		$quarantined->findQuarantinedById($pair['live']->getId());
	}//end testTheQuarantineFindersReturnOnlyQuarantinedRows()

	/**
	 * The row-only cascade removes a quarantined row with its source, and never
	 * its copy.
	 *
	 * @return void
	 */
	public function testDeleteBySourceSecretRemovesAQuarantinedRowButNotItsCopy(): void {
		$pair = $this->pair();
		$this->mapper->deleteBySourceSecret($pair['source']);

		$this->assertNotContains(
			$pair['quarantined']->getId(),
			$this->ids((new QuarantinedShareTargetMapper(db: $this->db))->findQuarantined())
		);
		$copy = (new SecretMapper(db: $this->db))->findById($pair['quarantined']->getSecretId());
		$this->assertSame($pair['recipient'], $copy->getOwnerId());
	}//end testDeleteBySourceSecretRemovesAQuarantinedRowButNotItsCopy()

	/**
	 * Deleting a recipient's rows removes the quarantined one too and counts
	 * both.
	 *
	 * @return void
	 */
	public function testDeleteByTargetUserCountsAQuarantinedRow(): void {
		$pair = $this->pair();

		$this->assertSame(2, $this->mapper->deleteByTargetUser($pair['recipient']));
		$this->assertNotContains(
			$pair['quarantined']->getId(),
			$this->ids((new QuarantinedShareTargetMapper(db: $this->db))->findQuarantined())
		);
	}//end testDeleteByTargetUserCountsAQuarantinedRow()
}//end class
