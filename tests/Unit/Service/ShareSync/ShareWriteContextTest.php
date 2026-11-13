<?php

/**
 * Unit tests for ShareWriteContext.
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
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Exception\ShareScanPendingException;
use OCA\Keepiq\Service\JudgedShareRows;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\ShareRowVerdict;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for a caller's standing on a shared secret and the context of a write.
 *
 * @covers \OCA\Keepiq\Service\ShareSync\ShareWriteContext
 * @uses   \OCA\Keepiq\Db\EncryptionSuite
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\ShareTarget
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Service\JudgedShareRows
 * @uses   \OCA\Keepiq\Service\ShareRowVerdict
 */
class ShareWriteContextTest extends TestCase {
	private ShareRowJudge&MockObject $rowJudge;

	private EncryptionSuiteMapper&MockObject $suites;

	private ShareAuthorizationService&MockObject $auth;

	private ShareWriteContext $context;

	protected function setUp(): void {
		$this->rowJudge = $this->createMock(ShareRowJudge::class);
		$this->suites   = $this->createMock(EncryptionSuiteMapper::class);
		$this->auth     = $this->createMock(ShareAuthorizationService::class);
		$this->context  = new ShareWriteContext($this->rowJudge, $this->suites, $this->auth);
	}

	private function secret(string $id, string $ownerId = 'owner', string $ownerType = 'user', ?DateTime $ends = null): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setAccessExpiresAt($ends);

