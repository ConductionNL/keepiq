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
 * All randomness is derived from PHP's CSPRNG via random_int(); no rand(),
 * mt_rand() or array_rand() is used anywhere in this service.
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
     * Generate a random string from the supplied configuration.
     *
     * When a non-empty regex is provided it overrides length,
     * includeSpecialCharacters and excludedCharacters.
     *
     * @param int    $length                   Desired output length (default mode)
     * @param bool   $includeSpecialCharacters Whether to include the OWASP special set
     * @param string $excludedCharacters       Characters to remove from the resolved set
     * @param string $regex                     Optional regex override
     *
     * @return string The generated key
     *
     * @throws InvalidArgumentException When validation fails
     * @throws RuntimeException         When generation cannot satisfy the regex
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
            includeSpecialCharacters: $includeSpecialCharacters,
            excludedCharacters: $excludedCharacters
        );
    }//end generate()

    /**
     * Generate a string using the default/explicit character-set mode.
     *
     * @param int    $length                   Desired output length
     * @param bool   $includeSpecialCharacters Whether to include the OWASP special set
     * @param string $excludedCharacters       Characters to remove from the resolved set
     *
     * @return string The generated key
     *
     * @throws InvalidArgumentException When validation fails
     */
    private function generateFromCharset(
        int $length,
        bool $includeSpecialCharacters,
        string $excludedCharacters,
    ): string {
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

        $charset = self::UPPERCASE.self::LOWERCASE.self::DIGITS;
        if ($includeSpecialCharacters === true) {
            $charset .= self::SPECIAL;
        }

        $charset = $this->applyExclusions(charset: $charset, excludedCharacters: $excludedCharacters);
        $this->assertCharsetViable(charset: $charset);

        return $this->buildString(charset: $charset, length: $length);
    }//end generateFromCharset()

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
        $excludedLength = strlen($excludedCharacters);
        for ($i = 0; $i < $excludedLength; $i++) {
            $excluded[$excludedCharacters[$i]] = true;
        }

        $resolved = '';
        $seen     = [];
        $charsetLength = strlen($charset);
        for ($i = 0; $i < $charsetLength; $i++) {
            $char = $charset[$i];
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
        for ($i = 0; $i < $length; $i++) {
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
        if (@preg_match($regex, '') === false) {
            throw new InvalidArgumentException(message: 'The regex pattern is syntactically invalid');
        }

        [$minLength, $maxLength] = $this->extractLength(regex: $regex);
        $charset                 = $this->extractCharset(regex: $regex);

        if ($minLength < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                message: sprintf('The regex length must be at least %d characters', self::MIN_LENGTH)
            );
        }

        $this->assertCharsetViable(charset: $charset);

        // Generate a length within the quantifier range, then validate against
        // the original regex as a post-condition (catches parser mismatches).
        $attempts = 0;
        do {
            $length    = random_int(min: $minLength, max: $maxLength);
            $candidate = $this->buildString(charset: $charset, length: $length);
            if (preg_match($regex, $candidate) === 1) {
                return $candidate;
            }

            $attempts++;
        } while ($attempts < 3);

        throw new RuntimeException(message: 'Unable to generate a value matching the supplied regex');
    }//end generateFromRegex()

    /**
     * Extract the length quantifier ({n} or {n,m}) from a regex.
     *
     * @param string $regex The regex pattern
     *
     * @return array{0:int,1:int} The [minLength, maxLength] pair
     *
     * @throws InvalidArgumentException When no quantifier is present
     */
    private function extractLength(string $regex): array
    {
        if (preg_match('/\{(\d+)(?:,(\d+))?\}/', $regex, $matches) !== 1) {
            throw new InvalidArgumentException(
                message: 'The regex must contain a length quantifier (e.g. {16} or {8,16})'
            );
        }

        $min = (int) $matches[1];
        $max = $min;
        if (isset($matches[2]) === true && $matches[2] !== '') {
            $max = (int) $matches[2];
        }

        if ($max < $min) {
            throw new InvalidArgumentException(message: 'The regex length range is invalid (max < min)');
        }

        return [$min, $max];
    }//end extractLength()

    /**
     * Extract the resolvable character set from a regex character class.
     *
     * Supports a single character class. Negated classes ([^...]) are resolved
     * as the complement against printable ASCII (0x21-0x7E).
     *
     * @param string $regex The regex pattern
     *
     * @return string The resolved character set
     *
     * @throws InvalidArgumentException When no character class can be determined
     */
    private function extractCharset(string $regex): string
    {
        if (preg_match('/\[(\^?)((?:\\\\.|[^\]\\\\])*)\]/', $regex, $matches) !== 1) {
            throw new InvalidArgumentException(
                message: 'The regex must contain a character class (e.g. [a-zA-Z0-9])'
            );
        }

        $negated   = ($matches[1] === '^');
        $classBody = $matches[2];

        $allowed = $this->expandCharacterClass(classBody: $classBody);

        if ($negated === true) {
            $allowed = $this->complementAscii(disallowed: $allowed);
        }

        // De-duplicate while preserving order.
        $resolved = '';
        $seen     = [];
        foreach ($allowed as $char) {
            if (isset($seen[$char]) === true) {
                continue;
            }

            $seen[$char] = true;
            $resolved   .= $char;
        }

        return $resolved;
    }//end extractCharset()

    /**
     * Expand a character-class body into a flat list of characters.
     *
     * Handles ranges (a-z), common escapes (\s \d \w) and literal escapes (\.).
     *
     * @param string $classBody The inside of the character class
     *
     * @return array<int,string> The expanded characters
     */
    private function expandCharacterClass(string $classBody): array
    {
        $chars  = [];
        $length = strlen($classBody);
        $i      = 0;

        while ($i < $length) {
            $char = $classBody[$i];

            // Escape sequences.
            if ($char === '\\' && ($i + 1) < $length) {
                $next = $classBody[($i + 1)];
                $i   += 2;
                $chars = array_merge($chars, $this->expandEscape(escape: $next));
                continue;
            }

            // Range (a-z): a literal, a dash, a literal — neither end escaped.
            if (($i + 2) < $length && $classBody[($i + 1)] === '-' && $classBody[($i + 2)] !== ']') {
                $start = ord($char);
                $end   = ord($classBody[($i + 2)]);
                if ($end >= $start) {
                    for ($code = $start; $code <= $end; $code++) {
                        $chars[] = chr($code);
                    }

                    $i += 3;
                    continue;
                }
            }

            $chars[] = $char;
            $i++;
        }

        return $chars;
    }//end expandCharacterClass()

    /**
     * Expand a single-character escape inside a character class.
     *
     * @param string $escape The escaped character (the part after the backslash)
     *
     * @return array<int,string> The characters the escape represents
     */
    private function expandEscape(string $escape): array
    {
        switch ($escape) {
            case 'd':
                return str_split(self::DIGITS);
            case 'w':
                return str_split(self::UPPERCASE.self::LOWERCASE.self::DIGITS.'_');
            case 's':
                // Whitespace is not a useful generation set; represent as a space.
                return [' '];
            default:
                // Literal escaped character (e.g. \. \\ \] ).
                return [$escape];
        }
    }//end expandEscape()

    /**
     * Compute the complement of a disallowed set against printable ASCII (0x21-0x7E).
     *
     * @param array<int,string> $disallowed The disallowed characters
     *
     * @return array<int,string> The allowed characters
     */
    private function complementAscii(array $disallowed): array
    {
        $blocked = [];
        foreach ($disallowed as $char) {
            $blocked[$char] = true;
        }

        // \s in a negated class blocks all whitespace, not just space.
        if (isset($blocked[' ']) === true) {
            $blocked["\t"] = true;
            $blocked["\n"] = true;
            $blocked["\r"] = true;
        }

        $allowed = [];
        for ($code = 0x21; $code <= 0x7E; $code++) {
            $char = chr($code);
            if (isset($blocked[$char]) === true) {
                continue;
            }

            $allowed[] = $char;
        }

        return $allowed;
    }//end complementAscii()
}//end class
