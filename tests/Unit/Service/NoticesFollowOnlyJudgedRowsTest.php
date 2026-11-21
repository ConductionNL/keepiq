<?php

/**
 * The paths that tell someone about a copy, or record its use, name the
 * copy's source only through a share row the pending quarantine scan has
 * judged. A secret a held-back row names is its own: a compromise warns its
 * holder and the people they shared it with, a use is recorded on it, an
 * ended access tells no source owner, the org-ownership findings list it,
 * and a compromise of the row's source warns nobody about it. A legitimate
 * copy behaves as before.
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
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\CompromiseContainmentService;
use OCA\Keepiq\Service\ExpiredGrantRemover;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\ShareExpiryService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRestrictionResolver;
use OCA\Keepiq\Service\ShareRowJudge;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCA\Keepiq\Service\UseOnlyUseRecorder;
use OCA\Keepiq\Service\VaultPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Authentication\Token\IProvider;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Alice owns S_a, sealed under suite-a. Bob owns V, sealed under suite-b,
 * and shared V with Carol (copy C_c). A row `{S_a, bob, V}` names V as
 * Bob's copy of S_a.
 */
class NoticesFollowOnlyJudgedRowsTest extends TestCase {

	/**
	 * The secrets by id.
	 *
	 * @var array<string,Secret>
	 */
	private array $secrets = [];

	/**
	 * The share rows.
	 *
	 * @var list<ShareTarget>
	 */
	private array $rows = [];

	/**
	 * Seed the secrets and rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secrets = [];
		$this->rows    = [];
		$this->secret(id: 'S_a', ownerId: 'alice', suite: 'suite-a');
		$this->secret(id: 'V', ownerId: 'bob', suite: 'suite-b');
		$this->secret(id: 'C_c', ownerId: 'carol', suite: 'suite-c');
		$this->row(source: 'S_a', target: 'bob', copy: 'V');
		$this->row(source: 'V', target: 'carol', copy: 'C_c');
	}//end setUp()

	/**
	 * The pending scan's verdicts that hold a row back.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function heldBackVerdicts(): array {
		return [
			'quarantined on the spot' => [ShareQuarantineScanner::HELD_QUARANTINED],
			'cannot be judged' => [ShareQuarantineScanner::HELD_UNJUDGED],
		];
	}//end heldBackVerdicts()

	/**
	 * A compromise of Bob's suite warns about V itself, not S_a, and warns
	 * the people Bob shared V with, also when the scan cannot judge the row;
	 * a compromise of Alice's suite warns nobody about V.
	 *
	 * @param string $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testACompromiseDoesNotPivotThroughAHeldBackRow(string $verdict): void {
		$containment = $this->containment(verdict: $verdict);

		$radius = $containment->collect(suiteIds: ['suite-b']);
		$this->assertSame('V', $radius->getSealed()[0]['target']->getId());
		// The people Bob shared V with are warned either way; a row the scan
		// cannot judge is also counted, since its source's owner may be missed.
		$this->assertSame(['carol'], array_column($radius->getOutbound(), 'recipientId'));
		$this->assertSame(($verdict === ShareQuarantineScanner::HELD_UNJUDGED ? 1 : 0), $radius->getFailures());

		$this->assertSame([], $containment->collect(suiteIds: ['suite-a'])->getOutbound());
	}//end testACompromiseDoesNotPivotThroughAHeldBackRow()

	/**
	 * A legitimate copy's compromise warns its source's owner, and a
	 * compromise of the source warns its holder, as before.
	 *
	 * @return void
	 */
	public function testACompromiseOfALegitimateCopyPivotsToItsSource(): void {
		$containment = $this->containment(verdict: null);

		$this->assertSame('S_a', $containment->collect(suiteIds: ['suite-b'])->getSealed()[0]['target']->getId());
		$this->assertSame(['bob'], array_column($containment->collect(suiteIds: ['suite-a'])->getOutbound(), 'recipientId'));
	}//end testACompromiseOfALegitimateCopyPivotsToItsSource()

