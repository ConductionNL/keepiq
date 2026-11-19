// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlin.test.Test
import kotlin.test.assertEquals

/** The extra fields the detail screen masks, as the web app does. */
class ExtraFieldMaskTest {
    private fun item(fields: String) = DecryptedItem(
        row = VaultRow("s1", "Server", null, null, null, null, null, null, null, false, false, false, null),
        typeName = "certificate",
        type = null,
        login = "",
        secret = "",
        additionalFields = Json.parseToJsonElement(fields).jsonObject,
        additionalFieldsError = false,
        passkey = null,
        fromCache = false,
    )

    @Test
    fun masksHiddenFieldsAndTheBuiltInSecretMembers() {
        val item = item(
            """{"PIN":"1234","Token":"t","Old":{"value":"x","kind":"hidden"},"Host":"db",
            "Private key":"-----BEGIN PRIVATE KEY-----","Passphrase":"p","API secret":"s",
            "@schema":{"PIN":{"type":"string","hidden":true},"Token":{"type":"string","format":"password"}}}""",
        )

        assertEquals(
            listOf("PIN", "Token", "Old", "Private key", "Passphrase", "API secret"),
            item.extraFields.map { it.first }.filter(item::masksExtra),
        )
    }

    @Test
    fun showsAMisnamedSchemaMemberUnderItsImportedName() {
        assertEquals(
            listOf("pin" to "1", ItemCodec.RENAMED_SCHEMA_FIELD to "my value"),
            item("""{"pin":"1","@schema":"my value"}""").extraFields,
        )
        // A schema map is no field; a taken imported name keeps its own value.
        assertEquals(listOf("pin" to "1"), item("""{"pin":"1","@schema":{"pin":{"type":"string"}}}""").extraFields)
        assertEquals(
            listOf(ItemCodec.RENAMED_SCHEMA_FIELD to "mine"),
            item("""{"@schema":"theirs","@schema (imported)":"mine"}""").extraFields,
        )
    }
}
