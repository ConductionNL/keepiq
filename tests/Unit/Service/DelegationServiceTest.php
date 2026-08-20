<?php

/**
 * Unit tests for DelegationService.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretDelegation;
use OCA\Doriath\Db\SecretDelegationMapper;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Db\ShareTarget;
use OCA\Doriath\Db\ShareTargetMapper;
use OCA\Doriath\Service\DelegationAuthorizer;
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DelegationService.
 */
class DelegationServiceTest extends TestCase {
	/**
	 * Build a service + return all the collaborator mocks.
	 *
	 * @return array{0:DelegationService,1:SecretDelegationMapper,2:SecretMapper}
	 */
	private function build(): array {
		$mapper = $this->createMock(originalClassName: SecretDelegationMapper::class);
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$service = new DelegationService(
			mapper: $mapper,
			authorizer: new DelegationAuthorizer(secretMapper: $secretMapper),
		);

		return [$service, $mapper, $secretMapper];
	}//end build()

	/**
	 * Build a baseline Secret for assertions.
	 *
	 * @param string $ownerId The owner user ID
	 *
	 * @return Secret
	 */
	private function buildSecret(string $ownerId = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end buildSecret()

	/**
	 * createDelegation persists a temporary delegation row.
	 *
	 * @return void
	 */
	public function testCreateDelegationPersistsTemporary(): void {
		[$service, $mapper, $secretMapper] = $this->build();

		$secretMapper->method('findById')->willReturn($this->buildSecret());

		$mapper->expects(matcher: $this->once())
			->method('insert')
			->willReturnCallback(static fn (SecretDelegation $e): SecretDelegation => $e);

		$entity = $service->createDelegation(secretId: 'sec-1', delegatedTo: 'bob', initiatedBy: 'alice');

		$this->assertSame('sec-1', $entity->getSecretId());
		$this->assertSame('alice', $entity->getOriginalOwnerId());
		$this->assertSame('bob', $entity->getDelegatedTo());
		$this->assertSame('alice', $entity->getInitiatedBy());
		$this->assertFalse($entity->getIsPermanent());
		$this->assertNotSame('', $entity->getId());
	}//end testCreateDelegationPersistsTemporary()

	/**
	 * createDelegation refuses non-owners.
	 *
	 * @return void
	 */
	public function testCreateDelegationRejectsNonOwner(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$mapper->expects(matcher: $this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createDelegation(secretId: 'sec-1', delegatedTo: 'bob', initiatedBy: 'mallory');
	}//end testCreateDelegationRejectsNonOwner()

	/**
	 * createDelegation refuses self-delegation.
	 *
	 * @return void
	 */
	public function testCreateDelegationRejectsSelf(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$mapper->expects(matcher: $this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createDelegation(secretId: 'sec-1', delegatedTo: 'alice', initiatedBy: 'alice');
	}//end testCreateDelegationRejectsSelf()

	/**
	 * createDelegation refuses unknown secrets.
	 *
	 * @return void
	 */
	public function testCreateDelegationRejectsUnknownSecret(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willThrowException(new DoesNotExistException(msg: 'gone'));
		$mapper->expects(matcher: $this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createDelegation(secretId: 'sec-missing', delegatedTo: 'bob', initiatedBy: 'alice');
	}//end testCreateDelegationRejectsUnknownSecret()

	/**
	 * reclaimDelegation removes only temporary rows owned by the caller.
	 *
	 * @return void
	 */
	public function testReclaimDelegationRemovesOnlyTemporaryOwnedRows(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));

		$temp = new SecretDelegation();
		$temp->setId('d-1');
		$temp->setOriginalOwnerId('alice');
		$temp->setIsPermanent(false);

		$perm = new SecretDelegation();
		$perm->setId('d-2');
		$perm->setOriginalOwnerId('alice');
		$perm->setIsPermanent(true);

		$foreign = new SecretDelegation();
		$foreign->setId('d-3');
		$foreign->setOriginalOwnerId('someoneelse');
		$foreign->setIsPermanent(false);

		$mapper->method('findBySecret')->willReturn([$temp, $perm, $foreign]);

		$deleted = [];
		$mapper->expects(matcher: $this->once())
			->method('delete')
			->willReturnCallback(
				static function (SecretDelegation $e) use (&$deleted): SecretDelegation {
					$deleted[] = $e->getId();
					return $e;
				}
			);

		$count = $service->reclaimDelegation(secretId: 'sec-1', ownerId: 'alice');
		$this->assertSame(1, $count);
		$this->assertSame(['d-1'], $deleted);
	}//end testReclaimDelegationRemovesOnlyTemporaryOwnedRows()

	/**
	 * reclaimDelegation refuses non-owners.
	 *
	 * @return void
	 */
	public function testReclaimDelegationRejectsNonOwner(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$mapper->expects(matcher: $this->never())->method('findBySecret');

		$this->expectException(InvalidArgumentException::class);
		$service->reclaimDelegation(secretId: 'sec-1', ownerId: 'mallory');
	}//end testReclaimDelegationRejectsNonOwner()

	/**
	 * getDelegationsForSecret returns rows only for the owner.
	 *
	 * @return void
	 */
	public function testGetDelegationsForSecretReturnsForOwner(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));

		$entity = new SecretDelegation();
		$entity->setId('d-1');
		$mapper->expects(matcher: $this->once())
			->method('findBySecret')
			->with('sec-1')
			->willReturn([$entity]);

		$rows = $service->getDelegationsForSecret(secretId: 'sec-1', ownerId: 'alice');
		$this->assertCount(1, $rows);
	}//end testGetDelegationsForSecretReturnsForOwner()

