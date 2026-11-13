<?php

/**
 * Unit tests for ShareSyncGuards.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSync
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSync;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSyncService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the guards a share sync runs before it writes.
 *
 * @covers \OCA\Keepiq\Service\ShareSync\ShareSyncGuards
 * @uses   \OCA\Keepiq\Service\ShareSync\LiveCopiesRun
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 * @uses   \OCA\Keepiq\Service\FederatedValueFields
 * @uses   \OCA\Keepiq\Service\JudgedShareRows
 * @uses   \OCA\Keepiq\Service\ShareSave\ShareSaveEntries
 * @uses   \OCA\Keepiq\Service\ShareSave\ShareSaveEntryParser
 * @uses   \OCA\Keepiq\Service\ShareSave\ShareSaveRefusal
 */
class ShareSyncGuardsTest extends TestCase {
	private ShareRowJudge&MockObject $rowJudge;

	private SecretMapper&MockObject $secrets;

	private EncryptionSuiteMapper&MockObject $suites;

	private ShareAuthorizationService&MockObject $auth;

	private ShareSyncGuards $guards;

	protected function setUp(): void {
		$this->rowJudge = $this->createMock(ShareRowJudge::class);
		$this->secrets  = $this->createMock(SecretMapper::class);
		$this->suites   = $this->createMock(EncryptionSuiteMapper::class);
		$this->auth     = $this->createMock(ShareAuthorizationService::class);
		$this->guards   = new ShareSyncGuards($this->rowJudge, $this->secrets, $this->suites, $this->auth);
	}

	private function source(?string $totp = null, ?string $passkeys = null): Secret {
		$source = new Secret();
		$source->setId('src');
		$source->setOwnerType('user');
		$source->setOwnerId('owner');
		if ($totp !== null) {
			$source->setTotp($totp);
		}
		if ($passkeys !== null) {
			$source->setPasskeys($passkeys);
		}

		return $source;
	}

	private function target(int $id, string $copyId, string $userId): ShareTarget {
		$target = new ShareTarget();
		$target->setId($id);
		$target->setSecretId($copyId);
		$target->setTargetUserId($userId);

		return $target;
	}

	private function copy(string $copyId, string $userId): Secret {
		$copy = new Secret();
		$copy->setId($copyId);
		$copy->setOwnerType('user');
		$copy->setOwnerId($userId);

		return $copy;
	}

	/**
	 * Rows c1 (bob) and c2 (carol), c2 judged by $verdictForC2.
	 */
	private function twoCopies(?string $verdictForC2 = null): JudgedShareRows {
		$this->secrets->method('findByIds')->willReturn(
			['c1' => $this->copy('c1', 'bob'), 'c2' => $this->copy('c2', 'carol')]
		);

		return new JudgedShareRows(
			[$this->target(1, 'c1', 'bob'), $this->target(2, 'c2', 'carol')],
			static fn (ShareTarget $row): ?string => ((string)$row->getId() === '2' ? $verdictForC2 : null)
		);
	}

	private function pendingFromJudge(): ShareScanPendingException {
		$pending = new ShareScanPendingException('wait');
		$this->rowJudge->expects($this->once())->method('scanPending')
			->with('This secret waits for the share quarantine scan and cannot be saved yet')
			->willReturn($pending);

		return $pending;
	}

	public function testAssertWritableAcceptsAnOrdinarySecret(): void {
		$this->guards->assertWritable($this->source());
		$this->addToAssertionCount(1);
	}

	public function testAssertWritableRefusesAReadOnlyCopy(): void {
		$source = $this->source();
		$source->setReadOnly(true);

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage(Secret::READ_ONLY_REFUSAL);
		$this->guards->assertWritable($source);
	}

	public function testAssertWritableRefusesAUseOnlyCopy(): void {
		$source = $this->source();
		$source->setUseOnly(true);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A use-only copy cannot be changed');
		$this->guards->assertWritable($source);
	}

