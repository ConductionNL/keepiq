<?php

/**
 * Doriath SecretFuzzySearch unit tests.
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
use OCA\Doriath\Service\SecretFuzzySearch;
use PHPUnit\Framework\TestCase;

class SecretFuzzySearchTest extends TestCase
{
    private SecretMapper $mapper;
    private SecretFuzzySearch $search;

    protected function setUp(): void
    {
        $this->mapper = $this->createMock(SecretMapper::class);
        $this->search = new SecretFuzzySearch($this->mapper);
    }

    private function makeSecret(string $id, string $name, ?string $url = null): Secret
    {
        $secret = new Secret();
        $secret->setId($id);
        $secret->setName($name);
        $secret->setUrl($url);
        return $secret;
    }

    public function testExactSubstringMatch(): void
    {
        $github = $this->makeSecret('1', 'GitHub', 'https://github.com');
        $this->mapper->method('searchByNameOrUrl')->willReturn([$github]);
        $this->mapper->method('findAllByOwner')->willReturn([$github]);

        $results = $this->search->match('user', 'alice', 'GitHub');

        $this->assertCount(1, $results);
        $this->assertSame('1', $results[0]->getId());
    }

    public function testFuzzyTypoMatch(): void
    {
        $github = $this->makeSecret('1', 'GitHub', 'https://github.com');
        // SQL pre-filter misses the typo; Levenshtein catches it.
        $this->mapper->method('searchByNameOrUrl')->willReturn([]);
        $this->mapper->method('findAllByOwner')->willReturn([$github]);

        $results = $this->search->match('user', 'alice', 'Githb');

        $this->assertCount(1, $results);
    }

    public function testNoMeaningfulMatch(): void
    {
        $github = $this->makeSecret('1', 'GitHub', 'https://github.com');
        $this->mapper->method('searchByNameOrUrl')->willReturn([]);
        $this->mapper->method('findAllByOwner')->willReturn([$github]);

        $results = $this->search->match('user', 'alice', 'xyzzyplugh');

        $this->assertCount(0, $results);
    }

    public function testUrlSubstringMatch(): void
    {
        // "github.com" is a substring of the URL, so the SQL pre-filter (stage 1)
        // catches it directly — no Levenshtein needed.
        $github = $this->makeSecret('1', 'GitHub', 'https://github.com');
        $this->mapper->method('searchByNameOrUrl')->willReturn([$github]);
        $this->mapper->method('findAllByOwner')->willReturn([$github]);

        $results = $this->search->match('user', 'alice', 'github.com');

        $this->assertCount(1, $results);
    }

    public function testResultsDeduplicatedAndSorted(): void
    {
        $beta = $this->makeSecret('2', 'Beta');
        $alpha = $this->makeSecret('1', 'Alpha');
        // Same entity surfaces in both stages — must not duplicate.
        $this->mapper->method('searchByNameOrUrl')->willReturn([$beta, $alpha]);
        $this->mapper->method('findAllByOwner')->willReturn([$beta, $alpha]);

        $results = $this->search->match('user', 'alice', 'a');

        $this->assertCount(2, $results);
        $this->assertSame('Alpha', $results[0]->getName());
        $this->assertSame('Beta', $results[1]->getName());
    }
}
