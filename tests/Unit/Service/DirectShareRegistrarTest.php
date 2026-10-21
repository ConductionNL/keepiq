<?php

/**
 * Unit tests for DirectShareRegistrar: the recipient copy and the share row are
 * minted in one transaction (GHSA-m5p9-6pw7-pf3g), and an active delegate may
 * register a share just as the owner can.
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\DirectShareRegistrar;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
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
		// row. A test that shares a received copy flips the flag.
		$this->mapper->method('findByRecipientSecret')->willReturnCallback(
			function (): ShareTarget {
				if ($this->sourceIsReceivedCopy === true) {
					return new ShareTarget();
				}

				throw new DoesNotExistException('none');
			}
		);
	}//end setUp()

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
	 * @param ShareAuthorizationService|null $authorization The owner-or-delegate check
	 *
	 * @return DirectShareRegistrar
	 */
	private function registrar(?ShareAuthorizationService $authorization = null): DirectShareRegistrar {
		return new DirectShareRegistrar(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			copyFactory: $this->copyFactory,
			notificationService: $this->notificationService,
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
	 * An active delegate of the source may register the share, exactly as the
	 * owner can. Routing the ownership check through isOwnerOrDelegate restores
	 * the capability the retired createShare endpoint had.
	 *
	 * @return void
	 */
	public function testAnActiveDelegateMayRegisterAShare(): void {
		$source = $this->ownerSecret('src-1', 'alice');
		$this->secretMapper->method('findById')->willReturn($source);
		$this->mapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException('none'));
		$this->copyFactory->method('create')->willReturn($this->recipientCopy('copy-1', 'bob'));
		$this->mapper->method('insert')->willReturnArgument(0);

		$authorization = $this->createMock(ShareAuthorizationService::class);
		$authorization->method('isOwnerOrDelegate')
			->willReturnCallback(static fn (Secret $secret, string $userId): bool => $userId === 'dave');

		// Dave, an active delegate (not the owner), succeeds.
		$report = $this->registrar(authorization: $authorization)->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);
		$this->assertSame('created', $report[0]['status']);
	}//end testAnActiveDelegateMayRegisterAShare()

	/**
	 * A caller who is neither owner nor an active delegate is refused.
	 *
	 * @return void
	 */
	public function testAStrangerMayNotRegisterAShare(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownerSecret('src-1', 'alice'));

		$authorization = $this->createMock(ShareAuthorizationService::class);
		$authorization->method('isOwnerOrDelegate')->willReturn(false);
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

		$delegationMapper = $this->createMock(SecretDelegationMapper::class);
		$delegationMapper->method('findActiveBySecretAndUser')->willReturn(new SecretDelegation());
		$authorization = new ShareAuthorizationService(
			secretMapper: $this->secretMapper,
			delegationMapper: $delegationMapper,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
		);

		$report = $this->registrar(authorization: $authorization)->registerDirectShares(
			userId: 'dave',
			shares: [['sourceSecretId' => 'src-1', 'targetUserId' => 'bob', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('restricted', $report[0]['status']);
	}//end testADelegateWithARestrictedCopyIsRefused()

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

		$authorization = $this->createMock(ShareAuthorizationService::class);
		$authorization->method('isOwnerOrDelegate')->willReturn(true);

		$report = $this->registrar(authorization: $authorization)->registerDirectShares(
			userId: 'bob',
			shares: [['sourceSecretId' => 'copy-1', 'targetUserId' => 'carol', 'encryptedKey' => 'blob']]
		);

		$this->assertSame('not_shareable', $report[0]['status']);
	}//end testAReceivedCopyMayNotBeSharedOnward()
}//end class
