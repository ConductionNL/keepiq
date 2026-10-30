// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFailsWith
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/**
 * A login's `passkeys` array (mobile-login-totp-passkeys D7, D11, D12): one
 * credential changes or goes, and every other entry is kept as stored,
 * members this app does not know included (web D5).
 */
class LoginPasskeysTest {
    private fun credential(id: String, counter: Long = 0) = PasskeyCredential(
        credentialId = id, rpId = "example.com", rpName = "Example", userName = "alice", userDisplayName = "Alice",
        userHandle = "dTE", privateKey = "pk-$id", algorithm = -7L, counter = counter, transports = emptyList(),
        createdAt = "2026-03-12T10:00:00.000Z",
    )

    private val withExtra = JsonObject(Json.parseToJsonElement(credential("a").toJson()).jsonObject + ("extra" to JsonPrimitive(1)))
    private val two = JsonArray(listOf(withExtra, Json.parseToJsonElement(credential("b", 4).toJson()))).toString()

    private fun entries(json: String) = Json.parseToJsonElement(json).jsonArray.map { it.jsonObject }

    @Test
    fun credentialsReadsEveryEntry() {
        assertEquals(listOf("a", "b"), LoginPasskeys.credentials(two).map { it.credentialId })
    }

    @Test
    fun withCounterChangesOnlyThatCredential() {
        val after = entries(LoginPasskeys.withCounter(two, "b", "example.com", 5))
        assertEquals(withExtra, after[0])
        assertEquals("5", after[1]["counter"].toString())
    }

    @Test
    fun withoutRemovesOneAndKeepsTheRestAsStored() {
        assertEquals(listOf(withExtra), entries(LoginPasskeys.without(two, "b", "example.com")))
    }

    @Test
    fun withoutTheLastGivesAnEmptyString() {
        assertEquals("", LoginPasskeys.without(LoginPasskeys.without(two, "a", "example.com"), "b", "example.com"))
    }

    @Test
    fun oneCredentialIdForTwoRpIdsIsTwoCredentials() {
        val other = credential("b").copy(rpId = "example.org")
        val both = JsonArray(listOf(Json.parseToJsonElement(credential("b", 4).toJson()), Json.parseToJsonElement(other.toJson()))).toString()
        val counted = entries(LoginPasskeys.withCounter(both, "b", "example.com", 5))
        assertEquals(listOf("5", "0"), counted.map { it["counter"].toString() })
        assertEquals(listOf("example.org"), LoginPasskeys.credentials(LoginPasskeys.without(both, "b", "example.com")).map { it.rpId })
    }

    @Test
    fun appendedStartsAnArrayOrAddsToOne() {
        assertEquals(listOf("c"), LoginPasskeys.credentials(LoginPasskeys.appended("", credential("c"))).map { it.credentialId })
        assertEquals(listOf("a", "b", "c"), LoginPasskeys.credentials(LoginPasskeys.appended(two, credential("c"))).map { it.credentialId })
    }

    @Test
    fun unreadableArrayGivesNoCredentials() {
        for (text in listOf("", "{", "{}", """[{"rpId":"example.com"}]""")) assertTrue(LoginPasskeys.credentials(text).isEmpty(), text)
    }

    @Test
    fun aValueThatIsNotAnArrayIsNeverOverwritten() {
        val single = credential("a").toJson()
        assertFalse(LoginPasskeys.readable(single))
        assertTrue(LoginPasskeys.readable(""))
        assertTrue(LoginPasskeys.readable(two))
        assertFailsWith<UnreadablePasskeysException> { LoginPasskeys.appended(single, credential("c")) }
        assertFailsWith<UnreadablePasskeysException> { LoginPasskeys.without(single, "a", "example.com") }
        assertFailsWith<UnreadablePasskeysException> { LoginPasskeys.withCounter("{", "a", "example.com", 1) }
    }
}
