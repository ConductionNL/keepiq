<?php

/**
 * Unit tests for SecretService.
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
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Exception\NotFoundException;
use OCA\Doriath\Exception\SuiteBlockedException;
use OCA\Doriath\Exception\WriteLockedException;
use OCA\Doriath\Service\LinkShareService;
use OCA\Doriath\Service\MigrationService;
use OCA\Doriath\Service\SecretService;
use OCA\Doriath\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SecretService.
 */
class SecretServiceTest extends TestCase
{
    /** @var SecretService */
    private SecretService $service;

    /** @var SecretMapper */
    private $mapper;

    /** @var SecretTypeService */
    private $typeService;

    /** @var EncryptionSuiteMapper */
    private $suiteMapper;

    /** @var MigrationService */
    private $migrationService;

    /** @var LinkShareService */
    private $linkShareService;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->mapper           = $this->createMock(SecretMapper::class);
        $this->typeService      = $this->createMock(SecretTypeService::class);
        $this->suiteMapper      = $this->createMock(EncryptionSuiteMapper::class);
        $this->migrationService = $this->createMock(MigrationService::class);
        $this->linkShareService = $this->createMock(LinkShareService::class);
        $logger                 = $this->createMock(LoggerInterface::class);

        $this->service = new SecretService(
            mapper: $this->mapper,
            typeService: $this->typeService,
            suiteMapper: $this->suiteMapper,
            migrationService: $this->migrationService,
            linkShareService: $this->linkShareService,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Build a suite with a status.
     *
     * @param string $status The suite status
     * @param string $id     The suite ID
     *
     * @return EncryptionSuite
     */
    private function makeSuite(string $status = 'active', string $id = 'suite-1'): EncryptionSuite
    {
        $suite = new EncryptionSuite();
        $suite->setId($id);
        $suite->setOwnerType('user');
        $suite->setOwnerId('alice');
        $suite->setStatus($status);
        return $suite;
    }//end makeSuite()

    /**
     * Build a secret owned by alice.
     *
     * @param string $id      The secret ID
     * @param string $ownerId The owner ID
     * @param string $suiteId The suite ID
     *
     * @return Secret
     */
    private function makeSecret(string $id = 's-1', string $ownerId = 'alice', string $suiteId = 'suite-1'): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName('GitHub');
        $secret->setUrl('https://github.com');
        $secret->setTypeId('login-id');
        $secret->setKey('CIPHERTEXT');
        $secret->setEncryptionSuiteId($suiteId);
        $secret->setOwnerType('user');
        $secret->setOwnerId($ownerId);
        return $secret;
    }//end makeSecret()

    /**
     * Create stores the encrypted blob and records the suite.
     *
     * @return void
     */
    public function testCreateStoresCiphertextAndSuite(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->suiteMapper->method('findActiveByOwner')->willReturn($this->makeSuite());
        $this->typeService->method('resolveTypeForSecret')->willReturn('login-id');
        $this->mapper->expects($this->once())->method('insert');

        $secret = $this->service->create(
            data: ['name' => 'GitHub', 'key' => 'CIPHERTEXT'],
            userId: 'alice',
        );

        $this->assertSame('CIPHERTEXT', $secret->getKey());
        $this->assertSame('suite-1', $secret->getEncryptionSuiteId());
        $this->assertSame('login-id', $secret->getTypeId());
    }//end testCreateStoresCiphertextAndSuite()

    /**
     * Create requires a name and a key.
     *
     * @return void
     */
    public function testCreateMissingFieldsRejected(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->expectException(InvalidArgumentException::class);
        $this->service->create(data: ['name' => 'NoKey'], userId: 'alice');
    }//end testCreateMissingFieldsRejected()

