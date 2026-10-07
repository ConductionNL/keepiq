// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.generator

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.vectors.GeneratedGeneratorVectors
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue
import kotlin.test.fail

/**
 * The shared generator cases (task 3.5): tests/vectors/generator/cases.json
 * holds what the web generator returned for seeded random sources. This
 * generator must give the same value, or the same refusal, for the same
 * draws. tests/vitest/generator-vectors.spec.js runs the web module on the
 * same file.
 */
class GeneratorVectorsTest {
    private val cases: List<JsonObject> by lazy {
        val json = Encoding.fromUtf8(Encoding.fromBase64(GeneratedGeneratorVectors.cases.joinToString("")))
        Json.parseToJsonElement(json).jsonObject.getValue("cases").jsonArray.map { it.jsonObject }
    }

    /** seededRandom in tests/vectors/seeded-random.mjs: xorshift32, one step per draw. */
    private fun seeded(seed: Long): (Int, Int) -> Int {
        var x = seed.toInt().let { if (it == 0) 1 else it }
        return { min, max ->
            x = x xor (x shl 13)
            x = x xor (x ushr 17)
            x = x xor (x shl 5)
            val range = max - min + 1
            if (range <= 1) min else min + (x.toUInt() % range.toUInt()).toInt()
        }
    }

    private fun JsonObject.bool(name: String, default: Boolean) = (this[name] as? JsonPrimitive)?.booleanOrNull ?: default
    private fun JsonObject.int(name: String, default: Int) = (this[name] as? JsonPrimitive)?.intOrNull ?: default
    private fun JsonObject.string(name: String, default: String) = (this[name] as? JsonPrimitive)?.contentOrNull ?: default

    private fun passwordOptions(o: JsonObject) = PasswordOptions(
        length = o.int("length", 16),
        includeUppercase = o.bool("includeUppercase", true),
        includeLowercase = o.bool("includeLowercase", true),
        includeDigits = o.bool("includeDigits", true),
        includeSpecialCharacters = o.bool("includeSpecialCharacters", true),
        minDigits = o.int("minDigits", 0),
        minSpecial = o.int("minSpecial", 0),
        excludedCharacters = o.string("excludedCharacters", ""),
        avoidAmbiguous = o.bool("avoidAmbiguous", false),
        regex = (o["regex"] as? JsonPrimitive)?.contentOrNull,
    )

    private fun passphraseOptions(o: JsonObject) = PassphraseOptions(
        words = o.int("words", Generator.DEFAULT_WORDS),
        separator = o.string("separator", "-"),
        capitalise = o.bool("capitalise", false),
        includeNumber = o.bool("includeNumber", false),
    )

    @Test
    fun everySharedCaseGivesTheWebGeneratorsValueOrRefusal() {
        assertTrue(cases.size > 100, "expected the shared cases, found ${cases.size}")
        val failures = ArrayList<String>()
        for (c in cases) {
            val name = "${c.string("name", "")} (seed ${c["seed"]})"
            val options = c.getValue("options").jsonObject
            val policy = GeneratorPolicy.from(c["policy"] as? JsonObject)
            val rand = seeded((c.getValue("seed") as JsonPrimitive).longOrNull!!)
            val outcome = runCatching {
                if (c.string("kind", "") == "passphrase") {
                    Generator.generatePassphrase(passphraseOptions(options), policy, rand)
                } else {
                    Generator.generateKey(passwordOptions(options), policy, rand)
                }
            }
            val expected = (c["expected"] as? JsonPrimitive)?.contentOrNull
            val error = (c["error"] as? JsonPrimitive)?.contentOrNull
            when {
                expected != null && outcome.getOrNull() != expected ->
                    failures += "$name: expected \"$expected\", got ${outcome.getOrNull()?.let { "\"$it\"" } ?: outcome.exceptionOrNull()?.message}"
                error != null && outcome.exceptionOrNull()?.message != error ->
                    failures += "$name: expected refusal \"$error\", got ${outcome.getOrNull() ?: outcome.exceptionOrNull()?.message}"
            }
        }
        if (failures.isNotEmpty()) fail("${failures.size} of ${cases.size} cases differ:\n" + failures.joinToString("\n"))
    }

    @Test
    fun theWordListIsTheWebOne() {
        assertEquals(7776, EffWordlist.words.size)
        assertEquals("abacus", EffWordlist.words.first())
        assertEquals("zoom", EffWordlist.words.last())
    }

    @Test
    fun aPolicyOfTwentyWithADigitIsMetWithTheSecureSource() {
        val policy = GeneratorPolicy.from(Json.parseToJsonElement("""{"policy_enabled":true,"generator_min_length":20,"generator_require_digit":true}""").jsonObject)!!
        repeat(50) {
            val value = GeneratorSettings(password = GeneratorSettings.DEFAULT_PASSWORD.copy(length = 8, includeDigits = false)).generate(policy)
            assertTrue(value.length >= 20 && value.any { it.isDigit() }, value)
        }
        assertEquals(20, GeneratorSettings().minimumLength(policy))
    }

    @Test
    fun aSixWordPassphraseHasSixListWords() {
        val words = EffWordlist.words.toSet()
        repeat(20) {
            val phrase = GeneratorSettings(GeneratorMode.PASSPHRASE, passphrase = PassphraseOptions(words = 6, separator = " ")).generate(null)
            val parts = phrase.split(' ')
            assertEquals(6, parts.size)
            assertTrue(parts.all { it in words }, phrase)
        }
    }

    @Test
    fun thePolicyIsReadAsTheWebReadsIt() {
        fun p(json: String) = GeneratorPolicy.from(Json.parseToJsonElement(json).jsonObject)
        assertEquals(null, p("""{"policy_enabled":false,"generator_min_length":64}"""))
        assertEquals(null, p("""{"policy_enabled":"true"}"""))
        assertEquals(8, p("""{"policy_enabled":true,"generator_min_length":"4"}""")!!.minLength)
        assertEquals(12, p("""{"policy_enabled":true}""")!!.minLength)
        assertEquals(12, p("""{"policy_enabled":true,"generator_min_length":"abc"}""")!!.minLength)
        assertEquals(true, p("""{"policy_enabled":true}""")!!.allowPassphrase)
        assertEquals(false, p("""{"policy_enabled":true,"generator_allow_passphrase":false}""")!!.allowPassphrase)
        assertEquals(false, p("""{"policy_enabled":true,"generator_require_digit":"true"}""")!!.requireDigit)
    }

    @Test
    fun aPassphraseSwitchedOffFallsBackToAPassword() {
        val policy = GeneratorPolicy.from(Json.parseToJsonElement("""{"policy_enabled":true,"generator_allow_passphrase":false}""").jsonObject)
        assertEquals(GeneratorMode.PASSWORD, GeneratorSettings(GeneratorMode.PASSPHRASE).sanitized(policy).mode)
    }
}
