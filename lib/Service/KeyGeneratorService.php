<?php

/**
 * Doriath Key Generator Service
 *
 * Stateless cryptographically-secure random key/password generation.
 *
 * @category Service
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stateless service that produces cryptographically random strings.
 *
 * All randomness is derived from PHP's CSPRNG via random_int(); no non-CSPRNG
 * randomness source is used anywhere in this service.
 */
class KeyGeneratorService
{
    /**
     * Minimum permitted output length.
     *
     * @var int
     */
    public const MIN_LENGTH = 8;

    /**
     * Maximum permitted output length.
     *
     * @var int
     */
    public const MAX_LENGTH = 128;

    /**
     * Minimum number of distinct characters the resolved set must contain.
     *
     * @var int
     */
    public const MIN_CHARSET_SIZE = 2;

    /**
     * Maximum regex post-condition retries before giving up.
     *
     * @var int
     */
    private const MAX_REGEX_ATTEMPTS = 3;

    /**
     * Uppercase letters of the default character set.
     *
     * @var string
     */
    private const UPPERCASE = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Lowercase letters of the default character set.
     *
     * @var string
     */
    private const LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';

    /**
     * Digits of the default character set.
     *
     * @var string
     */
    private const DIGITS = '0123456789';

    /**
     * OWASP recommended special-character set.
     *
     * @var string
     */
    private const SPECIAL = '!@#$%^&*()-_=+[]{}|;:,.<>?/';

    /**
     * Constructor.
     *
     * @param KeyGeneratorRegexParser $regexParser The regex parser
     *
     * @return void
     */
    public function __construct(
        private KeyGeneratorRegexParser $regexParser=new KeyGeneratorRegexParser(),
    ) {
    }//end __construct()

    /**
     * Generate a random string from the supplied configuration.
     *
     * When a non-empty regex is provided it overrides length,
     * includeSpecialCharacters and excludedCharacters. The boolean
     * includeSpecialCharacters flag is part of the public API contract.
     *
     * @param int    $length                   Desired output length (default mode)
     * @param bool   $includeSpecialCharacters Whether to include the OWASP special set
     * @param string $excludedCharacters       Characters to remove from the resolved set
     * @param string $regex                    Optional regex override
     *
     * @return string The generated key
     *
     * @throws InvalidArgumentException When validation fails
     * @throws RuntimeException         When generation cannot satisfy the regex
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     * @SuppressWarnings(PHPMD.LongVariable)
     */
    public function generate(
        int $length=16,
        bool $includeSpecialCharacters=true,
        string $excludedCharacters='',
        string $regex='',
    ): string {
        if ($regex !== '') {
            return $this->generateFromRegex(regex: $regex);
        }

        return $this->generateFromCharset(
            length: $length,
            includeSpecial: $includeSpecialCharacters,
            excludedCharacters: $excludedCharacters
        );
    }//end generate()

    /**
     * Generate a string using the default/explicit character-set mode.
     *
     * @param int    $length             Desired output length
     * @param bool   $includeSpecial     Whether to include the OWASP special set
     * @param string $excludedCharacters Characters to remove from the resolved set
     *
     * @return string The generated key
     *
     * @throws InvalidArgumentException When validation fails
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
     */
    private function generateFromCharset(
        int $length,
        bool $includeSpecial,
        string $excludedCharacters,
    ): string {
        $this->assertLengthInRange(length: $length);

        $charset = self::UPPERCASE.self::LOWERCASE.self::DIGITS;
        if ($includeSpecial === true) {
            $charset .= self::SPECIAL;
        }

        $charset = $this->applyExclusions(charset: $charset, excludedCharacters: $excludedCharacters);
        $this->assertCharsetViable(charset: $charset);

        return $this->buildString(charset: $charset, length: $length);
    }//end generateFromCharset()

    /**
     * Assert that an explicit length is within the permitted range.
     *
     * @param int $length The requested length
     *
     * @return void
     *
     * @throws InvalidArgumentException When the length is out of range
     */
    private function assertLengthInRange(int $length): void
    {
        if ($length < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                message: sprintf('Length must be at least %d characters', self::MIN_LENGTH)
            );
        }

        if ($length > self::MAX_LENGTH) {
            throw new InvalidArgumentException(
                message: sprintf('Length must not exceed %d characters', self::MAX_LENGTH)
            );
        }
    }//end assertLengthInRange()

    /**
     * Remove excluded characters from a character set and return the unique remainder.
     *
     * @param string $charset            The base character set
     * @param string $excludedCharacters Characters to remove
     *
     * @return string The resolved character set (each char unique)
     */
    private function applyExclusions(string $charset, string $excludedCharacters): string
    {
        $excluded = [];
        if ($excludedCharacters !== '') {
            foreach (str_split($excludedCharacters) as $char) {
                $excluded[$char] = true;
            }
        }

        $resolved = '';
        $seen     = [];
        foreach (str_split($charset) as $char) {
            if (isset($excluded[$char]) === true || isset($seen[$char]) === true) {
                continue;
            }

            $seen[$char] = true;
            $resolved   .= $char;
        }

        return $resolved;
    }//end applyExclusions()

    /**
     * Assert that a resolved character set has at least the minimum size.
     *
     * @param string $charset The resolved character set
     *
     * @return void
     *
     * @throws InvalidArgumentException When the set is empty or too small
     */
    private function assertCharsetViable(string $charset): void
    {
        $size = strlen($charset);
        if ($size === 0) {
            throw new InvalidArgumentException(message: 'The character set is empty after exclusions');
        }

        if ($size < self::MIN_CHARSET_SIZE) {
            throw new InvalidArgumentException(
                message: sprintf(
                    'The character set must contain at least %d distinct characters',
                    self::MIN_CHARSET_SIZE
                )
            );
        }
    }//end assertCharsetViable()

    /**
     * Build a random string of the given length from a character set using the CSPRNG.
     *
     * @param string $charset The resolved character set
     * @param int    $length  The output length
     *
     * @return string The generated string
     */
    private function buildString(string $charset, int $length): string
    {
        $max    = (strlen($charset) - 1);
        $result = '';
        for ($index = 0; $index < $length; $index++) {
            $result .= $charset[random_int(min: 0, max: $max)];
        }

        return $result;
    }//end buildString()

    /**
     * Generate a string from a regex override.
     *
     * @param string $regex The regex pattern
     *
     * @return string The generated key
     *
     * @throws InvalidArgumentException When the regex is invalid for generation
     * @throws RuntimeException         When generation cannot satisfy the regex
     */
    private function generateFromRegex(string $regex): string
    {
        $delimited = $this->regexParser->delimit(pattern: $regex);
        $this->regexParser->assertValid(delimited: $delimited);

        [$minLength, $maxLength] = $this->regexParser->extractLength(regex: $regex);
        $charset = $this->regexParser->extractCharset(regex: $regex);

        if ($minLength < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                message: sprintf('The regex length must be at least %d characters', self::MIN_LENGTH)
            );
        }

        $this->assertCharsetViable(charset: $charset);

        // Generate a length within the quantifier range, then validate against
        // the original regex as a post-condition (catches parser mismatches).
        for ($attempt = 0; $attempt < self::MAX_REGEX_ATTEMPTS; $attempt++) {
            $length    = random_int(min: $minLength, max: $maxLength);
            $candidate = $this->buildString(charset: $charset, length: $length);
            if (preg_match($delimited, $candidate) === 1) {
                return $candidate;
            }
        }

        throw new RuntimeException(message: 'Unable to generate a value matching the supplied regex');
    }//end generateFromRegex()
}//end class