    /**
     * Create is blocked when no active suite exists (e.g. revoked).
     *
     * @return void
     */
    public function testCreateBlockedWhenNoActiveSuite(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(false);
        $this->suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));

        $this->expectException(SuiteBlockedException::class);
        $this->service->create(data: ['name' => 'X', 'key' => 'C'], userId: 'alice');
    }//end testCreateBlockedWhenNoActiveSuite()

    /**
     * Create is rejected with a write lock.
     *
     * @return void
     */
    public function testCreateRejectedDuringWriteLock(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(WriteLockedException::class);
        $this->service->create(data: ['name' => 'X', 'key' => 'C'], userId: 'alice');
    }//end testCreateRejectedDuringWriteLock()

    /**
     * Reading another user's secret is forbidden.
     *
     * @return void
     */
    public function testGetForeignSecretForbidden(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret(ownerId: 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->get(id: 's-1', userId: 'alice');
    }//end testGetForeignSecretForbidden()

    /**
     * Reading a missing secret throws NotFound.
     *
     * @return void
     */
    public function testGetMissingSecret(): void
    {
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

        $this->expectException(NotFoundException::class);
        $this->service->get(id: 'gone', userId: 'alice');
    }//end testGetMissingSecret()

    /**
     * A secret with a revoked suite returns 403 (blocked) on read.
     *
     * @return void
     */
    public function testGetRevokedSuiteBlocked(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret());
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'revoked'));

        $this->expectException(SuiteBlockedException::class);
        $this->service->get(id: 's-1', userId: 'alice');
    }//end testGetRevokedSuiteBlocked()

    /**
     * A secret with an active suite reads normally.
     *
     * @return void
     */
    public function testGetActiveSuiteReturnsSecret(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret());
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'active'));

        $secret = $this->service->get(id: 's-1', userId: 'alice');
        $this->assertSame('s-1', $secret->getId());
    }//end testGetActiveSuiteReturnsSecret()

    /**
     * List includes blocked secrets with metadata only (no ciphertext).
     *
     * @return void
     */
    public function testListBlockedSecretOmitsCiphertext(): void
    {
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'revoked'));

        $result = $this->service->list(
            userId: 'alice',
            folderId: null,
            sort: 'name',
            direction: 'asc',
            page: 1,
            limit: 50,
        );

        $item = $result['items'][0];
        $this->assertTrue($item['blocked']);
        $this->assertArrayNotHasKey('key', $item);
        $this->assertArrayHasKey('blockedReason', $item);
        $this->assertSame('GitHub', $item['name']);
    }//end testListBlockedSecretOmitsCiphertext()

    /**
     * List returns the full encrypted blob for an active suite.
     *
     * @return void
     */
    public function testListActiveSecretIncludesCiphertext(): void
    {
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->suiteMapper->method('findById')->willReturn($this->makeSuite(status: 'active'));

        $result = $this->service->list(
            userId: 'alice',
            folderId: null,
            sort: 'name',
            direction: 'asc',
            page: 1,
            limit: 50,
        );

        $item = $result['items'][0];
        $this->assertFalse($item['blocked']);
        $this->assertSame('CIPHERTEXT', $item['key']);
    }//end testListActiveSecretIncludesCiphertext()

    /**
     * Update is rejected during a write lock.
     *
     * @return void
     */
    public function testUpdateRejectedDuringWriteLock(): void
    {
        $this->migrationService->method('isWriteLocked')->willReturn(true);

        $this->expectException(WriteLockedException::class);
        $this->service->update(id: 's-1', data: ['name' => 'New'], userId: 'alice');
    }//end testUpdateRejectedDuringWriteLock()

    /**
     * Delete cascades to link shares and removes the secret.
     *
     * @return void
     */
    public function testDeleteCascadesToLinkShares(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret());
        $this->linkShareService->expects($this->once())->method('deleteBySecretId')->with('s-1');
        $this->mapper->expects($this->once())->method('delete');

        $this->service->delete(id: 's-1', userId: 'alice');
    }//end testDeleteCascadesToLinkShares()

    /**
     * Delete cascades to GroupShares and SecretDelegations when the
     * optional mappers are wired (W29 §10.1).
     *
     * @return void
     *
     * @spec openspec/changes/implement-user-sharing/tasks.md#task-10.1
     */
    public function testDeleteCascadesToGroupSharesAndDelegations(): void
    {
        $groupShareMapper = $this->createMock(\OCA\Doriath\Db\GroupShareMapper::class);
        $delegationMapper = $this->createMock(\OCA\Doriath\Db\SecretDelegationMapper::class);
        $logger           = $this->createMock(LoggerInterface::class);

        $service = new SecretService(
            mapper: $this->mapper,
            typeService: $this->typeService,
            suiteMapper: $this->suiteMapper,
            migrationService: $this->migrationService,
            linkShareService: $this->linkShareService,
            logger: $logger,
            secretRequestService: null,
            shareService: null,
            groupShareMapper: $groupShareMapper,
            secretDelegationMapper: $delegationMapper,
        );

        $this->mapper->method('findById')->willReturn($this->makeSecret());
        $this->linkShareService->expects($this->once())->method('deleteBySecretId')->with('s-1');
        $groupShareMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
        $delegationMapper->expects($this->once())->method('deleteBySecret')->with('s-1');
        $this->mapper->expects($this->once())->method('delete');

        $service->delete(id: 's-1', userId: 'alice');
    }//end testDeleteCascadesToGroupSharesAndDelegations()

    /**
     * Fuzzy search matches an exact substring.
     *
     * @return void
     */
    public function testFuzzyExactSubstring(): void
    {
        $this->mapper->method('searchByNameOrUrl')->willReturn([$this->makeSecret()]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

        $matched = $this->service->fuzzyMatch(userId: 'alice', term: 'GitHub');
        $this->assertCount(1, $matched);
    }//end testFuzzyExactSubstring()

    /**
     * Fuzzy search tolerates a one-character typo.
     *
     * @return void
     */
    public function testFuzzyTypoDistanceOne(): void
    {
        // SQL pre-filter misses the typo; the Levenshtein pass must catch it.
        $this->mapper->method('searchByNameOrUrl')->willReturn([]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

        $matched = $this->service->fuzzyMatch(userId: 'alice', term: 'Githb');
        $this->assertCount(1, $matched);
    }//end testFuzzyTypoDistanceOne()

    /**
     * Fuzzy search returns nothing for an unrelated query.
     *
     * @return void
     */
    public function testFuzzyNoMatch(): void
    {
        $this->mapper->method('searchByNameOrUrl')->willReturn([]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret()]);

        $matched = $this->service->fuzzyMatch(userId: 'alice', term: 'xyzzyplugh');
        $this->assertCount(0, $matched);
    }//end testFuzzyNoMatch()

    /**
     * An empty search term returns an empty result set.
     *
     * @return void
     */
    public function testSearchEmptyTerm(): void
    {
        $result = $this->service->search(userId: 'alice', term: '   ', page: 1, limit: 50);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['items']);
    }//end testSearchEmptyTerm()

    /**
     * createForApplication resolves the application's active suite and persists
     * the row with owner_type=application.
     *
     * @return void
     */
    public function testCreateForApplicationPersistsApplicationOwnedSecret(): void
    {
        $suite = $this->makeSuite(status: 'active', id: 'app-suite-1');
        $this->suiteMapper->expects($this->once())
            ->method('findActiveByOwner')
            ->with('application', 'app-1')
            ->willReturn($suite);

        $this->typeService->method('resolveTypeForSecret')->willReturn('type-default');

        $captured = null;
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                static function (Secret $entity) use (&$captured) {
                    $captured = $entity;
                    return $entity;
                }
            );

        $result = $this->service->createForApplication(
            data: ['name' => 'PROD-DB', 'key' => 'cipher'],
            applicationId: 'app-1',
            writingUserId: 'alice',
        );

        $this->assertSame($captured, $result);
        $this->assertSame('application', $result->getOwnerType());
        $this->assertSame('app-1', $result->getOwnerId());
        $this->assertSame('app-suite-1', $result->getEncryptionSuiteId());
        $this->assertSame('PROD-DB', $result->getName());
    }//end testCreateForApplicationPersistsApplicationOwnedSecret()

    /**
     * createForApplication blocks when the application has no active suite.
     *
     * @return void
     */
    public function testCreateForApplicationRejectsMissingSuite(): void
    {
        $this->suiteMapper->expects($this->once())
            ->method('findActiveByOwner')
            ->willThrowException(new DoesNotExistException('no suite'));

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(SuiteBlockedException::class);
        $this->expectExceptionMessage('No active EncryptionSuite for application app-1');

        $this->service->createForApplication(
            data: ['name' => 'X', 'key' => 'cipher'],
            applicationId: 'app-1',
            writingUserId: 'alice',
        );
    }//end testCreateForApplicationRejectsMissingSuite()
}//end class