	/**
	 * getDelegationsForSecret refuses non-owners.
	 *
	 * @return void
	 */
	public function testGetDelegationsForSecretRejectsNonOwner(): void {
		[$service, $mapper, $secretMapper] = $this->build();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$mapper->expects(matcher: $this->never())->method('findBySecret');

		$this->expectException(InvalidArgumentException::class);
		$service->getDelegationsForSecret(secretId: 'sec-1', ownerId: 'mallory');
	}//end testGetDelegationsForSecretRejectsNonOwner()

	/**
	 * Build a service with the share-target mapper + group manager wired
	 * so the admin-handover branch can be exercised.
	 *
	 * @return array{0:DelegationService,1:SecretDelegationMapper,2:SecretMapper,3:ShareTargetMapper,4:IGroupManager}
	 */
	private function buildWithAdmin(): array {
		$mapper = $this->createMock(originalClassName: SecretDelegationMapper::class);
		$secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$shareTargetMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$groupManager = $this->createMock(originalClassName: IGroupManager::class);

		$service = new DelegationService(
			mapper: $mapper,
			authorizer: new DelegationAuthorizer(
				secretMapper: $secretMapper,
				shareTargetMapper: $shareTargetMapper,
				groupManager: $groupManager,
			),
		);

		return [$service, $mapper, $secretMapper, $shareTargetMapper, $groupManager];
	}//end buildWithAdmin()

