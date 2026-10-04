// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.generator

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.crypto.SecureRandomBytes

/** Why the generator refused a request. [message] is the web generator's English text, for logs and tests. */
enum class GeneratorErrorCode {
    LENGTH_TOO_SHORT, LENGTH_TOO_LONG, CHARSET_EMPTY, CHARSET_TOO_SMALL, NO_KIND_CHOSEN,
    REGEX_INVALID, REGEX_NO_QUANTIFIER, REGEX_RANGE_INVALID, REGEX_NO_CLASS, REGEX_TOO_SHORT,
    REGEX_BELOW_POLICY, REGEX_EXCLUDES_REQUIRED, REGEX_NO_MATCH, PASSPHRASE_WORDS, PASSPHRASE_OFF,
}

/** A request the generator refuses (GeneratorError in src/generator/generator.js). */
class GeneratorException(val code: GeneratorErrorCode, message: String, val argument: String? = null) : Exception(message)

/** The generator-relevant part of the organisation's policy (generatorPolicy). */
data class GeneratorPolicy(
    val minLength: Int,
    val requireUpper: Boolean,
    val requireLower: Boolean,
    val requireDigit: Boolean,
    val requireSymbol: Boolean,
    val allowPassphrase: Boolean,
) {
    companion object {
        /** From `GET /api/settings/policy`; null when no policy applies. */
        fun from(raw: JsonObject?): GeneratorPolicy? {
            if (raw == null || !raw.isTrue("policy_enabled")) return null
            val floorRaw = raw["generator_min_length"]
            val floor = if (floorRaw == null || floorRaw is kotlinx.serialization.json.JsonNull) 12 else parseIntLikeJs((floorRaw as? JsonPrimitive)?.contentOrNull)
            return GeneratorPolicy(
                minLength = maxOf(Generator.MIN_LENGTH, floor ?: 12),
                requireUpper = raw.isTrue("generator_require_upper"),
                requireLower = raw.isTrue("generator_require_lower"),
                requireDigit = raw.isTrue("generator_require_digit"),
                requireSymbol = raw.isTrue("generator_require_symbol"),
                allowPassphrase = !raw.isFalse("generator_allow_passphrase"),
            )
        }

        /** Reads the policy from the server; null when none applies or it cannot be read (it never blocks). */
        suspend fun fetch(api: nl.conduction.keepiq.shared.api.KeepiqApi): GeneratorPolicy? = from(api.fetchPolicy())

        private fun JsonObject.isTrue(name: String): Boolean = (this[name] as? JsonPrimitive)?.takeIf { !it.isString }?.booleanOrNull == true

        private fun JsonObject.isFalse(name: String): Boolean = (this[name] as? JsonPrimitive)?.takeIf { !it.isString }?.booleanOrNull == false

        /** `Number.parseInt(value, 10)`: leading whitespace, a sign, then digits; null for NaN. */
        internal fun parseIntLikeJs(value: String?): Int? {
            val s = value?.trimStart() ?: return null
            var i = 0
            var sign = 1
            if (i < s.length && (s[i] == '+' || s[i] == '-')) {
                if (s[i] == '-') sign = -1
                i++
            }
            val start = i
            while (i < s.length && s[i] in '0'..'9') i++
            if (i == start) return null
            return s.substring(start, i).take(9).toInt() * sign
        }
    }
}

/** Password options (generateKey). Defaults are the web generator's defaults for a missing option. */
data class PasswordOptions(
    val length: Int = 16,
    val includeUppercase: Boolean = true,
    val includeLowercase: Boolean = true,
    val includeDigits: Boolean = true,
    val includeSpecialCharacters: Boolean = true,
    val minDigits: Int = 0,
    val minSpecial: Int = 0,
    val excludedCharacters: String = "",
    val avoidAmbiguous: Boolean = false,
    val regex: String? = null,
)

/** Passphrase options (generatePassphrase). */
data class PassphraseOptions(
    val words: Int = Generator.DEFAULT_WORDS,
    val separator: String = "-",
    val capitalise: Boolean = false,
    val includeNumber: Boolean = false,
)

/**
 * The key and passphrase generator, a port of src/generator/generator.js
 * with the same options, policy clamp, refusals and order of random draws.
 * tests/vectors/generator/cases.json holds outputs of the web module for
 * fixed random sources; the commonTest suite and a vitest test check both
 * implementations against them.
 *
 * [rand] draws a uniform integer in [min, max]; the default is the platform's
 * cryptographic random source with rejection sampling, as randomInt does.
 */