	public function testAssertBaseNamedRequiresTheBaseOnlyForAGroupWrite(): void {
		$this->guards->assertBaseNamed(ShareSyncService::GROUP_WRITE, '2026-01-01T00:00:00+00:00');
		$this->guards->assertBaseNamed(ShareSyncService::TEAM_WRITE, '');
		$this->guards->assertBaseNamed(null, '');
		$this->addToAssertionCount(3);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('expectedUpdatedAt is required when saving as a group member');
		$this->guards->assertBaseNamed(ShareSyncService::GROUP_WRITE, '');
	}

	public function testJudgedRowsOfAsksTheJudgeForTheSource(): void {
		$before = new JudgedShareRows([], static fn (): ?string => null);
		$after  = new JudgedShareRows([], static fn (): ?string => null);
		$this->rowJudge->expects($this->once())->method('rowsOfSource')->with('src', $before)->willReturn($after);

		$this->assertSame($after, $this->guards->judgedRowsOf($this->source(), $before));
	}

	public function testLiveCopiesLeavesOutTheNamedCopy(): void {
		$judged = $this->twoCopies();

		$result = $this->guards->liveCopies($this->source(), 'c1', $judged);

		$this->assertSame(['c2'], array_keys($result['copies']));
		$this->assertSame([], $result['ignored']);
	}

	public function testAssertCopiesJudgedOnlyCountsNamedCopiesOutsideAGroupWrite(): void {
		$judged = $this->twoCopies(ShareQuarantineScanner::HELD_UNJUDGED);
		$this->rowJudge->expects($this->never())->method('scanPending');

		$this->guards->assertCopiesJudged($this->source(), [['secretId' => 'c1']], ShareSyncService::TEAM_WRITE, $judged);
		$this->addToAssertionCount(1);
	}

	public function testAssertCopiesJudgedRefusesANamedUnjudgedCopy(): void {
		$judged  = $this->twoCopies(ShareQuarantineScanner::HELD_UNJUDGED);
		$pending = $this->pendingFromJudge();

		$this->expectExceptionObject($pending);
		$this->guards->assertCopiesJudged($this->source(), [['secretId' => 'c2']], null, $judged);
	}

	public function testAssertCopiesJudgedRefusesAnyUnjudgedCopyInAGroupWrite(): void {
		$judged  = $this->twoCopies(ShareQuarantineScanner::HELD_UNJUDGED);
		$pending = $this->pendingFromJudge();

		$this->expectExceptionObject($pending);
		$this->guards->assertCopiesJudged($this->source(), [], ShareSyncService::GROUP_WRITE, $judged);
	}

	public function testHolderHasVaultKeyAsksForTheRecipient(): void {
		$this->suites->expects($this->once())->method('countActiveByOwner')->with('user', 'bob')->willReturn(1);

		$this->assertTrue(
			$this->guards->holderHasVaultKey($this->source(), ['holder' => 'recipient', 'targetUserId' => 'bob'])
		);
	}

	public function testHolderHasVaultKeyAsksForTheSourceOwner(): void {
		$this->suites->expects($this->once())->method('countActiveByOwner')->with('user', 'owner')->willReturn(0);

		$this->assertFalse(
			$this->guards->holderHasVaultKey($this->source(), ['holder' => 'owner', 'targetUserId' => null])
		);
	}

	public function testAssertNoDuplicatesAcceptsDistinctRows(): void {
		$this->guards->assertNoDuplicates(['a' => ['secretId' => 'x'], 'b' => ['secretId' => 'y']]);
		$this->addToAssertionCount(1);
	}

	public function testAssertNoDuplicatesRefusesARowNamedTwice(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An entry is named twice');
		$this->guards->assertNoDuplicates([['secretId' => 'x'], ['secretId' => 'x']]);
	}

