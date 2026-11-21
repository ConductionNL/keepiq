<?php

/**
 * The expiry job never deletes the victim's secret behind a quarantined
 * planted row (GHSA-m5p9-6pw7-pf3g).
 *
 * The attacker owns S_a and planted {S_a, victim, V}, where V is the victim's
 * own secret, with an access end date that has passed: on the row itself, or
 * on the group share it is linked to. The real ShareExpiryService runs against
 * the real database (the quarantine filter is SQL), inside a transaction that
 * is rolled back. Each scenario is run with the row live as well, to show the
 * same run does delete V without the quarantine. The row is planted in V's
 * own second, so a pending quarantine scan on the instance does not hold its
 * copy back either.
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
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\ShareExpiryService;
use OCA\Keepiq\Tests\Support\ShareTargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Tests that the expiry job leaves a quarantined row's copy alone.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class QuarantinedRowExpiryTest extends TestCase {
	use ShareTargetDatabase;

	/**
	 * The attacker.
	 *
	 * @var string
	 */
	private string $attacker = '';

	/**
	 * The victim.
	 *
	 * @var string
	 */
	private string $victim = '';

	/**
	 * The attacker's source S_a.
	 *
	 * @var Secret
	 */
	private Secret $sourceA;

	/**
	 * The victim's own secret V.
	 *
	 * @var Secret
	 */
	private Secret $secretV;

	/**
	 * Open the database and seed both users' secrets.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->openDatabase();

		$this->attacker = $this->user('attacker');
		$this->victim   = $this->user('victim');
		$this->sourceA  = $this->insertSecret(ownerId: $this->attacker, createdAt: new DateTime('-2 days'), name: 'S_a');
		$this->secretV  = $this->insertSecret(ownerId: $this->victim, createdAt: new DateTime('-1 day'), name: 'V');
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
	 * The planted row, quarantined or live.
	 *
	 * @return array<string,array{0:bool}>
	 */
	public static function rowStates(): array {
		return [
			'quarantined' => [true],
			'live (the exploit)' => [false],
		];
	}//end rowStates()

	/**
	 * Whether a row with this id is in the table.
	 *
	 * @param string $table The table, without prefix
	 * @param string $id    The row id
	 *
	 * @return bool
	 */
	private function exists(string $table, string $id): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($table)->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		$result = $qb->executeQuery();
		$found  = $result->fetch();
		$result->closeCursor();

		return $found !== false;
	}//end exists()

	/**
	 * A planted row whose own end date passed, with that end date written onto
	 * V: the expiry job leaves both the quarantined row and V.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testTheExpiryJobKeepsVBehindAnExpiredQuarantinedRow(bool $quarantined): void {
		$this->secretV->setUseOnly(true);
		$this->secretV->setAccessExpiresAt(new DateTime('-1 hour'));
		(new SecretMapper(db: $this->db))->update($this->secretV);
		$row = $this->insertShareTarget(
			sourceSecretId: $this->sourceA->getId(),
			targetUserId: $this->victim,
			secretId: $this->secretV->getId(),
			createdBy: $this->attacker,
			createdAt: clone $this->secretV->getCreatedAt(),
			quarantined: $quarantined,
			grants: ['expiresAt' => '-1 hour'],
		);

		\OC::$server->get(ShareExpiryService::class)->expire(new DateTime());

		$this->assertSame($quarantined, $this->exists('keepiq_secrets', $this->secretV->getId()), 'V survives only when the row is quarantined');
		$this->assertSame($quarantined, $this->exists('keepiq_share_targets', $row->getId()), 'the quarantined row is left for the administrator');
	}//end testTheExpiryJobKeepsVBehindAnExpiredQuarantinedRow()

	/**
	 * A planted row linked to a group share whose end date passed: the group
	 * share's removal removes the row either way, but deletes V only when the
	 * row is live.
	 *
	 * @param bool $quarantined Whether the planted row is quarantined
	 *
	 * @return void
	 */
	#[DataProvider('rowStates')]
	public function testAnExpiredGroupShareRemovesTheQuarantinedRowButKeepsV(bool $quarantined): void {
		$groupShare = new GroupShare();
		$groupShare->setId(Uuid::uuid4()->toString());
		$groupShare->setSecretId($this->sourceA->getId());
		$groupShare->setGroupId('qt-group');
		$groupShare->setCreatedBy($this->attacker);
		$groupShare->setCreatedAt(new DateTime('-2 hours'));
		$groupShare->setUseOnly(false);
		$groupShare->setExpiresAt(new DateTime('-1 hour'));
		(new GroupShareMapper(db: $this->db))->insert($groupShare);
		$row = $this->insertShareTarget(
			sourceSecretId: $this->sourceA->getId(),
			targetUserId: $this->victim,
			secretId: $this->secretV->getId(),
			createdBy: $this->attacker,
			createdAt: clone $this->secretV->getCreatedAt(),
			quarantined: $quarantined,
			grants: ['groupShareId' => $groupShare->getId()],
		);

		\OC::$server->get(ShareExpiryService::class)->expire(new DateTime());

		$this->assertSame($quarantined, $this->exists('keepiq_secrets', $this->secretV->getId()), 'V survives only when the row is quarantined');
		$this->assertFalse($this->exists('keepiq_share_targets', $row->getId()), 'the group share removal removes the row');
	}//end testAnExpiredGroupShareRemovesTheQuarantinedRowButKeepsV()
}//end class