object Generator {
    const val MIN_LENGTH = 8
    const val MAX_LENGTH = 128
    const val MIN_CHARSET_SIZE = 2
    private const val MAX_REGEX_ATTEMPTS = 3
    const val MIN_WORDS = 4
    const val MAX_WORDS = 12
    const val DEFAULT_WORDS = 5

    const val UPPERCASE = "ABCDEFGHIJKLMNOPQRSTUVWXYZ"
    const val LOWERCASE = "abcdefghijklmnopqrstuvwxyz"
    const val DIGITS = "0123456789"
    const val SPECIAL = "!@#\$%^&*()-_=+[]{}|;:,.<>?/"
    private const val AMBIGUOUS = "IOl01"

    /** randomInt: rejection sampling over 32-bit values, so no value is favoured. */
    val secureRandomInt: (Int, Int) -> Int = { min, max ->
        val range = max.toLong() - min + 1
        if (range <= 1) {
            min
        } else {
            val limit = (0x100000000L / range) * range
            var value: Long
            do {
                val b = SecureRandomBytes.next(4)
                value = ((b[0].toLong() and 0xFF) shl 24) or ((b[1].toLong() and 0xFF) shl 16) or
                    ((b[2].toLong() and 0xFF) shl 8) or (b[3].toLong() and 0xFF)
            } while (value >= limit)
            (min + value % range).toInt()
        }
    }

    /** generateKey. */
    fun generateKey(options: PasswordOptions = PasswordOptions(), policy: GeneratorPolicy? = null, rand: (Int, Int) -> Int = secureRandomInt): String {
        if (!options.regex.isNullOrEmpty()) return generateFromRegex(options.regex, policy, rand)
        return generateFromCharset(options, policy, rand)
    }

    /** generatePassphrase: EFF large word list, made to meet the policy rather than refused. */
    fun generatePassphrase(options: PassphraseOptions = PassphraseOptions(), policy: GeneratorPolicy? = null, rand: (Int, Int) -> Int = secureRandomInt): String {
        if (policy != null && !policy.allowPassphrase) {
            throw GeneratorException(GeneratorErrorCode.PASSPHRASE_OFF, "Your organisation has switched passphrases off")
        }
        val count = options.words
        if (count < MIN_WORDS || count > MAX_WORDS) {
            throw GeneratorException(GeneratorErrorCode.PASSPHRASE_WORDS, "A passphrase must have $MIN_WORDS to $MAX_WORDS words")
        }
        var separator = options.separator
        var capitalise = options.capitalise
        var includeNumber = options.includeNumber
        if (policy != null) {
            capitalise = capitalise || policy.requireUpper
            includeNumber = includeNumber || policy.requireDigit
            if (policy.requireSymbol && !intersects(separator, SPECIAL)) {
                separator = SPECIAL[rand(0, SPECIAL.length - 1)].toString()
            }
        }
        val list = EffWordlist.words
        fun draw(): String {
            val word = list[rand(0, list.size - 1)]
            return if (capitalise) word[0].uppercase() + word.substring(1) else word
        }
        val words = MutableList(count) { draw() }
        if (includeNumber) {
            val index = rand(0, words.size - 1)
            words[index] = words[index] + rand(0, 9).toString()
        }
        if (policy != null) {
            while (words.joinToString(separator).length < policy.minLength) words += draw()
        }
        return words.joinToString(separator)
    }

    private fun requiredClasses(policy: GeneratorPolicy): List<Pair<String, String>> = buildList {
        if (policy.requireUpper) add("uppercase" to UPPERCASE)
        if (policy.requireLower) add("lowercase" to LOWERCASE)
        if (policy.requireDigit) add("digit" to DIGITS)
        if (policy.requireSymbol) add("symbol" to SPECIAL)
    }

    private fun intersects(a: String, b: String): Boolean = b.any { a.contains(it) }

    private fun dedupe(chars: Iterable<Char>): String = LinkedHashSet<Char>().apply { addAll(chars) }.joinToString("")

    private fun assertLengthInRange(length: Int) {
        if (length < MIN_LENGTH) throw GeneratorException(GeneratorErrorCode.LENGTH_TOO_SHORT, "Length must be at least $MIN_LENGTH characters")
        if (length > MAX_LENGTH) throw GeneratorException(GeneratorErrorCode.LENGTH_TOO_LONG, "Length must not exceed $MAX_LENGTH characters")
    }

    private fun assertCharsetViable(charset: String) {
        if (charset.isEmpty()) throw GeneratorException(GeneratorErrorCode.CHARSET_EMPTY, "The character set is empty after exclusions")
        if (charset.length < MIN_CHARSET_SIZE) {
            throw GeneratorException(
                GeneratorErrorCode.CHARSET_TOO_SMALL,
                "The character set must contain at least $MIN_CHARSET_SIZE distinct characters",
            )
        }
    }

