<?php

/**
 * Unit tests for DirectShareRegistrar: the recipient copy and the share row are
 * minted in one transaction (GHSA-m5p9-6pw7-pf3g), and an active delegate may
 * register a share just as the owner can, from the source or their own copy,
 * judged on that copy.
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
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\ShareAuditTrail;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareGrantReader;
use OCA\Keepiq\Service\TeamFolderQueryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DirectShareRegistrar.
 */
class DirectShareRegistrarTest extends TestCase {

	/**
	 * @var ShareTargetMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private ShareTargetMapper $mapper;

	/**
	 * @var SecretMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private SecretMapper $secretMapper;

	/**
	 * @var RecipientSecretCopyFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	private RecipientSecretCopyFactory $copyFactory;

	/**
	 * @var NotificationService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private NotificationService $notificationService;

	/**
	 * @var IDBConnection&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IDBConnection $connection;

	/**
	 * Whether the source secret under test is itself a received copy.
	 *
	 * @var boolean
	 */
	private bool $sourceIsReceivedCopy = false;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(ShareTargetMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->copyFactory = $this->createMock(RecipientSecretCopyFactory::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->connection = $this->createMock(IDBConnection::class);

		// By default a source is an original: the reverse copy lookup finds no
		// row. A test that shares a received copy flips the flag or names the
		// copy's row.
		$this->mapper->method('findByRecipientSecret')->willReturnCallback(
			function (string $recipientSecretId): ShareTarget {
				if (isset($this->copyRows[$recipientSecretId]) === true) {
					return $this->copyRows[$recipientSecretId];
				}

				if ($this->sourceIsReceivedCopy === true) {
					return new ShareTarget();
				}

				throw new DoesNotExistException('none');
			}
		);
	}//end setUp()

	/**
	 * Share rows of received copies, by copy id.
	 *
	 * @var array<string,ShareTarget>
	 */
	private array $copyRows = [];

	/**
	 * A share row giving a user a copy of a source.
	 *
	 * @param string $sourceId The source
	 * @param string $userId   The copy's holder
	 * @param string $copyId   The copy
	 *
	 * @return ShareTarget
	 */
	private function copyRow(string $sourceId, string $userId, string $copyId): ShareTarget {
		$row = new ShareTarget();
		$row->setSourceSecretId($sourceId);
		$row->setTargetUserId($userId);
		$row->setSecretId($copyId);
		return $row;
	}//end copyRow()

	/**
	 * The real authorization service over this test's mappers, with active
	 * delegations given as "secretId/userId".
	 *
	 * @param list<string>                  $delegates    The active delegations
	 * @param ShareGrantReader|null $grants Lists the grants reaching a delegate's copy
	 *
	 * @return ShareAuthorizationService
	 */
	private function realAuthorization(array $delegates, ?ShareGrantReader $grants = null): ShareAuthorizationService {
		$delegationMapper = $this->createMock(SecretDelegationMapper::class);
		$delegationMapper->method('findActiveBySecretAndUser')->willReturnCallback(
			static function (string $secretId, string $userId) use ($delegates): SecretDelegation {
				if (in_array($secretId.'/'.$userId, $delegates, true) === true) {
					return new SecretDelegation();
				}

				throw new DoesNotExistException('none');
			}
		);

		return new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $delegationMapper,
			shareTargetMapper: $this->mapper,
			grants: $grants,
		);
	}//end realAuthorization()