	public function testMirroredValueFieldsClearsFieldsTheSourceDoesNotHold(): void {
		$updates = [['secretId' => 'src', 'key' => 'k'], ['secretId' => 'c1', 'key' => 'k1'], ['secretId' => 'c2']];

		$this->assertSame(
			[
				['secretId' => 'src', 'key' => 'k', 'totp' => null, 'passkeys' => null],
				['secretId' => 'c1', 'key' => 'k1', 'totp' => null, 'passkeys' => null],
				['secretId' => 'c2'],
			],
			$this->guards->mirroredValueFields($this->source(), $updates)
		);
	}

	public function testMirroredValueFieldsLeavesAHeldFieldUntouchedWhenNoRowCarriesIt(): void {
		$updates = [['secretId' => 'src', 'key' => 'k'], ['secretId' => 'c1', 'login' => 'l']];

		$this->assertSame(
			[
				['secretId' => 'src', 'key' => 'k', 'passkeys' => null],
				['secretId' => 'c1', 'login' => 'l', 'passkeys' => null],
			],
			$this->guards->mirroredValueFields($this->source('T'), $updates)
		);
	}

	public function testMirroredValueFieldsAcceptsEveryRowCarryingAHeldField(): void {
		$updates = [
			['secretId' => 'src', 'key' => 'k', 'totp' => 'T1', 'passkeys' => 'P1'],
			['secretId' => 'c1', 'key' => 'k1', 'totp' => 'T2', 'passkeys' => 'P2'],
		];

		$this->assertSame($updates, $this->guards->mirroredValueFields($this->source('T', 'P'), $updates));
	}

	public function testMirroredValueFieldsRefusesARowMissingAFieldOthersCarry(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Every row must carry the totp the source holds, and none it does not');
		$this->guards->mirroredValueFields(
			$this->source('T'),
			[['secretId' => 'src', 'key' => 'k', 'totp' => 'T1'], ['secretId' => 'c1', 'key' => 'k1']]
		);
	}

	public function testMirroredValueFieldsRefusesAFieldTheSourceDropped(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Every row must carry the passkeys the source holds, and none it does not');
		$this->guards->mirroredValueFields(
			$this->source(null, 'P'),
			[['secretId' => 'src', 'key' => 'k', 'passkeys' => ''], ['secretId' => 'c1', 'key' => 'k1', 'passkeys' => 'P2']]
		);
	}

	public function testMirroredValueFieldsIgnoresNonArrayEntries(): void {
		$updates = ['bogus', ['secretId' => 'src', 'key' => 'k']];

		$result = $this->guards->mirroredValueFields($this->source(), $updates);

		$this->assertSame('bogus', $result[0]);
		$this->assertSame(['secretId' => 'src', 'key' => 'k', 'totp' => null, 'passkeys' => null], $result[1]);
	}

	private function groupWrite(array $updates, ?JudgedShareRows $judged, ?Secret $current = null): array {
		$source = $this->source();
		$this->auth->expects($this->once())->method('loadSecret')->with('src')->willReturn($current ?? $source);
		$sync = $this->createMock(ShareSyncService::class);
		$sync->method('resolveSyncWriter')->willReturn(ShareSyncService::GROUP_WRITE);

		return $this->guards->assertGroupWrite($sync, $source, $updates, 'bob', $judged);
	}

	public function testAssertGroupWriteReturnsTheWriterAndEveryRowToWrite(): void {
		$judged = $this->twoCopies();

		$this->assertSame(
			[ShareSyncService::GROUP_WRITE, ['src' => true, 'c1' => true, 'c2' => true]],
			$this->groupWrite(
				[['secretId' => 'src', 'key' => 'a'], ['secretId' => 'c1', 'key' => 'b'], ['secretId' => 'c2', 'key' => 'c']],
				$judged
			)
		);
	}

