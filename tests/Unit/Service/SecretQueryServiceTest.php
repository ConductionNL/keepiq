<?php

/**
 * Doriath SecretQueryService unit tests.
 *
 * @category Tests
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use OCA\Doriath\Db\Secret;
use OCA\Doriath\Db\SecretMapper;
use OCA\Doriath\Exception\ForbiddenException;
use OCA\Doriath\Service\SecretFuzzySearch;
use OCA\Doriath\Service\SecretQueryService;
use OCA\Doriath\Service\SecretSuiteGuard;
use PHPUnit\Framework\TestCase;

class SecretQueryServiceTest extends TestCase
{
    private SecretMapper $mapper;
    private SecretSuiteGuard $suiteGuard;
    private SecretFuzzySearch $fuzzySearch;
    private SecretQueryService $service;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretMapper::class);
        $this->suiteGuard = $this->createMock(SecretSuiteGuard::class);
        $this->fuzzySearch = $this->createMock(SecretFuzzySearch::class);

        $this->service = new SecretQueryService(
            $this->mapper,
            $this->suiteGuard,
            $this->fuzzySearch,
        );
    }

    private function makeSecret(string $id, string $owner, string $suite = 'suite-1'): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName('secret-'.$id);
        $secret->setOwnerType('user');
        $secret->setOwnerId($owner);
        $secret->setEncryptionSuiteId($suite);
        $secret->setSecretKey('cipher');
        return $secret;
    }

    public function testGetForeignSecretRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret('s1', 'bob'));

        $this->expectException(ForbiddenException::class);
        $this->service->get('s1', 'alice');
    }

    public function testGetBlockedSuiteRejected(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret('s1', 'alice'));
        $this->suiteGuard->method('isSecretBlocked')->willReturn(true);

        $this->expectException(ForbiddenException::class);
        $this->service->get('s1', 'alice');
    }

    public function testGetOwnSecret(): void
    {
        $this->mapper->method('findById')->willReturn($this->makeSecret('s1', 'alice'));
        $this->suiteGuard->method('isSecretBlocked')->willReturn(false);

        $secret = $this->service->get('s1', 'alice');
        $this->assertSame('s1', $secret->getId());
    }

    public function testListWithholdsBlobsForBlockedSecret(): void
    {
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret('s1', 'alice')]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->suiteGuard->method('isSecretBlocked')->willReturn(true);

        $result = $this->service->list('alice', [], 'name', 'asc', 1, 50);

        $this->assertSame(1, $result['total']);
        $this->assertTrue($result['items'][0]['blocked']);
        $this->assertArrayNotHasKey('key', $result['items'][0]);
        $this->assertArrayHasKey('blocked_reason', $result['items'][0]);
    }

    public function testListIncludesBlobsForAccessibleSecret(): void
    {
        $this->mapper->method('findByOwner')->willReturn([$this->makeSecret('s1', 'alice')]);
        $this->mapper->method('countByOwner')->willReturn(1);
        $this->suiteGuard->method('isSecretBlocked')->willReturn(false);

        $result = $this->service->list('alice', [], 'name', 'asc', 1, 50);

        $this->assertFalse($result['items'][0]['blocked']);
        $this->assertSame('cipher', $result['items'][0]['key']);
    }

    public function testEmptySearchReturnsNoItems(): void
    {
        $result = $this->service->search('alice', '   ', 1, 50);
        $this->assertSame(0, $result['total']);
        $this->assertEmpty($result['items']);
    }

    public function testSearchPaginatesFuzzyResults(): void
    {
        $secrets = [
            $this->makeSecret('s1', 'alice'),
            $this->makeSecret('s2', 'alice'),
            $this->makeSecret('s3', 'alice'),
        ];
        $this->fuzzySearch->method('match')->willReturn($secrets);
        $this->suiteGuard->method('isSecretBlocked')->willReturn(false);

        $result = $this->service->search('alice', 'secret', 1, 2);

        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['items']);
    }
}