	/**
	 * Owner self-delegation is rejected when the delegate holds no share
	 * (the wire-in adds the pre-existing-share precondition from
	 * ownership-delegation/spec.md).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationRejectsDelegateWithoutPreExistingShare(): void {
		[$service, $mapper, $secretMapper, $shareTargetMapper, $_groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException(msg: 'no share'));
		$mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createDelegation(secretId: 'sec-1', delegatedTo: 'bob', initiatedBy: 'alice');
	}//end testCreateDelegationRejectsDelegateWithoutPreExistingShare()

	/**
	 * Owner self-delegation succeeds when the delegate already holds a
	 * share of the secret.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationAcceptsDelegateWithPreExistingShare(): void {
		[$service, $mapper, $secretMapper, $shareTargetMapper, $_groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')->willReturn(new ShareTarget());
		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (SecretDelegation $e): SecretDelegation => $e);

		$entity = $service->createDelegation(secretId: 'sec-1', delegatedTo: 'bob', initiatedBy: 'alice');
		$this->assertSame('bob', $entity->getDelegatedTo());
	}//end testCreateDelegationAcceptsDelegateWithPreExistingShare()

	/**
	 * Admin handover: a vault_admin who already holds a share of someone
	 * else's secret can promote their own copy to co-owner without owner
	 * consent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-6.2
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationAdminHandoverPromotesAdminCopy(): void {
		[$service, $mapper, $secretMapper, $shareTargetMapper, $groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$groupManager->method('isInGroup')
			->with('mallory', DelegationAuthorizer::VAULT_ADMIN_GROUP)
			->willReturn(true);
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')->willReturn(new ShareTarget());
		$mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static fn (SecretDelegation $e): SecretDelegation => $e);

		$entity = $service->createAdminHandover(
			secretId: 'sec-1',
			delegatedTo: 'mallory',
			initiatedBy: 'mallory',
		);

		$this->assertSame('alice', $entity->getOriginalOwnerId());
		$this->assertSame('mallory', $entity->getDelegatedTo());
		$this->assertSame('mallory', $entity->getInitiatedBy());
		$this->assertFalse($entity->getIsPermanent());
	}//end testCreateDelegationAdminHandoverPromotesAdminCopy()

	/**
	 * Admin handover is rejected when the caller is not in the
	 * vault_admin group.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationAdminHandoverRejectsNonAdmin(): void {
		[$service, $mapper, $secretMapper, $_shareTargetMapper, $groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$groupManager->method('isInGroup')->willReturn(false);
		$mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createAdminHandover(
			secretId: 'sec-1',
			delegatedTo: 'mallory',
			initiatedBy: 'mallory',
		);
	}//end testCreateDelegationAdminHandoverRejectsNonAdmin()

	/**
	 * Admin handover is rejected when the admin holds no share of the
	 * secret (the "must already be a recipient" invariant).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationAdminHandoverRejectsWithoutShare(): void {
		[$service, $mapper, $secretMapper, $shareTargetMapper, $groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$groupManager->method('isInGroup')->willReturn(true);
		$shareTargetMapper->method('findBySourceSecretAndTargetUser')
			->willThrowException(new DoesNotExistException(msg: 'no share'));
		$mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createAdminHandover(
			secretId: 'sec-1',
			delegatedTo: 'mallory',
			initiatedBy: 'mallory',
		);
	}//end testCreateDelegationAdminHandoverRejectsWithoutShare()

	/**
	 * Admin handover is rejected when the initiator is already the
	 * owner — there is nothing to grab.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#task-17.1
	 */
	public function testCreateDelegationAdminHandoverRejectsOwnerAsInitiator(): void {
		[$service, $mapper, $secretMapper, $_shareTargetMapper, $groupManager] = $this->buildWithAdmin();
		$secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
		$groupManager->method('isInGroup')->willReturn(true);
		$mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$service->createAdminHandover(
			secretId: 'sec-1',
			delegatedTo: 'alice',
			initiatedBy: 'alice',
		);
	}//end testCreateDelegationAdminHandoverRejectsOwnerAsInitiator()

	/**
	 * makePermanent delegates to the mapper update.
	 *
	 * @return void
	 */
	public function testMakePermanentDelegatesToMapper(): void {
		[$service, $mapper, $_] = $this->build();
		$mapper->expects(matcher: $this->once())
			->method('makePermanentByOriginalOwner')
			->with('alice')
			->willReturn(3);

		$this->assertSame(3, $service->makePermanent(originalOwnerId: 'alice'));
	}//end testMakePermanentDelegatesToMapper()
}//end class