	public function testAssertGroupWriteRechecksTheCurrentRow(): void {
		$current = $this->source();
		$current->setUseOnly(true);
		$this->secrets->expects($this->never())->method('findByIds');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A use-only copy cannot be changed');
		$this->groupWrite([], null, $current);
	}

	public function testAssertGroupWriteRefusesAnEmptyKey(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A key may not be empty');
		$this->groupWrite([['secretId' => 'src', 'key' => '']], null);
	}

	public function testAssertGroupWriteRefusesADuplicateRow(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An entry is named twice');
		$this->groupWrite([['secretId' => 'src', 'key' => 'a'], ['secretId' => 'src', 'key' => 'b']], null);
	}

	public function testAssertGroupWriteWaitsForAnUnjudgedCopy(): void {
		$judged  = $this->twoCopies(ShareQuarantineScanner::HELD_UNJUDGED);
		$pending = $this->pendingFromJudge();

		$this->expectExceptionObject($pending);
		$this->groupWrite([['secretId' => 'src', 'key' => 'a']], $judged);
	}

	public function testAssertGroupWriteRefusesAMissingCopy(): void {
		$judged = $this->twoCopies();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Name the source and every copy whose access has not ended, and nothing else');
		$this->groupWrite([['secretId' => 'src', 'key' => 'a'], ['secretId' => 'c1', 'key' => 'b']], $judged);
	}

	public function testAssertGroupWriteRefusesAnUnknownRow(): void {
		$judged = $this->twoCopies();

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Name the source and every copy whose access has not ended, and nothing else');
		$this->groupWrite(
			[
				['secretId' => 'src', 'key' => 'a'],
				['secretId' => 'c1', 'key' => 'b'],
				['secretId' => 'c2', 'key' => 'c'],
				['secretId' => 'other', 'key' => 'd'],
			],
			$judged
		);
	}

	public function testAssertGroupWriteToleratesNamingAnIgnoredCopy(): void {
		$judged = $this->twoCopies(ShareQuarantineScanner::HELD_QUARANTINED);

		$this->assertSame(
			[ShareSyncService::GROUP_WRITE, ['src' => true, 'c1' => true]],
			$this->groupWrite(
				[['secretId' => 'src', 'key' => 'a'], ['secretId' => 'c1', 'key' => 'b'], ['secretId' => 'c2', 'key' => 'c']],
				$judged
			)
		);
	}

	public function testAssertGroupWriteSkipsAValuelessRowWhoseHolderHasNoVaultKey(): void {
		$judged = $this->twoCopies();
		$this->suites->expects($this->once())->method('countActiveByOwner')->with('user', 'carol')->willReturn(0);

		$this->assertSame(
			[ShareSyncService::GROUP_WRITE, ['src' => true, 'c1' => true]],
			$this->groupWrite(
				[['secretId' => 'src', 'key' => 'a'], ['secretId' => 'c1', 'login' => 'b'], ['secretId' => 'c2']],
				$judged
			)
		);
	}

	public function testAssertGroupWriteRefusesAValuelessRowWhoseHolderHasAVaultKey(): void {
		$judged = $this->twoCopies();
		$this->suites->method('countActiveByOwner')->willReturn(1);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Every holder with a vault key must get the new value');
		$this->groupWrite([['secretId' => 'src', 'key' => 'a'], ['secretId' => 'c1', 'key' => 'b'], ['secretId' => 'c2']], $judged);
	}

	public function testAssertGroupWriteJudgesTheRowsWhenNoneAreGiven(): void {
		$this->secrets->method('findByIds')->willReturn([]);
		$this->rowJudge->expects($this->once())->method('rowsOfSource')->with('src')
			->willReturn(new JudgedShareRows([], static fn (): ?string => null));

		$this->assertSame(
			[ShareSyncService::GROUP_WRITE, ['src' => true]],
			$this->groupWrite([['secretId' => 'src', 'key' => 'a', 'expiresAt' => (new DateTime())->format('c')]], null)
		);
	}
}
