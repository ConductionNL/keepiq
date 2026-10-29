<?php

/**
 * A copy holder's save goes to the copy's source only through a share row its
 * target holds and the pending quarantine scan does not hold back. Otherwise
 * the write context names no source and no owner certificate, and share-save
 * saves the caller's own row, or waits for the scan when it cannot judge the
 * row. A legitimate copy still saves for everyone as before.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\ShareSave
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

namespace OCA\Keepiq\Tests\Unit\Service\ShareSave;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\GroupShare;
use OCA\Keepiq\Db\GroupShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\SecretVersionService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareSave\ShareSaveRefusal;
use OCA\Keepiq\Service\ShareSave\ShareSaveScope;
use OCA\Keepiq\Service\ShareSync\ShareSyncGuards;
use OCA\Keepiq\Service\ShareSync\ShareWriteContext;
use OCA\Keepiq\Service\ShareSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Alice owns S_a and shares it with a group Bob is in. A row
 * `{S_a, bob, V}` names Bob's own secret V as his copy.
 */
class CopySaveFollowsOnlyAJudgedRowTest extends TestCase {

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
	 * Seed S_a, owned by Alice, and V, owned by Bob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->secrets = [];
		$this->rows    = [];
		$this->secret(id: 'S_a', ownerId: 'alice');
		$this->secret(id: 'V', ownerId: 'bob');
	}//end setUp()

	/**
	 * Add a user-owned secret.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->resetUpdatedFields();
		$this->secrets[$id] = $secret;

		return $secret;
	}//end secret()

	/**
	 * Add a live share row of S_a.
	 *
	 * @param string $target The row's target
	 * @param string $copyId The copy it names
	 *
	 * @return ShareTarget
	 */
	private function row(string $target, string $copyId): ShareTarget {
		$row = new ShareTarget();
		$row->setId('row-'.$copyId);
		$row->setSourceSecretId('S_a');
		$row->setTargetUserId($target);
		$row->setSecretId($copyId);
		$this->rows[] = $row;

		return $row;
	}//end row()

	/**
	 * The share-target mapper over the rows.
	 *
	 * @return ShareTargetMapper
	 */
	private function targets(): ShareTargetMapper {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('findByRecipientSecret')->willReturnCallback(
			function (string $recipientSecretId): ShareTarget {
				foreach ($this->rows as $row) {
					if ($row->getSecretId() === $recipientSecretId) {
						return $row;
					}
				}

				throw new DoesNotExistException('none');
			}
		);
		$targets->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			function (string $sourceSecretId, string $targetUserId): ShareTarget {
				foreach ($this->rows as $row) {
					if ($row->getSourceSecretId() === $sourceSecretId && $row->getTargetUserId() === $targetUserId) {
						return $row;
					}
				}

				throw new DoesNotExistException('none');
			}
		);
		$targets->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceSecretId): array => array_values(
				array_filter($this->rows, static fn (ShareTarget $row): bool => $row->getSourceSecretId() === $sourceSecretId)
			)
		);

		return $targets;
	}//end targets()

	/**
	 * The secret mapper over the secrets; it never writes.
	 *
	 * @return SecretMapper
	 */
	private function secretMapper(): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(
			function (string $id): Secret {
				if (isset($this->secrets[$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $this->secrets[$id];
			}
		);
		$mapper->method('findByIds')->willReturnCallback(
			fn (array $ids): array => array_intersect_key($this->secrets, array_flip($ids))
		);
		$mapper->expects($this->never())->method('update');

		return $mapper;
	}//end secretMapper()

	/**
	 * Every owner has an active suite whose certificate names them.
	 *
	 * @return EncryptionSuiteMapper
	 */
	private function suites(): EncryptionSuiteMapper {
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				$suite = new EncryptionSuite();
				$suite->setCertificate('CERT-'.$ownerId);
				return $suite;
			}
		);
		$suites->method('countActiveByOwner')->willReturn(1);

		return $suites;
	}//end suites()

	/**
	 * The real authorization service: S_a is fully shared with a group
	 * everyone is in, and nobody holds a delegation.
	 *
	 * @param ShareTargetMapper $targets The share targets
	 * @param SecretMapper      $secrets The secrets
	 *
	 * @return ShareAuthorizationService
	 */
	private function authorization(ShareTargetMapper $targets, SecretMapper $secrets): ShareAuthorizationService {
		$delegations = $this->createMock(SecretDelegationMapper::class);
		$delegations->method('findActiveBySecretAndUser')->willThrowException(new DoesNotExistException('none'));

		$groupShare = new GroupShare();
		$groupShare->setSecretId('S_a');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly(false);
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturnCallback(
			static fn (string $secretId): array => ($secretId === 'S_a' ? [$groupShare] : [])
		);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		return new ShareAuthorizationService(
			secretMapper: $secrets,
			delegationMapper: $delegations,
			shareTargetMapper: $targets,
			groupShareMapper: $groupShares,
			groupManager: $groupManager,
		);
	}//end authorization()

	/**
	 * A scanner whose heldBack() answers the given verdict, or none at all.
	 *
	 * @param string|null|false $verdict The verdict, or false for no scanner
	 *
	 * @return ShareQuarantineScanner|null
	 */
	private function scanner(string|null|false $verdict): ?ShareQuarantineScanner {
		if ($verdict === false) {
			return null;
		}

		$scanner = $this->createMock(ShareQuarantineScanner::class);
		$scanner->method('heldBack')->willReturn($verdict);

		return $scanner;
	}//end scanner()

	/**
	 * The write context and the share-save scope on the same collaborators.
	 *
	 * @param ShareQuarantineScanner|null $scanner The scanner
	 *
	 * @return array{0:ShareWriteContext,1:ShareSaveScope}
	 */
	private function services(?ShareQuarantineScanner $scanner): array {
		$targets  = $this->targets();
		$secrets  = $this->secretMapper();
		$suites   = $this->suites();
		$auth     = $this->authorization(targets: $targets, secrets: $secrets);
		$contexts = new ShareWriteContext(targets: $targets, suites: $suites, auth: $auth, quarantineScan: $scanner);
		$guards   = new ShareSyncGuards(targets: $targets, secrets: $secrets, suites: $suites, auth: $auth);
		$sync     = new ShareSyncService(
			mapper: $targets,
			secretMapper: $secrets,
			db: $this->createMock(IDBConnection::class),
			auth: $auth,
			versionService: $this->createMock(SecretVersionService::class),
			guards: $guards,
			contexts: $contexts,
			quarantineScan: $scanner,
		);
		$federated = $this->createMock(FederatedShareMapper::class);
		$federated->method('findBySourceSecret')->willReturn([]);

		return [
			$contexts,
			new ShareSaveScope(
				targets: $targets,
				secrets: $secrets,
				federatedShares: $federated,
				sync: $sync,
				guards: $guards,
				quarantineScan: $scanner,
			),
		];
	}//end services()

	/**
	 * The scanner's verdicts on a legitimate copy that let it through: no
	 * scanner wired, the scan completed, or a pending scan that clears it.
	 *
	 * @return array<string,array{0:string|null|false}>
	 */
	public static function clearingScans(): array {
		return [
			'no scanner' => [false],
			'cleared' => [null],
		];
	}//end clearingScans()

	/**
	 * While the pending scan cannot judge the row naming V, Bob's write
	 * context of V names neither S_a nor Alice's certificate, and a
	 * share-save of V waits for the scan, so neither V nor S_a is written.
	 *
	 * @return void
	 */
	public function testAnUnjudgedRowLeadsTheHoldersSaveNowhere(): void {
		$this->row(target: 'bob', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: ShareQuarantineScanner::HELD_UNJUDGED));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertNull($context['sourceSecretId']);
		$this->assertNull($context['ownerCertificate']);
		$this->assertSame('none', $context['effectiveGrade']);

		try {
			$scope->resolve(row: $this->secrets['V'], userId: 'bob');
			$this->fail('A share-save through an unjudged row must wait for the scan');
		} catch (ShareSaveRefusal $refusal) {
			$this->assertSame('share_scan_pending', $refusal->getBody()['error']);
			$this->assertSame(Http::STATUS_CONFLICT, $refusal->getStatus());
		}

		$this->assertSame([], $this->secrets['S_a']->getUpdatedFields());
		$this->assertSame([], $this->secrets['V']->getUpdatedFields());
	}//end testAnUnjudgedRowLeadsTheHoldersSaveNowhere()

	/**
	 * A row the pending scan quarantines on the spot is no copy: V is its own
	 * source, as with any quarantined row.
	 *
	 * @return void
	 */
	public function testARowQuarantinedOnTheSpotIsNoCopy(): void {
		$this->row(target: 'bob', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: ShareQuarantineScanner::HELD_QUARANTINED));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertSame('V', $context['sourceSecretId']);
		$this->assertSame('owner', $context['effectiveGrade']);
		$this->assertSame('CERT-bob', $context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['V'], userId: 'bob');
		$this->assertFalse($path->isCopyEditor());
		$this->assertSame('V', $path->source->getId());
	}//end testARowQuarantinedOnTheSpotIsNoCopy()

	/**
	 * A row whose target does not hold V never leads Bob's save to S_a,
	 * whatever the scan says, even when Bob writes S_a for everyone
	 * through a copy of their own.
	 *
	 * @param string|null|false $verdict The scanner's verdict
	 *
	 * @return void
	 */
	#[DataProvider('clearingScans')]
	public function testARowWhoseTargetDoesNotHoldTheCopyNeverPivots(string|null|false $verdict): void {
		$this->secret(id: 'C_v', ownerId: 'bob');
		$this->row(target: 'bob', copyId: 'C_v');
		$this->row(target: 'someone-else', copyId: 'V');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: $verdict));

		$context = $contexts->writeContext(secretId: 'V', userId: 'bob');
		$this->assertNull($context['sourceSecretId']);
		$this->assertNull($context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['V'], userId: 'bob');
		$this->assertFalse($path->isCopyEditor());
		$this->assertSame('V', $path->source->getId());
	}//end testARowWhoseTargetDoesNotHoldTheCopyNeverPivots()

	/**
	 * A legitimate copy, held by its target and cleared by the scan (or with
	 * no scanner wired), still saves for everyone through its source.
	 *
	 * @param string|null|false $verdict The scanner's verdict
	 *
	 * @return void
	 */
	#[DataProvider('clearingScans')]
	public function testALegitimateCopyStillSavesForEveryone(string|null|false $verdict): void {
		$this->secret(id: 'C_r', ownerId: 'recipient');
		$this->row(target: 'recipient', copyId: 'C_r');
		[$contexts, $scope] = $this->services(scanner: $this->scanner(verdict: $verdict));

		$context = $contexts->writeContext(secretId: 'C_r', userId: 'recipient');
		$this->assertSame('S_a', $context['sourceSecretId']);
		$this->assertSame('write', $context['effectiveGrade']);
		$this->assertSame('CERT-alice', $context['ownerCertificate']);

		$path = $scope->resolve(row: $this->secrets['C_r'], userId: 'recipient');
		$this->assertTrue($path->isCopyEditor());
		$this->assertSame('S_a', $path->source->getId());
		$this->assertSame(ShareSyncService::GROUP_WRITE, $path->writesOn);
	}//end testALegitimateCopyStillSavesForEveryone()
}//end class