	/**
	 * Secrets by id, for a findById() stub.
	 *
	 * @param array<string,Secret> $secrets The secrets
	 *
	 * @return void
	 */
	private function secrets(array $secrets): void {
		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($secrets): Secret {
				if (isset($secrets[$id]) === false) {
					throw new DoesNotExistException('none');
				}

				return $secrets[$id];
			}
		);
	}//end secrets()

	/**
	 * Build a user-owned source secret.
	 *
	 * @param string $id      The secret id
	 * @param string $ownerId The owning user
	 *
	 * @return Secret
	 */
	private function ownerSecret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setName('demo');
		return $secret;
	}//end ownerSecret()

	/**
	 * Build a recipient copy the factory hands back.
	 *
	 * @param string $id      The copy id
	 * @param string $ownerId The recipient
	 *
	 * @return Secret
	 */
	private function recipientCopy(string $id, string $ownerId): Secret {
		$copy = new Secret();
		$copy->setId($id);
		$copy->setOwnerType('user');
		$copy->setOwnerId($ownerId);
		return $copy;
	}//end recipientCopy()

	/**
	 * Build the registrar, with an authorization service when one is given.
	 *
	 * @param ShareAuthorizationService|null $authorization    The owner-or-delegate check
	 * @param GroupShareMapper|null          $groupShareMapper The group shares a row may name
	 * @param ShareAuditTrail|null           $auditTrail       The share audit trail
	 *
	 * @return DirectShareRegistrar
	 */
	private function registrar(
		?ShareAuthorizationService $authorization = null,
		?GroupShareMapper $groupShareMapper = null,
		?ShareAuditTrail $auditTrail = null,
	): DirectShareRegistrar {
		return new DirectShareRegistrar(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			copyFactory: $this->copyFactory,
			notificationService: $this->notificationService,
			auditTrail: $auditTrail,
			groupShareMapper: $groupShareMapper,
			authorization: $authorization,
			connection: $this->connection,
		);
	}//end registrar()

	/**
	 * A unique-constraint failure on the link insert rolls back the minted copy
	 * and never commits, so no decryptable copy is left behind without a share
	 * row. The share is reported as already existing.
	 *
	 * @return void
	 */
	public function testAFailedLinkInsertRollsBackTheMintedCopy(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('src-1', 'alice'));
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->copyFactory->expects($this->once())->method('create')
			->willReturn($this->recipientCopy('copy-1', 'bob'));

		$violation = $this->createMock(DbException::class);
		$violation->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->mapper->method('insert')->willThrowException($violation);

		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');

		$report = $this->registrar()->registerDirectShares(
			userId: 'alice',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('exists', $report[0]['status']);
	}//end testAFailedLinkInsertRollsBackTheMintedCopy()

	/**
	 * An unexpected insert failure rolls back the copy and propagates, so again
	 * no copy is left behind.
	 *
	 * @return void
	 */
	public function testAnUnexpectedInsertFailureRollsBackAndThrows(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('src-1', 'alice'));
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));

		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);
		$this->mapper->method('insert')->willThrowException($failure);

		$this->connection->expects($this->once())->method('rollBack');
		$this->connection->expects($this->never())->method('commit');

		$this->expectException(DbException::class);
		$this->registrar()->registerDirectShares(
			userId: 'alice',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);
	}//end testAnUnexpectedInsertFailureRollsBackAndThrows()

	/**
	 * A successful registration commits the transaction once.
	 *
	 * @return void
	 */
	public function testASuccessfulRegistrationCommits(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('src-1', 'alice'));
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$this->connection->expects($this->once())->method('beginTransaction');
		$this->connection->expects($this->once())->method('commit');
		$this->connection->expects($this->never())->method('rollBack');

		$report = $this->registrar()->registerDirectShares(
			userId: 'alice',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('created', $report[0]['status']);
		$this->assertSame('copy-1', $report[0]['recipientSecretId']);
	}//end testASuccessfulRegistrationCommits()

	/**
	 * An active delegate of the source, holding one unrestricted copy of it,
	 * may register the share exactly as the owner can, by naming the source.
	 *
	 * @return void
	 */
	public function testAnActiveDelegateMayRegisterAShare(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);
		$this->assertSame('created', $report[0]['status']);
	}//end testAnActiveDelegateMayRegisterAShare()

	/**
	 * A delegate's client names their own received copy, as it cannot decrypt
	 * the source. The copy resolves to its source: the share is created and the
	 * minted row hangs on the source, where the owner sees and revokes it.
	 *
	 * @return void
	 */
	public function testADelegateMayShareFromTheirOwnCopy(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$minted = [];
		$this->mapper->method('insert')->willReturnCallback(
			static function (ShareTarget $row) use (&$minted): ShareTarget {
				$minted[] = $row;
				return $row;
			}
		);

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'dave-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('created', $report[0]['status']);
		$this->assertCount(1, $minted);
		$this->assertSame('src-1', $minted[0]->getSourceSecretId());
		$this->assertSame('dave', $minted[0]->getCreatedBy());
	}//end testADelegateMayShareFromTheirOwnCopy()

	/**
	 * The `share.granted` audit entry of a delegate's share names the source
	 * the row hangs on, not the copy the delegate named.
	 *
	 * @return void
	 */
	public function testADelegatesGrantIsAuditedOnTheSource(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);
		$events = [];
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(static function (AuditEvent $event) use (&$events): void {
			$events[] = $event;
		});

		$this->registrar(
			authorization: $this->realAuthorization(['src-1/dave']),
			auditTrail: new ShareAuditTrail(eventDispatcher: $dispatcher),
		)->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'dave-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertCount(1, $events);
		$this->assertSame(AuditEventTypes::SHARE_GRANTED, $events[0]->getEventType());
		$this->assertSame('src-1', $events[0]->getObjectId());
	}//end testADelegatesGrantIsAuditedOnTheSource()

	/**
	 * A delegate naming their own copy for a recipient who already holds a
	 * live share of the source gets `exists`: the check looks at the resolved
	 * source, not the copy named, and nothing is minted.
	 *
	 * @return void
	 */
	public function testADelegatesCopyFindsTheRecipientsExistingShareOfTheSource(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => match (true) {
				$sourceSecretId === 'src-1' && $targetUserId === 'dave' => $this->copyRow('src-1', 'dave', 'dave-copy'),
				$sourceSecretId === 'src-1' && $targetUserId === 'bob' => $this->copyRow('src-1', 'bob', 'bob-copy'),
				default => throw new DoesNotExistException('none'),
			}
		);
		$this->copyFactory->expects($this->never())->method('create');
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'dave-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('exists', $report[0]['status']);
	}//end testADelegatesCopyFindsTheRecipientsExistingShareOfTheSource()

	/**
	 * An owner naming a copy they hold of their own source is not resolved to
	 * the source: a received copy is never a share source, for the owner too.
	 *
	 * @return void
	 */
	public function testAnOwnersCopyOfTheirOwnSourceIsNotResolved(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'alice-copy' => $this->recipientCopy('alice-copy', 'alice')]);
		$this->copyRows['alice-copy'] = $this->copyRow('src-1', 'alice', 'alice-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization([]))->registerDirectShares(
			userId: 'alice',
			shares: [['sourceSecretId' => 'alice-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_shareable', $report[0]['status']);
	}//end testAnOwnersCopyOfTheirOwnSourceIsNotResolved()

	/**
	 * A delegate naming their own copy with a group share of its source is
	 * matched against the resolved source, not the copy they named, so the
	 * share is created and linked to that group share.
	 *
	 * @return void
	 */
	public function testADelegatesCopyMatchesAGroupShareOfItsSource(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$groupShare = new GroupShare();
		$groupShare->setId('gs-1');
		$groupShare->setSecretId('src-1');
		$groupShareMapper = $this->createMock(GroupShareMapper::class);
		$groupShareMapper->method('findById')->willReturnCallback(
			static fn (string $id): GroupShare => ($id === 'gs-1' ? $groupShare : throw new DoesNotExistException('none'))
		);

		$report = $this->registrar(
			authorization: $this->realAuthorization(['src-1/dave']),
			groupShareMapper: $groupShareMapper,
		)->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'dave-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob', 'groupShareId' => 'gs-1']]
		);

		$this->assertSame('created', $report[0]['status']);
	}//end testADelegatesCopyMatchesAGroupShareOfItsSource()

	/**
	 * A delegate naming another recipient's copy of the same source does not
	 * reach the source through it: only the delegate's own copy resolves.
	 *
	 * @return void
	 */
	public function testADelegateMayNotShareFromAnotherRecipientsCopy(): void {
		$this->secrets([
			'src-1' => $this->ownerSecret('src-1', 'alice'),
			'dave-copy' => $this->recipientCopy('dave-copy', 'dave'),
			'carol-copy' => $this->recipientCopy('carol-copy', 'carol'),
		]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->copyRows['carol-copy'] = $this->copyRow('src-1', 'carol', 'carol-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'carol-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_owned', $report[0]['status']);
	}//end testADelegateMayNotShareFromAnotherRecipientsCopy()

	/**
	 * A row naming the delegate as target but pointing at a secret someone else
	 * owns does not resolve to the source: the delegate does not hold that copy.
	 *
	 * @return void
	 */
	public function testADelegateMayNotShareFromACopyTheyDoNotHold(): void {
		$this->secrets([
			'src-1' => $this->ownerSecret('src-1', 'alice'),
			'dave-copy' => $this->recipientCopy('dave-copy', 'dave'),
			'mallory-secret' => $this->recipientCopy('mallory-secret', 'mallory'),
		]);
		$this->copyRows['dave-copy'] = $this->copyRow('src-1', 'dave', 'dave-copy');
		$this->copyRows['mallory-secret'] = $this->copyRow('src-1', 'dave', 'mallory-secret');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $this->copyRow('src-1', 'dave', 'dave-copy')
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'mallory-secret', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_owned', $report[0]['status']);
	}//end testADelegateMayNotShareFromACopyTheyDoNotHold()

	/**
	 * The same call by a plain recipient, who holds no delegation, is still
	 * refused: their copy is a received copy, not a share source.
	 *
	 * @return void
	 */
	public function testAPlainRecipientsCopyIsStillNotShareable(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'carol-copy' => $this->recipientCopy('carol-copy', 'carol')]);
		$this->copyRows['carol-copy'] = $this->copyRow('src-1', 'carol', 'carol-copy');
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization([]))->registerDirectShares(
			userId: 'carol',
			shares: [['sourceSecretId' => 'carol-copy', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_shareable', $report[0]['status']);
	}//end testAPlainRecipientsCopyIsStillNotShareable()

	/**
	 * An active delegate whose copy is gone (revoked or expired) holds no grant
	 * to share from: `restricted`, nothing minted.
	 *
	 * @return void
	 */
	public function testADelegateWithNoCopyIsRestricted(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice')]);
		$this->mapper->method('findBySourceSecretAndTargetUser')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('restricted', $report[0]['status']);
	}//end testADelegateWithNoCopyIsRestricted()

	/**
	 * An active delegate with two rows for (source, delegate) has no single
	 * grant to judge: `restricted`, nothing minted.
	 *
	 * @return void
	 */
	public function testADelegateWithTwoCopiesIsRestricted(): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice')]);
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new \OCP\AppFramework\Db\MultipleObjectsReturnedException('two'));
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('restricted', $report[0]['status']);
	}//end testADelegateWithTwoCopiesIsRestricted()

	/**
	 * A caller who is neither owner nor an active delegate is refused.
	 *
	 * @return void
	 */
	public function testAStrangerMayNotRegisterAShare(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('src-1', 'alice'));

		$authorization = $this->realAuthorization([]);
		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $authorization)->registerDirectShares(
			userId: 'mallory',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_owned', $report[0]['status']);
	}//end testAStrangerMayNotRegisterAShare()

	/**
	 * A delegate whose own copy of the source is use-only (restricted) may not
	 * mint full, unrestricted copies through register-batch. The authorization
	 * is a real ShareAuthorizationService — isOwnerOrDelegate is not mocked away
	 * — and the delegate's copy is a genuine use-only fixture.
	 *
	 * @return void
	 */
	public function testADelegateWithARestrictedCopyIsRefused(): void {
		$source = $this->ownerSecret('src-1', 'alice');

		$restrictedCopy = new Secret();
		$restrictedCopy->setId('dave-copy');
		$restrictedCopy->setOwnerType('user');
		$restrictedCopy->setOwnerId('dave');
		$restrictedCopy->setUseOnly(true);

		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id): Secret => ($id === 'dave-copy' ? $restrictedCopy : $source)
		);

		// Dave's own share target: his use-only copy of the source.
		$daveTarget = new ShareTarget();
		$daveTarget->setSecretId('dave-copy');
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturn($daveTarget);

		$this->mapper->expects($this->never())->method('insert');

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave']))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('restricted', $report[0]['status']);
	}//end testADelegateWithARestrictedCopyIsRefused()

	/**
	 * Dave's grants on src-1: his direct share (full, with the given end
	 * date) and a use-only group share without an end date that he is in.
	 * His copy reads as full and permanent either way.
	 *
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public static function delegateGrants(): array {
		return [
			'full direct grant ended, use-only group grant left' => ['-1 hour', 'restricted'],
			'full direct grant ends tomorrow, use-only group grant' => ['+1 day', 'restricted'],
			'full permanent direct grant, use-only group grant' => [null, 'created'],
		];
	}//end delegateGrants()

	/**
	 * A delegate mints a full copy through register-batch only while one of
	 * their own grants is unrestricted on its own: a full grant that ends
	 * does not count, even beside a permanent use-only one
	 * (GHSA-m5p9-6pw7-pf3g).
	 *
	 * @param string|null $directEndsAt When dave's direct share ends
	 * @param string      $status       The registration status
	 *
	 * @return void
	 */
	#[DataProvider('delegateGrants')]
	public function testADelegateMayRegisterAFullShareOnlyOnAnUnrestrictedGrant(?string $directEndsAt, string $status): void {
		$this->secrets(['src-1' => $this->ownerSecret('src-1', 'alice'), 'dave-copy' => $this->recipientCopy('dave-copy', 'dave')]);
		$daveRow = $this->copyRow('src-1', 'dave', 'dave-copy');
		$daveRow->setExpiresAt($directEndsAt === null ? null : new DateTime($directEndsAt));
		$this->mapper->method('findBySourceSecretAndTargetUser')->willReturnCallback(
			static fn (string $sourceSecretId, string $targetUserId): ShareTarget => ($targetUserId === 'dave'
				? $daveRow
				: throw new DoesNotExistException('none'))
		);
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$groupShare = new GroupShare();
		$groupShare->setId('gs-1');
		$groupShare->setSecretId('src-1');
		$groupShare->setGroupId('team');
		$groupShare->setUseOnly(true);
		$groupShares = $this->createMock(GroupShareMapper::class);
		$groupShares->method('findBySecret')->willReturn([$groupShare]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $userId, string $groupId): bool => $userId === 'dave' && $groupId === 'team');
		$teamFolders = $this->createMock(TeamFolderQueryService::class);
		$teamFolders->method('coveringMemberships')->willReturn([]);
		$grants = new ShareGrantReader(
			secretMapper: $this->secretMapper,
			groupShareMapper: $groupShares,
			teamFolders: $teamFolders,
			groupManager: $groups,
		);

		$report = $this->registrar(authorization: $this->realAuthorization(['src-1/dave'], $grants))->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame($status, $report[0]['status']);
	}//end testADelegateMayRegisterAFullShareOnlyOnAnUnrestrictedGrant()

	/**
	 * A received copy may never be a share source: a recipient cannot re-share a
	 * secret that was shared with them, even when they own the copy.
	 *
	 * @return void
	 */
	public function testAReceivedCopyMayNotBeSharedOnward(): void {
		$this->sourceIsReceivedCopy = true;
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('copy-1', 'bob'));

		$this->mapper->expects($this->never())->method('insert');

		$authorization = $this->realAuthorization([]);

		$report = $this->registrar(authorization: $authorization)->registerDirectShares(
			userId: 'bob',
			shares: [['sourceSecretId' => 'copy-1', 'targetUserId' => 'carol', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_shareable', $report[0]['status']);
	}//end testAReceivedCopyMayNotBeSharedOnward()
}//end class