	/**
	 * A use of V through a held-back row is recorded on V, not on S_a.
	 *
	 * @param string $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAUseThroughAHeldBackRowIsRecordedOnTheSecretItself(string $verdict): void {
		$this->assertSame('V', $this->recordedUseOf(verdict: $verdict));
	}//end testAUseThroughAHeldBackRowIsRecordedOnTheSecretItself()

	/**
	 * A use refused because the caller does not hold the secret judges no
	 * share row, so it quarantines and audits nothing.
	 *
	 * @return void
	 */
	public function testAUseByAnotherUserJudgesNoRow(): void {
		$this->secrets['V']->setUseOnly(true);
		$secrets = $this->secretMapper();
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->expects($this->never())->method('heldBack');
		$recorder = new UseOnlyUseRecorder(
			secretMapper: $secrets,
			rowJudge: new ShareRowJudge(targets: $this->targets(), secrets: $secrets, scanner: $scanner),
			eventDispatcher: $this->createMock(IEventDispatcher::class),
		);

		$this->expectException(NotFoundException::class);
		$recorder->recordUse(copyId: 'V', userId: 'mallory');
	}//end testAUseByAnotherUserJudgesNoRow()

	/**
	 * A use of a legitimate use-only copy is recorded on its source, as before.
	 *
	 * @return void
	 */
	public function testAUseOfALegitimateCopyIsRecordedOnItsSource(): void {
		$this->assertSame('S_a', $this->recordedUseOf(verdict: null));
	}//end testAUseOfALegitimateCopyIsRecordedOnItsSource()

	/**
	 * When V's access ends, the owner of S_a is not told about Bob.
	 *
	 * @param string $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAnEndedAccessThroughAHeldBackRowTellsNoSourceOwner(string $verdict): void {
		$this->assertSame([['share_access_ended', 'bob']], $this->endedAccessNotices(verdict: $verdict));
	}//end testAnEndedAccessThroughAHeldBackRowTellsNoSourceOwner()

	/**
	 * When a legitimate copy's access ends, its source's owner is told, as before.
	 *
	 * @return void
	 */
	public function testAnEndedAccessOfALegitimateCopyTellsItsSourceOwner(): void {
		$this->assertSame(
			[['share_access_ended', 'bob'], ['share_access_ended_owner', 'alice']],
			$this->endedAccessNotices(verdict: null)
		);
	}//end testAnEndedAccessOfALegitimateCopyTellsItsSourceOwner()

	/**
	 * The org-ownership findings list V as Bob's own secret while its row is
	 * held back, and leave out a legitimate received copy.
	 *
	 * @return void
	 */
	public function testTheOwnershipFindingsListASecretOnlyAHeldBackRowNames(): void {
		$this->assertSame(['V'], $this->findingsOf(verdict: ShareQuarantineScanner::HELD_QUARANTINED));
		$this->assertSame(['V'], $this->findingsOf(verdict: ShareQuarantineScanner::HELD_UNJUDGED));
		$this->assertSame([], $this->findingsOf(verdict: null));
	}//end testTheOwnershipFindingsListASecretOnlyAHeldBackRowNames()

	/**
	 * Add a user-owned secret sealed under a suite.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 * @param string $suite   The suite
	 *
	 * @return void
	 */
	private function secret(string $id, string $ownerId, string $suite): void {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('name-'.$id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId($suite);
		$secret->setTypeId('type-login');
		$this->secrets[$id] = $secret;
	}//end secret()

	/**
	 * Add a live share row.
	 *
	 * @param string $source The source
	 * @param string $target The target
	 * @param string $copy   The copy
	 *
	 * @return void
	 */
	private function row(string $source, string $target, string $copy): void {
		$row = new ShareTarget();
		$row->setId('row-'.$copy);
		$row->setSourceSecretId($source);
		$row->setTargetUserId($target);
		$row->setSecretId($copy);
		$this->rows[] = $row;
	}//end row()

	/**
	 * The secret mapper over the secrets.
	 *
	 * @return SecretMapper
	 */
	private function secretMapper(): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($this->secrets[$id] ?? throw new DoesNotExistException('none'))
		);
		$mapper->method('findByEncryptionSuiteId')->willReturnCallback(
			fn (string $suite): array => array_values(
				array_filter($this->secrets, static fn (Secret $secret): bool => $secret->getEncryptionSuiteId() === $suite)
			)
		);

