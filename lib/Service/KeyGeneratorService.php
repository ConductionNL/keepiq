<?php

/**
 * Keepiq Key Generator Service
 *
 * Stateless cryptographically-secure random key/password generation.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use InvalidArgumentException;
use RuntimeException;

/**
 * Stateless service that produces cryptographically random strings.
 *
 * All randomness is derived from PHP's CSPRNG via random_int(); no non-CSPRNG
 * randomness source is used anywhere in this service.
 */
class KeyGeneratorService {
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
	 * @param \OCP\IAppConfig|null $appConfig The app config (org policy clamp)
	 *
	 * @return void
	 */
	public function __construct(
		private KeyGeneratorRegexParser $regexParser = new KeyGeneratorRegexParser(),
		private ?\OCP\IAppConfig $appConfig = null,
	) {
	}//end __construct()

	/**
	 * The effective org generator policy (org-password-policies §2.1) —
	 * disabled (null) when the policy gate is off or no config is wired.
	 *
	 * @return array{minLength:int, requireUpper:bool, requireLower:bool, requireDigit:bool, requireSymbol:bool}|null
	 */
	private function policy(): ?array {
		if ($this->appConfig === null
			|| $this->appConfig->getValueBool('keepiq', 'policy_enabled', false) === false
		) {
			return null;
		}

		return [
			'minLength' => max(8, $this->appConfig->getValueInt('keepiq', 'generator_min_length', 12)),
			'requireUpper' => $this->appConfig->getValueBool('keepiq', 'generator_require_upper', false),
			'requireLower' => $this->appConfig->getValueBool('keepiq', 'generator_require_lower', false),
			'requireDigit' => $this->appConfig->getValueBool('keepiq', 'generator_require_digit', false),
			'requireSymbol' => $this->appConfig->getValueBool('keepiq', 'generator_require_symbol', false),
		];
	}//end policy()

	/**
	 * The character classes a policy requires, keyed by label.
	 *
	 * @param array<string,mixed> $policy The effective policy
	 *
	 * @return array<string,string> label => class character set
	 */
	private function requiredClasses(array $policy): array {
		$classes = [];
		if ($policy['requireUpper'] === true) {
			$classes['uppercase'] = self::UPPERCASE;
		}

		if ($policy['requireLower'] === true) {
			$classes['lowercase'] = self::LOWERCASE;
		}

		if ($policy['requireDigit'] === true) {
			$classes['digit'] = self::DIGITS;
		}

		if ($policy['requireSymbol'] === true) {
			$classes['symbol'] = self::SPECIAL;
		}

		return $classes;
	}//end requiredClasses()