    private fun buildString(charset: String, length: Int, rand: (Int, Int) -> Int): String {
        val sb = StringBuilder(length)
        repeat(length) { sb.append(charset[rand(0, charset.length - 1)]) }
        return sb.toString()
    }

    private fun forceRequiredClasses(result: String, policy: GeneratorPolicy, charset: String, rand: (Int, Int) -> Int): String {
        val chars = result.toCharArray()
        val used = HashSet<Int>()
        for ((_, classSet) in requiredClasses(policy)) {
            if (intersects(result, classSet)) continue
            val allowed = classSet.filter { charset.contains(it) }
            if (allowed.isEmpty()) continue
            var position: Int
            do {
                position = rand(0, chars.size - 1)
            } while (position in used)
            used += position
            chars[position] = allowed[rand(0, allowed.length - 1)]
        }
        return chars.concatToString()
    }

    private fun generateFromCharset(options: PasswordOptions, policy: GeneratorPolicy?, rand: (Int, Int) -> Int): String {
        var length = options.length
        var includeUpper = options.includeUppercase
        var includeLower = options.includeLowercase
        var includeDigits = options.includeDigits
        var includeSpecial = options.includeSpecialCharacters
        var minDigits = maxOf(0, options.minDigits)
        var minSpecial = maxOf(0, options.minSpecial)
        val excluded = options.excludedCharacters.toCharArray().toMutableSet()
        if (options.avoidAmbiguous) excluded.addAll(AMBIGUOUS.toList())

        if (policy != null) {
            length = maxOf(length, policy.minLength)
            includeUpper = includeUpper || policy.requireUpper
            includeLower = includeLower || policy.requireLower
            includeDigits = includeDigits || policy.requireDigit
            includeSpecial = includeSpecial || policy.requireSymbol
        }
        if (!includeUpper && !includeLower && !includeDigits && !includeSpecial) {
            throw GeneratorException(GeneratorErrorCode.NO_KIND_CHOSEN, "Choose at least one kind of character")
        }
        minDigits = if (includeDigits) minDigits else 0
        minSpecial = if (includeSpecial) minSpecial else 0
        length = maxOf(length, minDigits + minSpecial)
        assertLengthInRange(length)

        var charset = (if (includeUpper) UPPERCASE else "") + (if (includeLower) LOWERCASE else "") +
            (if (includeDigits) DIGITS else "") + (if (includeSpecial) SPECIAL else "")
        charset = dedupe(charset.filter { c -> c !in excluded }.toList())
        if (policy != null) {
            for ((_, classSet) in requiredClasses(policy)) {
                if (!intersects(charset, classSet)) charset += classSet
            }
        }
        assertCharsetViable(charset)

        var result = buildString(charset, length, rand)
        result = ensureMinimums(
            result,
            charset,
            listOf(
                UPPERCASE to (if (includeUpper) 1 else 0),
                LOWERCASE to (if (includeLower) 1 else 0),
                DIGITS to (if (includeDigits) maxOf(minDigits, 1) else 0),
                SPECIAL to (if (includeSpecial) maxOf(minSpecial, 1) else 0),
            ),
            rand,
        )
        return if (policy != null) forceRequiredClasses(result, policy, charset, rand) else result
    }

    private fun ensureMinimums(value: String, charset: String, minimums: List<Pair<String, Int>>, rand: (Int, Int) -> Int): String {
        val chars = value.toCharArray()
        val reserved = HashSet<Int>()
        for ((classSet, count) in minimums) {
            val allowed = classSet.filter { charset.contains(it) }
            if (count == 0 || allowed.isEmpty()) continue
            val have = chars.indices.filter { classSet.contains(chars[it]) }
            have.take(count).forEach { reserved += it }
            var missing = count - minOf(have.size, count)
            while (missing > 0) {
                val free = chars.indices.filter { it !in reserved }
                if (free.isEmpty()) break
                val position = free[rand(0, free.size - 1)]
                chars[position] = allowed[rand(0, allowed.length - 1)]
                reserved += position
                missing--
            }
        }
        return chars.concatToString()
    }

