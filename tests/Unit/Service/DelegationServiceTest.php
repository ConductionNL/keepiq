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
use OCA\Doriath\Service\DelegationService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for DelegationService.
 */
class DelegationServiceTest extends TestCase
{
    /**
     * Build a service + return all the collaborator mocks.
     *
     * @return array{0:DelegationService,1:SecretDelegationMapper,2:SecretMapper}
     */
    private function build(): array
    {
        $mapper       = $this->createMock(originalClassName: SecretDelegationMapper::class);
        $secretMapper = $this->createMock(originalClassName: SecretMapper::class);
        $logger       = $this->createMock(originalClassName: LoggerInterface::class);
        $service      = new DelegationService(
            mapper: $mapper,
            secretMapper: $secretMapper,
            logger: $logger,
        );

        return [$service, $mapper, $secretMapper];
    }

    /**
     * Build a baseline Secret for assertions.
     *
     * @param string $ownerId The owner user ID
     *
     * @return Secret
     */
    private function buildSecret(string $ownerId = 'alice'): Secret
    {
        $secret = new Secret();
        $secret->setId('sec-1');
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        return $secret;
    }

    /**
     * createDelegation persists a temporary delegation row.
     *
     * @return void
     */
    public function testCreateDelegationPersistsTemporary(): void
    {
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
    }

    /**
     * createDelegation refuses non-owners.
     *
     * @return void
     */
    public function testCreateDelegationRejectsNonOwner(): void
    {
        [$service, $mapper, $secretMapper] = $this->build();
        $secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
        $mapper->expects(matcher: $this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $service->createDelegation(secretId: 'sec-1', delegatedTo: 'bob', initiatedBy: 'mallory');
    }

    /**
     * createDelegation refuses self-delegation.
     *
     * @return void
     */
    public function testCreateDelegationRejectsSelf(): void
    {
        [$service, $mapper, $secretMapper] = $this->build();
        $secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
        $mapper->expects(matcher: $this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $service->createDelegation(secretId: 'sec-1', delegatedTo: 'alice', initiatedBy: 'alice');
    }

    /**
     * createDelegation refuses unknown secrets.
     *
     * @return void
     */
    public function testCreateDelegationRejectsUnknownSecret(): void
    {
        [$service, $mapper, $secretMapper] = $this->build();
        $secretMapper->method('findById')->willThrowException(new DoesNotExistException(msg: 'gone'));
        $mapper->expects(matcher: $this->never())->method('insert');

        $this->expectException(InvalidArgumentException::class);
        $service->createDelegation(secretId: 'sec-missing', delegatedTo: 'bob', initiatedBy: 'alice');
    }

    /**
     * reclaimDelegation removes only temporary rows owned by the caller.
     *
     * @return void
     */
    public function testReclaimDelegationRemovesOnlyTemporaryOwnedRows(): void
    {
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
            ->willReturnCallback(static function (SecretDelegation $e) use (&$deleted): SecretDelegation {
                $deleted[] = $e->getId();
                return $e;
            });

        $count = $service->reclaimDelegation(secretId: 'sec-1', ownerId: 'alice');
        $this->assertSame(1, $count);
        $this->assertSame(['d-1'], $deleted);
    }

    /**
     * reclaimDelegation refuses non-owners.
     *
     * @return void
     */
    public function testReclaimDelegationRejectsNonOwner(): void
    {
        [$service, $mapper, $secretMapper] = $this->build();
        $secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
        $mapper->expects(matcher: $this->never())->method('findBySecret');

        $this->expectException(InvalidArgumentException::class);
        $service->reclaimDelegation(secretId: 'sec-1', ownerId: 'mallory');
    }

    /**
     * getDelegationsForSecret returns rows only for the owner.
     *
     * @return void
     */
    public function testGetDelegationsForSecretReturnsForOwner(): void
    {
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
    }

    /**
     * getDelegationsForSecret refuses non-owners.
     *
     * @return void
     */
    public function testGetDelegationsForSecretRejectsNonOwner(): void
    {
        [$service, $mapper, $secretMapper] = $this->build();
        $secretMapper->method('findById')->willReturn($this->buildSecret(ownerId: 'alice'));
        $mapper->expects(matcher: $this->never())->method('findBySecret');

        $this->expectException(InvalidArgumentException::class);
        $service->getDelegationsForSecret(secretId: 'sec-1', ownerId: 'mallory');
    }

    /**
     * makePermanent delegates to the mapper update.
     *
     * @return void
     */
    public function testMakePermanentDelegatesToMapper(): void
    {
        [$service, $mapper, $_] = $this->build();
        $mapper->expects(matcher: $this->once())
            ->method('makePermanentByOriginalOwner')
            ->with('alice')
            ->willReturn(3);

        $this->assertSame(3, $service->makePermanent(originalOwnerId: 'alice'));
    }
}