		return $secret;
	}

	private function standing(bool $owner, ?string $grade, ?string $copyGrade): void {
		$this->auth->method('isOwnerOrDelegate')->willReturn($owner);
		$this->auth->method('resolveGrade')->willReturn($grade);
		$this->auth->method('copyGrade')->willReturn($copyGrade);
	}

	public function testOwnerOrDelegateStandsAsOwner(): void {
		$source = $this->secret('src');
		$this->auth->expects($this->once())->method('isOwnerOrDelegate')->with($source, 'alice')->willReturn(true);
		$this->auth->expects($this->never())->method('resolveGrade');

		$standing = $this->context->standingOn($source, 'alice');

		$this->assertSame(['grade' => 'owner', 'writesOn' => null], $standing);
		$this->assertTrue($this->context->writesForEveryone($standing));
	}

	public function testTeamWriterWritesOnTheTeam(): void {
		$this->standing(false, 'manage', null);
		$this->auth->expects($this->never())->method('copyGrade');

		$standing = $this->context->standingOn($this->secret('src'), 'alice');

		$this->assertSame(['grade' => 'manage', 'writesOn' => ShareSyncService::TEAM_WRITE], $standing);
		$this->assertTrue($this->context->writesForEveryone($standing));
	}

	public function testWriteCopyHolderWritesOnTheGroup(): void {
		$this->standing(false, 'read', 'write');

		$this->assertSame(
			['grade' => 'write', 'writesOn' => ShareSyncService::GROUP_WRITE],
			$this->context->standingOn($this->secret('src'), 'alice')
		);
	}

	public function testReadOnlyTeamGradeWinsOverAReadCopy(): void {
		$this->standing(false, 'read', 'read');

		$standing = $this->context->standingOn($this->secret('src'), 'alice');

		$this->assertSame(['grade' => 'read', 'writesOn' => null], $standing);
		$this->assertFalse($this->context->writesForEveryone($standing));
	}

	public function testTheTeamGradeIsReportedBeforeAReadOnlyCopyGrade(): void {
		$this->standing(false, 'read', 'use');

		$this->assertSame(['grade' => 'read', 'writesOn' => null], $this->context->standingOn($this->secret('src'), 'alice'));
	}

	public function testWithoutTeamGradeTheCopyGradeIsReported(): void {
		$this->standing(false, null, 'read');

		$this->assertSame(['grade' => 'read', 'writesOn' => null], $this->context->standingOn($this->secret('src'), 'alice'));
	}

	public function testWithoutGroupSharesOnlyOwnersAndTeamWritersWriteForEveryone(): void {
		$this->standing(false, null, 'write');

		$this->assertFalse($this->context->writesForEveryoneWithoutGroupShares($this->secret('src'), 'alice'));
	}

	public function testWithoutGroupSharesATeamWriterWritesForEveryone(): void {
		$this->standing(false, 'write', null);

		$this->assertTrue($this->context->writesForEveryoneWithoutGroupShares($this->secret('src'), 'alice'));
	}

	public function testWithoutGroupSharesTheOwnerWritesForEveryone(): void {
		$this->standing(true, null, null);

		$this->assertTrue($this->context->writesForEveryoneWithoutGroupShares($this->secret('src'), 'alice'));
	}

	public function testWriteContextOfTheOwnSourceCarriesTheOwnerCertificate(): void {
		$source = $this->secret('src', 'alice');
		$source->setUpdatedAt(new DateTime('2026-01-02T03:04:05+00:00'));
		$this->auth->method('loadSecret')->with('src')->willReturn($source);
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::NONE));
		$this->standing(true, null, null);
		$suite = new EncryptionSuite();
		$suite->setCertificate('CERT');
		$this->suites->expects($this->once())->method('findActiveByOwner')->with('user', 'alice')->willReturn($suite);

		$this->assertSame(
			[
				'sourceSecretId' => 'src',
				'effectiveGrade' => 'owner',
				'ownerCertificate' => 'CERT',
				'sourceUpdatedAt' => '2026-01-02T03:04:05+00:00',
			],
			$this->context->writeContext('src', 'alice')
		);
	}

	public function testWriteContextPivotsATrustedCopyToItsSource(): void {
		$copy   = $this->secret('copy', 'alice');
		$source = $this->secret('src', 'owner', 'organisation');
		$row    = new ShareTarget();
		$row->setSourceSecretId('src');
		$this->auth->method('loadSecret')->willReturnMap([['copy', $copy], ['src', $source]]);
		$this->rowJudge->method('forCopy')->with($copy)->willReturn(new ShareRowVerdict(ShareRowVerdict::TRUSTED, $row));
		$this->standing(false, 'write', null);
		$this->suites->method('findActiveByOwner')->with('organisation', 'owner')
			->willThrowException(new DoesNotExistException('none'));

		$this->assertSame(
			[
				'sourceSecretId' => 'src',
				'effectiveGrade' => 'write',
				'ownerCertificate' => null,
				'sourceUpdatedAt' => null,
			],
			$this->context->writeContext('copy', 'alice')
		);
	}

	public function testReadGradeGetsNoOwnerCertificate(): void {
		$source = $this->secret('src');
		$this->auth->method('loadSecret')->willReturn($source);
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::QUARANTINED));
		$this->standing(false, 'read', null);
		$this->suites->expects($this->never())->method('findActiveByOwner');

		$this->assertNull($this->context->writeContext('src', 'alice')['ownerCertificate']);
	}

	public function testAnUnrelatedSourceFallsBackToTheOwnRow(): void {
		$own = $this->secret('src', 'alice');
		$this->auth->method('loadSecret')->willReturn($own);
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::NONE));
		$this->standing(false, null, null);
		$this->rowJudge->expects($this->once())->method('rowsOfSource')->with('src')
			->willReturn(new JudgedShareRows([], static fn (): ?string => null));

		$this->assertSame(
			['sourceSecretId' => null, 'effectiveGrade' => 'none', 'ownerCertificate' => null, 'sourceUpdatedAt' => null],
			$this->context->writeContext('src', 'alice')
		);
	}

	public function testAnUntrustedCopyIsWrittenAsTheHoldersOwnRow(): void {
		$copy = $this->secret('copy', 'alice', 'user', new DateTime('+1 day'));
		$this->auth->expects($this->once())->method('loadSecret')->with('copy')->willReturn($copy);
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::FOREIGN));
		$this->rowJudge->method('rowsOfSource')->willReturn(new JudgedShareRows([], static fn (): ?string => null));

		$this->assertSame('none', $this->context->writeContext('copy', 'alice')['effectiveGrade']);
	}

	public function testAnotherUsersRowIsNotFound(): void {
		$this->auth->method('loadSecret')->willReturn($this->secret('copy', 'bob'));
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::AMBIGUOUS));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret not found');
		$this->context->writeContext('copy', 'alice');
	}

	public function testAnOrganisationRowIsNotFound(): void {
		$this->auth->method('loadSecret')->willReturn($this->secret('copy', 'alice', 'organisation'));
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::AMBIGUOUS));

		$this->expectException(InvalidArgumentException::class);
		$this->context->writeContext('copy', 'alice');
	}

	public function testARowWhoseAccessEndedIsNotFound(): void {
		$this->auth->method('loadSecret')->willReturn($this->secret('copy', 'alice', 'user', new DateTime('-1 second')));
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::FOREIGN));
		$this->rowJudge->expects($this->never())->method('rowsOfSource');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret not found');
		$this->context->writeContext('copy', 'alice');
	}

	public function testAnOwnRowThatIsSharedOnwardIsNotFound(): void {
		$this->auth->method('loadSecret')->willReturn($this->secret('copy', 'alice'));
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::FOREIGN));
		$this->rowJudge->method('rowsOfSource')->willReturn(new JudgedShareRows([new ShareTarget()], static fn (): ?string => null));
		$this->rowJudge->expects($this->never())->method('scanPending');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Secret not found');
		$this->context->writeContext('copy', 'alice');
	}

	public function testAnUnjudgedCopyThatIsSharedOnwardWaitsForTheScan(): void {
		$pending = new ShareScanPendingException('wait');
		$this->auth->method('loadSecret')->willReturn($this->secret('copy', 'alice'));
		$this->rowJudge->method('forCopy')->willReturn(new ShareRowVerdict(ShareRowVerdict::UNJUDGED));
		$this->rowJudge->method('rowsOfSource')->willReturn(new JudgedShareRows([new ShareTarget()], static fn (): ?string => null));
		$this->rowJudge->expects($this->once())->method('scanPending')
			->with('This secret waits for the share quarantine scan and cannot be saved yet')
			->willReturn($pending);

		try {
			$this->context->writeContext('copy', 'alice');
			$this->fail('Expected the scan refusal');
		} catch (ShareScanPendingException $e) {
			$this->assertSame($pending, $e);
		}
	}
}
