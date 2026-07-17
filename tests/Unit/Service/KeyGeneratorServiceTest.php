<?php

/**
 * Unit tests for KeyGeneratorService.
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
use OCA\Doriath\Service\KeyGeneratorService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KeyGeneratorService.
 */
class KeyGeneratorServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var KeyGeneratorService
     */
    private KeyGeneratorService $service;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new KeyGeneratorService();
    }//end setUp()

    /**
     * Default mode produces a 16-character string from the full set.
     *
     * @return void
     */
    public function testDefaultLengthAndCharset(): void
    {
        $key = $this->service->generate();

        $this->assertSame(16, strlen($key));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9!@#$%^&*()\-_=+\[\]{}|;:,.<>?\/]{16}$/', $key);
    }//end testDefaultLengthAndCharset()

    /**
     * Custom length is honoured.
     *
     * @return void
     */
    public function testCustomLength(): void
    {
        $key = $this->service->generate(length: 24);
        $this->assertSame(24, strlen($key));
    }//end testCustomLength()

    /**
     * Exact minimum length is accepted.
     *
     * @return void
     */
    public function testMinimumLengthAccepted(): void
    {
        $key = $this->service->generate(length: 8);
        $this->assertSame(8, strlen($key));
    }//end testMinimumLengthAccepted()

    /**
     * Disabling special characters yields an alphanumeric-only key.
     *
     * @return void
     */
    public function testNoSpecialCharacters(): void
    {
        $key = $this->service->generate(length: 32, includeSpecialCharacters: false);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $key);
    }//end testNoSpecialCharacters()

    /**
     * Excluded characters never appear in the output.
     *
     * @return void
     */
    public function testCharacterExclusion(): void
    {
        // Generate many keys to make accidental absence unlikely.
        for ($i = 0; $i < 50; $i++) {
            $key = $this->service->generate(
                length: 32,
                includeSpecialCharacters: false,
                excludedCharacters: '0Ol1I'
            );
            $this->assertSame(0, preg_match('/[0Ol1I]/', $key), 'Excluded char appeared: '.$key);
        }
    }//end testCharacterExclusion()

    /**
     * Exclusion works alongside special characters.
     *
     * @return void
     */
    public function testExclusionWithSpecialCharacters(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $key = $this->service->generate(
                length: 32,
                includeSpecialCharacters: true,
                excludedCharacters: '{}[]'
            );
            $this->assertSame(0, preg_match('/[{}\[\]]/', $key), 'Excluded special appeared: '.$key);
        }
    }//end testExclusionWithSpecialCharacters()

    /**
     * Length below the minimum is rejected.
     *
     * @return void
     */
    public function testLengthTooShortRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(length: 6);
    }//end testLengthTooShortRejected()

    /**
     * A character set reduced below 2 distinct characters is rejected.
     *
     * @return void
     */
    public function testCharsetTooSmallRejected(): void
    {
        // Exclude all but one character from the alphanumeric set.
        $allButOne = 'BCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(
            length: 16,
            includeSpecialCharacters: false,
            excludedCharacters: $allButOne
        );
    }//end testCharsetTooSmallRejected()

    /**
     * A fully exhausted character set is rejected.
     *
     * @return void
     */
    public function testCharsetEmptyRejected(): void
    {
        $all = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(
            length: 16,
            includeSpecialCharacters: false,
            excludedCharacters: $all
        );
    }//end testCharsetEmptyRejected()

    /**
     * Exactly two characters remaining is valid.
     *
     * @return void
     */
    public function testTwoCharactersValid(): void
    {
        // Leave only 'A' and 'B'.
        $exclude = 'CDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $key     = $this->service->generate(
            length: 16,
            includeSpecialCharacters: false,
            excludedCharacters: $exclude
        );
        $this->assertSame(16, strlen($key));
        $this->assertMatchesRegularExpression('/^[AB]{16}$/', $key);
    }//end testTwoCharactersValid()

    /**
     * Regex with exact length generates a matching key.
     *
     * @return void
     */
    public function testRegexExactLength(): void
    {
        $regex = '^[a-zA-Z0-9!@#]{16}$';
        $key   = $this->service->generate(regex: $regex);
        $this->assertSame(16, strlen($key));
        $this->assertSame(1, preg_match('/'.$regex.'/', $key));
    }//end testRegexExactLength()

    /**
     * Regex with a length range generates a key in-range.
     *
     * @return void
     */
    public function testRegexLengthRange(): void
    {
        $regex = '^[a-zA-Z0-9]{8,16}$';
        $key   = $this->service->generate(regex: $regex);
        $this->assertGreaterThanOrEqual(8, strlen($key));
        $this->assertLessThanOrEqual(16, strlen($key));
        $this->assertSame(1, preg_match('/'.$regex.'/', $key));
    }//end testRegexLengthRange()

    /**
     * Negated character class excludes the forbidden characters.
     *
     * @return void
     */
    public function testRegexNegatedClass(): void
    {
        $regex = '^[^\s<>]{16}$';
        for ($i = 0; $i < 20; $i++) {
            $key = $this->service->generate(regex: $regex);
            $this->assertSame(16, strlen($key));
            $this->assertSame(0, preg_match('/[\s<>]/', $key), 'Forbidden char appeared: '.$key);
        }
    }//end testRegexNegatedClass()

    /**
     * Regex overrides the other configuration fields.
     *
     * @return void
     */
    public function testRegexOverridesOtherFields(): void
    {
        $key = $this->service->generate(
            length: 10,
            includeSpecialCharacters: true,
            excludedCharacters: 'abc',
            regex: '^[a-z]{20}$'
        );
        $this->assertSame(20, strlen($key));
        $this->assertMatchesRegularExpression('/^[a-z]{20}$/', $key);
    }//end testRegexOverridesOtherFields()

    /**
     * Regex with no length quantifier is rejected.
     *
     * @return void
     */
    public function testRegexMissingQuantifierRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(regex: '^[a-zA-Z0-9]$');
    }//end testRegexMissingQuantifierRejected()

    /**
     * Syntactically invalid regex is rejected.
     *
     * @return void
     */
    public function testRegexInvalidSyntaxRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(regex: '^[a-z');
    }//end testRegexInvalidSyntaxRejected()

    /**
     * Regex with a quantifier length below the minimum is rejected.
     *
     * @return void
     */
    public function testRegexLengthTooShortRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(regex: '^[a-zA-Z]{5}$');
    }//end testRegexLengthTooShortRejected()

    /**
     * Regex resolving to fewer than 2 distinct characters is rejected.
     *
     * @return void
     */
    public function testRegexCharsetTooSmallRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generate(regex: '^[a]{16}$');
    }//end testRegexCharsetTooSmallRejected()

    /**
     * The service relies solely on random_int() — verify statistical spread,
     * which would not hold for a fixed/biased non-CSPRNG source.
     *
     * @return void
     */
    public function testUsesCsprngSpread(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[$this->service->generate(length: 16)] = true;
        }

        // 200 16-char keys must be effectively all unique under a CSPRNG.
        $this->assertGreaterThan(190, count($seen));
    }//end testUsesCsprngSpread()

    /**
     * The service source must not call non-CSPRNG randomness functions.
     *
     * @return void
     */
    public function testSourceUsesNoWeakRandomness(): void
    {
        $source = file_get_contents(__DIR__.'/../../../lib/Service/KeyGeneratorService.php');
        $this->assertStringContainsString('random_int(', $source);
        $this->assertSame(0, preg_match('/\bmt_rand\s*\(/', $source));
        $this->assertSame(0, preg_match('/\brand\s*\(/', $source));
        $this->assertSame(0, preg_match('/\barray_rand\s*\(/', $source));
    }//end testSourceUsesNoWeakRandomness()
}//end class