	/**
	 * Generate a random string from the supplied configuration.
	 *
	 * When a non-empty regex is provided it overrides length,
	 * includeSpecialCharacters and excludedCharacters. The boolean
	 * includeSpecialCharacters flag is part of the public API contract.
	 *
	 * @param int $length Desired output length (default mode)
	 * @param bool $includeSpecialCharacters Whether to include the OWASP special set
	 * @param string $excludedCharacters Characters to remove from the resolved set
	 * @param string $regex Optional regex override
	 *
	 * @return string The generated key
	 *
	 * @throws InvalidArgumentException When validation fails
	 * @throws RuntimeException When generation cannot satisfy the regex
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $includeSpecialCharacters mirrors the
	 *   optional request field of KeyGeneratorController::generate() one-for-one; this
	 *   method is the service half of that HTTP contract, so it carries the same
	 *   defaulted parameter. It is forwarded, never branched on, to generateFromCharset().
	 * @SuppressWarnings(PHPMD.LongVariable)        Same reason: the name is the wire field name
	 *   posted by src/dialogs/KeyGeneratorModal.vue and is called out above as part of
	 *   the public API contract, so it is not free to shorten.
	 */
	public function generate(
		int $length = 16,
		bool $includeSpecialCharacters = true,
		string $excludedCharacters = '',
		string $regex = '',
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
	 * @param int $length Desired output length
	 * @param bool $includeSpecial Whether to include the OWASP special set
	 * @param string $excludedCharacters Characters to remove from the resolved set
	 *
	 * @return string The generated key
	 *
	 * @throws InvalidArgumentException When validation fails
	 */
	private function generateFromCharset(
		int $length,
		bool $includeSpecial,
		string $excludedCharacters,
	): string {
		// Org policy clamp (org-password-policies §2.1): the server is
		// authoritative — length is raised to the floor and required
		// classes are forced into the resolved set AND the output.
		$policy = $this->policy();
		if ($policy !== null) {
			$length = max($length, $policy['minLength']);
			if ($policy['requireSymbol'] === true) {
				$includeSpecial = true;
			}
		}

		$this->assertLengthInRange(length: $length);

		$charset = self::UPPERCASE . self::LOWERCASE . self::DIGITS;
		if ($includeSpecial === true) {
			$charset .= self::SPECIAL;
		}

		$charset = $this->applyExclusions(charset: $charset, excludedCharacters: $excludedCharacters);

		if ($policy !== null) {
			// An exclusion list may not hollow out a required class: any
			// class emptied by exclusions is restored wholesale.
			foreach ($this->requiredClasses(policy: $policy) as $classSet) {
				if ($this->intersects(charset: $charset, classSet: $classSet) === false) {
					$charset .= $classSet;
				}
			}
		}

		$this->assertCharsetViable(charset: $charset);

		$result = $this->buildString(charset: $charset, length: $length);
		if ($policy !== null) {
			$result = $this->forceRequiredClasses(result: $result, policy: $policy, charset: $charset);
		}

		return $result;
	}//end generateFromCharset()

	/**
	 * Whether a charset contains at least one character of a class.
	 *
	 * @param string $charset The resolved character set
	 * @param string $classSet The class character set
	 *
	 * @return bool
	 */
	private function intersects(string $charset, string $classSet): bool {
		return strpbrk($charset, $classSet) !== false;
	}//end intersects()

	/**
	 * Guarantee the output contains at least one character of every
	 * required class by replacing random positions (CSPRNG) with random
	 * members of any missing class.
	 *
	 * @param string $result The generated string
	 * @param array<string,mixed> $policy The effective policy
	 * @param string $charset The resolved character set
	 *
	 * @return string
	 */
	private function forceRequiredClasses(string $result, array $policy, string $charset): string {
		$usedPositions = [];
		foreach ($this->requiredClasses(policy: $policy) as $classSet) {
			if (strpbrk($result, $classSet) !== false) {
				continue;
			}

			$allowed = array_values(array_intersect(str_split($classSet), str_split($charset)));
			if ($allowed === []) {
				continue;
			}

			do {
				$position = random_int(min: 0, max: (strlen($result) - 1));
			} while (isset($usedPositions[$position]) === true);

			$usedPositions[$position] = true;
			$result[$position] = $allowed[random_int(min: 0, max: (count($allowed) - 1))];
		}

		return $result;
	}//end forceRequiredClasses()

	/**
	 * Assert that an explicit length is within the permitted range.
	 *
	 * @param int $length The requested length
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the length is out of range
	 */
	private function assertLengthInRange(int $length): void {
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
	 * @param string $charset The base character set
	 * @param string $excludedCharacters Characters to remove
	 *
	 * @return string The resolved character set (each char unique)
	 */
	private function applyExclusions(string $charset, string $excludedCharacters): string {
		$excluded = [];
		if ($excludedCharacters !== '') {
			foreach (str_split($excludedCharacters) as $char) {
				$excluded[$char] = true;
			}
		}

		$resolved = '';
		$seen = [];
		foreach (str_split($charset) as $char) {
			if (isset($excluded[$char]) === true || isset($seen[$char]) === true) {
				continue;
			}

			$seen[$char] = true;
			$resolved .= $char;
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
	private function assertCharsetViable(string $charset): void {
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
	 * @param int $length The output length
	 *
	 * @return string The generated string
	 */
	private function buildString(string $charset, int $length): string {
		$max = (strlen($charset) - 1);
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
	 * @throws RuntimeException When generation cannot satisfy the regex
	 */
	private function generateFromRegex(string $regex): string {
		$delimited = $this->regexParser->delimit(pattern: $regex);
		$this->regexParser->assertValid(delimited: $delimited);

		[$minLength, $maxLength] = $this->regexParser->extractLength(regex: $regex);
		$charset = $this->regexParser->extractCharset(regex: $regex);

		if ($minLength < self::MIN_LENGTH) {
			throw new InvalidArgumentException(
				message: sprintf('The regex length must be at least %d characters', self::MIN_LENGTH)
			);
		}

		// Org policy gate on regex overrides (org-password-policies §2.2):
		// a provable maximum below the floor, or a charset that excludes a
		// required class, is rejected — never silently weakened.
		$policy = $this->policy();
		if ($policy !== null) {
			if ($maxLength < $policy['minLength']) {
				throw new InvalidArgumentException(
					message: sprintf(
						'The regex cannot reach the org policy minimum length of %d characters',
						$policy['minLength']
					)
				);
			}

			foreach ($this->requiredClasses(policy: $policy) as $label => $classSet) {
				if ($this->intersects(charset: $charset, classSet: $classSet) === false) {
					throw new InvalidArgumentException(
						message: sprintf('The regex excludes the %s characters the org policy requires', $label)
					);
				}
			}

			// Raise the generated length window to the floor where the
			// quantifier allows it.
			$minLength = max($minLength, min($policy['minLength'], $maxLength));
		}//end if

		$this->assertCharsetViable(charset: $charset);

		// Generate a length within the quantifier range, then validate against
		// the original regex as a post-condition (catches parser mismatches).
		for ($attempt = 0; $attempt < self::MAX_REGEX_ATTEMPTS; $attempt++) {
			$length = random_int(min: $minLength, max: $maxLength);
			$candidate = $this->buildString(charset: $charset, length: $length);
			if (preg_match($delimited, $candidate) === 1) {
				return $candidate;
			}
		}

		throw new RuntimeException(message: 'Unable to generate a value matching the supplied regex');
	}//end generateFromRegex()
}//end class