		return $mapper;
	}//end secretMapper()

	/**
	 * The share-target mapper over the rows.
	 *
	 * @return ShareTargetMapper
	 */
	private function targets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			function (string $copyId): ShareTarget {
				foreach ($this->rows as $row) {
					if ($row->getSecretId() === $copyId) {
						return $row;
					}
				}

				throw new DoesNotExistException('none');
			}
		);
		$targets->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceId): array => array_values(
				array_filter($this->rows, static fn (ShareTarget $row): bool => $row->getSourceSecretId() === $sourceId)
			)
		);

		return $targets;
	}//end targets()

	/**
	 * The judge; the scanner answers the verdict for `{S_a, bob, V}` and
	 * clears every other row.
	 *
	 * @param string|null  $verdict The verdict
	 * @param SecretMapper $secrets The secret mapper
	 *
	 * @return ShareRowJudge
	 */
	private function judge(?string $verdict, SecretMapper $secrets): ShareRowJudge {
		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturnCallback(
			static fn (ShareTarget $row): ?string => ($row->getId() === 'row-V' ? $verdict : null)
		);

		return new ShareRowJudge(targets: $this->targets(), secrets: $secrets, scanner: $scanner);
	}//end judge()

	/**
	 * The compromise containment service.
	 *
	 * @param string|null $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return CompromiseContainmentService
	 */
	private function containment(?string $verdict): CompromiseContainmentService {
		$secrets  = $this->secretMapper();
		$contacts = $this->createMock(EmergencyContactMapper::class);
		$contacts->method('findByGranteeSuite')->willReturn([]);

		return new CompromiseContainmentService(
			secretMapper: $secrets,
			contactMapper: $contacts,
			notificationService: $this->createMock(NotificationService::class),
			migrationService: $this->createMock(MigrationService::class),
			tokenProvider: $this->createMock(IProvider::class),
			logger: $this->createMock(LoggerInterface::class),
			rowJudge: $this->judge(verdict: $verdict, secrets: $secrets),
		);
	}//end containment()

	/**
	 * The secret a use of V by Bob is recorded on; V is use-only.
	 *
	 * @param string|null $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return string
	 */
	private function recordedUseOf(?string $verdict): string {
		$this->secrets['V']->setUseOnly(true);
		$secrets = $this->secretMapper();
		$events  = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (AuditEvent $event) use (&$events): void {
				$events[] = $event;
			}
		);

		(new UseOnlyUseRecorder(
			secretMapper: $secrets,
			eventDispatcher: $dispatcher,
			rowJudge: $this->judge(verdict: $verdict, secrets: $secrets),
		))->recordUse(copyId: 'V', userId: 'bob');

		$this->assertCount(1, $events);

		return (string)$events[0]->getObjectId();
	}//end recordedUseOf()

	/**
	 * The notices an expiry run sends once V's access ended and V is gone.
	 *
	 * @param string|null $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return list<array{0:string,1:string}>
	 */
	private function endedAccessNotices(?string $verdict): array {
		$copy = $this->secrets['V'];
		$copy->setAccessExpiresAt(new DateTime('-1 hour'));
		unset($this->secrets['V']);
		$secrets = $this->secretMapper();
		$secrets->method('findAccessEndingBetween')->willReturn([$copy]);

		$sent = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(
			static function (string $subject, string $recipientId) use (&$sent): bool {
				$sent[] = [$subject, $recipientId];
				return true;
			}
		);

		(new ShareExpiryService(
			secretMapper: $secrets,
			targetMapper: $this->targets(),
			restrictions: $this->createMock(ShareRestrictionResolver::class),
			remover: $this->createMock(ExpiredGrantRemover::class),
			notifications: $notifications,
			rowJudge: $this->judge(verdict: $verdict, secrets: $secrets),
		))->expire(now: new DateTime());

		return $sent;
	}//end endedAccessNotices()

	/**
	 * The ids the org-ownership findings list for Bob, whose own secrets
	 * are V alone.
	 *
	 * @param string|null $verdict The scan's verdict on `{S_a, bob, V}`
	 *
	 * @return list<string>
	 */
	private function findingsOf(?string $verdict): array {
		$secrets = $this->secretMapper();
		$secrets->method('findByOwner')->willReturn([$this->secrets['V']]);
		$policies = $this->createMock(VaultPolicyService::class);
		$policies->method('appliesTo')->willReturn(true);
		$policies->method('ownershipTypes')->willReturn(['login']);
		$type = new SecretType();
		$type->setName('login');
		$types = $this->createMock(SecretTypeMapper::class);
		$types->method('findById')->willReturn($type);

		$guard = new OrgOwnershipGuard(
			policies: $policies,
			teamFolders: $this->createMock(TeamFolderQueryService::class),
			typeMapper: $types,
			secretMapper: $secrets,
			rowJudge: $this->judge(verdict: $verdict, secrets: $secrets),
		);

		return array_column($guard->findings(userId: 'bob'), 'id');
	}//end findingsOf()
}//end class
