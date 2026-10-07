// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.boolean
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.int
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.vectors.GeneratedAutofillVectors
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertTrue

/**
 * The shared autofill cases: tests/vectors/autofill/cases.json holds what
 * the browser extension's matcher, use-only rules and save classifier
 * answered. The phone must answer the same; tests/vitest/autofill-vectors.spec.js
 * re-runs the extension on the same file.
 */
class AutofillVectorsTest {
    private val cases: JsonObject by lazy {
        Json.parseToJsonElement(Encoding.fromUtf8(Encoding.fromBase64(GeneratedAutofillVectors.cases.joinToString("")))).jsonObject
    }

    private data class Item(
        override val id: String,
        override val name: String,
        override val url: String?,
        override val useOnly: Boolean,
        override val lastUsedAt: String?,
    ) : Matchable

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.contentOrNull

    private val items: List<Item> by lazy {
        cases.getValue("items").jsonArray.map { it.jsonObject }.map {
            Item(it.text("id")!!, it.text("name") ?: "", it.text("url"), it.text("useOnly") == "true", it.text("lastUsedAt"))
        }
    }

    @Test
    fun hostsReadAsTheExtensionReadsThem() {
        val hosts = cases.getValue("hosts").jsonArray.map { it.jsonObject }
        assertTrue(hosts.size > 30)
        for (h in hosts) {
            val input = h.text("input") ?: ""
            assertEquals(h.text("host"), SiteMatch.hostOf(input), "hostOf($input)")
            assertEquals(h.text("registrable"), SiteMatch.registrableDomain(input), "registrableDomain($input)")
            assertEquals(h.getValue("publicSuffix").jsonPrimitive.boolean, SiteMatch.isPublicSuffix(input), "isPublicSuffix($input)")
        }
    }

    @Test
    fun scoresMatchTheExtension() {
        val scores = cases.getValue("scores").jsonArray.map { it.jsonObject }
        assertTrue(scores.size > 100)
        for (s in scores) {
            assertEquals(
                s.getValue("score").jsonPrimitive.int,
                SiteMatch.matchScore(s.text("name"), s.text("url"), s.text("target")),
                "matchScore(${s.text("name")}, ${s.text("url")}, ${s.text("target")})",
            )
        }
    }

    @Test
    fun rankingAndUseOnlyRulesMatchTheExtension() {
        for (m in cases.getValue("matches").jsonArray.map { it.jsonObject }) {
            val target = m.text("target") ?: ""
            val ranked = SiteMatch.matchSecrets(items, target)
            assertEquals(m.getValue("ids").jsonArray.map { it.jsonPrimitive.content }, ranked.map { it.item.id }, "matchSecrets($target)")
            assertEquals(
                m.getValue("filtered").jsonArray.map { it.jsonPrimitive.content },
                SiteMatch.filterForHost(ranked, target).map { it.item.id },
                "filterForHost($target)",
            )
            assertEquals(m.getValue("blocksSave").jsonPrimitive.boolean, SiteMatch.blocksSavePrompt(items, target), "blocksSavePrompt($target)")
        }
    }

    @Test
    fun saveOffersMatchTheExtension() {
        val stored = cases.getValue("stored").jsonArray.map { it.jsonObject }
        val rows = stored.map { Item(it.text("id")!!, it.text("name") ?: "", it.text("url"), false, null) }
        val plain = stored.associate { o ->
            val p = o["plain"] as? JsonObject
            o.text("id")!! to p?.let { PlainLogin(it.text("login") ?: "", it.text("secret") ?: "") }
        }
        for (c in cases.getValue("classify").jsonArray.map { it.jsonObject }) {
            val offer = Capture.classify(c.text("host")!!, c.text("login") ?: "", c.text("secret") ?: "", rows) { plain[it.id] }
            val (action, id) = when (offer) {
                SaveOffer.Save -> "save" to null
                SaveOffer.None -> "none" to null
                is SaveOffer.Update -> "update" to offer.id
            }
            assertEquals(c.text("action"), action, "classify($c)")
            assertEquals(c.text("id"), id, "classify($c)")
        }
    }
}