    /** compilePattern: PHP-style delimiters (`/…/i`, `#…#`, `~…~`) are accepted. */
    internal fun compilePattern(pattern: String): Regex {
        var body = pattern
        var flags = ""
        val first = pattern.firstOrNull()
        if (first != null && first in "/#~" && pattern.length >= 2) {
            val end = pattern.lastIndexOf(first)
            val tail = pattern.substring(end + 1)
            if (end > 0 && tail.all { it in 'a'..'z' || it in 'A'..'Z' }) {
                body = pattern.substring(1, end)
                flags = tail.filter { it in "imsu" }
            }
        }
        val options = buildSet {
            if ('i' in flags) add(RegexOption.IGNORE_CASE)
            if ('m' in flags) add(RegexOption.MULTILINE)
        }
        return try {
            Regex(body, options)
        } catch (e: Exception) {
            throw GeneratorException(GeneratorErrorCode.REGEX_INVALID, "The regex pattern is syntactically invalid")
        }
    }

    /** extractLength: the window of the first `{n}` or `{n,m}`. */
    internal fun extractLength(regex: String): Pair<Int, Int> {
        val match = Regex("""\{(\d+)(?:,(\d+))?\}""").find(regex)
            ?: throw GeneratorException(GeneratorErrorCode.REGEX_NO_QUANTIFIER, "The regex must contain a length quantifier (e.g. {16} or {8,16})")
        val min = match.groupValues[1].take(9).toInt()
        val max = match.groups[2]?.value?.take(9)?.toInt() ?: min
        if (max < min) throw GeneratorException(GeneratorErrorCode.REGEX_RANGE_INVALID, "The regex length range is invalid (max < min)")
        return min to max
    }

    private fun expandEscape(escape: Char): List<Char> = when (escape) {
        'd' -> DIGITS.toList()
        'w' -> (UPPERCASE + LOWERCASE + DIGITS + "_").toList()
        's' -> listOf(' ')
        else -> listOf(escape)
    }

    private fun expandCharacterClass(body: String): List<Char> {
        val chars = ArrayList<Char>()
        var i = 0
        while (i < body.length) {
            val c = body[i]
            if (c == '\\' && i + 1 < body.length) {
                chars += expandEscape(body[i + 1])
                i += 2
                continue
            }
            if (i + 2 < body.length && body[i + 1] == '-' && body[i + 2] != ']') {
                val start = body[i].code
                val end = body[i + 2].code
                if (end >= start) {
                    for (code in start..end) chars += code.toChar()
                    i += 3
                    continue
                }
            }
            chars += c
            i += 1
        }
        return chars
    }

    private fun complementAscii(disallowed: List<Char>): List<Char> {
        val blocked = disallowed.toSet()
        return (0x21..0x7e).map { it.toChar() }.filter { it !in blocked }
    }

    /** extractCharset: the set of the first character class. */
    internal fun extractCharset(regex: String): String {
        val match = Regex("""\[(\^?)((?:\\.|[^\]\\])*)\]""").find(regex)
            ?: throw GeneratorException(GeneratorErrorCode.REGEX_NO_CLASS, "The regex must contain a character class (e.g. [a-zA-Z0-9])")
        var allowed = expandCharacterClass(match.groupValues[2])
        if (match.groupValues[1] == "^") allowed = complementAscii(allowed)
        return dedupe(allowed)
    }

    private fun generateFromRegex(regex: String, policy: GeneratorPolicy?, rand: (Int, Int) -> Int): String {
        val compiled = compilePattern(regex)
        val (quantifierMin, maxLength) = extractLength(regex)
        var minLength = quantifierMin
        val charset = extractCharset(regex)
        if (minLength < MIN_LENGTH) {
            throw GeneratorException(GeneratorErrorCode.REGEX_TOO_SHORT, "The regex length must be at least $MIN_LENGTH characters")
        }
        if (policy != null) {
            if (maxLength < policy.minLength) {
                throw GeneratorException(
                    GeneratorErrorCode.REGEX_BELOW_POLICY,
                    "The regex cannot reach the org policy minimum length of ${policy.minLength} characters",
                    policy.minLength.toString(),
                )
            }
            for ((label, classSet) in requiredClasses(policy)) {
                if (!intersects(charset, classSet)) {
                    throw GeneratorException(
                        GeneratorErrorCode.REGEX_EXCLUDES_REQUIRED,
                        "The regex excludes the $label characters the org policy requires",
                        label,
                    )
                }
            }
            minLength = maxOf(minLength, minOf(policy.minLength, maxLength))
        }
        assertCharsetViable(charset)
        repeat(MAX_REGEX_ATTEMPTS) {
            val candidate = buildString(charset, rand(minLength, maxLength), rand)
            if (compiled.containsMatchIn(candidate)) return candidate
        }
        throw GeneratorException(GeneratorErrorCode.REGEX_NO_MATCH, "Unable to generate a value matching the supplied regex")
    }
}
