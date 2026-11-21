// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertTrue

/** The field schemas an edit keeps, as the web app's withSchemas does. */
class FieldSchemaEditTest {
    private fun item(fields: String) = DecryptedItem(
        row = VaultRow("s1", "Server", null, null, null, null, null, null, null, false, false, false, null),
        typeName = "login",
        type = null,
        login = "",
        secret = "",
        additionalFields = Json.parseToJsonElement(fields).jsonObject,
        additionalFieldsError = false,
        passkey = null,
        fromCache = false,
    )

    private fun saved(draft: ItemDraft) = ItemCodec.parts(draft, null).additionalFields

    private fun json(text: String) = Json.parseToJsonElement(text).jsonObject

    @Test
    fun aRenamedFieldKeepsItsSchema() {
        val draft = ItemCodec.draft(item("""{"PIN":"4711","@schema":{"PIN":{"type":"string","hidden":true}}}"""), null)
        assertTrue(ItemCodec.masksField(draft.fieldNames[0], draft.schemaOf(0)))

        val renamed = draft.copy(fields = listOf("Card PIN" to "4711"))
        assertEquals(json("""{"Card PIN":"4711","@schema":{"Card PIN":{"type":"string","hidden":true}}}"""), saved(renamed))
    }

    @Test
    fun aRemovedFieldTakesItsSchemaAlong() {
        val draft = ItemCodec.draft(item("""{"PIN":"1","Host":"db","@schema":{"PIN":{"type":"string","hidden":true}}}"""), null)
        val removed = draft.withoutField(0)
        assertFalse(ItemCodec.masksField(removed.fieldNames[0], removed.schemaOf(0)))
        assertEquals(json("""{"Host":"db"}"""), saved(removed))
        assertEquals(listOf("", ""), removed.withField().fieldSchemas)
    }

    @Test
    fun keepsTheSchemasOfMembersThatAreNoRow() {
        val draft = ItemCodec.draft(
            item("""{"Notes":"n","count":42,"pin":"1","@schema":{"Notes":{"type":"string","hidden":true},"count":{"type":"integer"},"gone":{"type":"integer"}}}"""),
            null,
        )
        assertEquals(
            json("""{"count":42,"pin":"1","notes":"n","@schema":{"notes":{"type":"string","hidden":true},"count":{"type":"integer"}}}"""),
            saved(draft),
        )
    }

    @Test
    fun aFieldCannotBeNamedSchema() {
        val draft = ItemCodec.draft(null, null).copy(name = "x", fields = listOf("@schema" to "v"))
        assertEquals(DraftProblem.FIELD_NAME_RESERVED, ItemCodec.validate(draft, null)["field-0"])
    }

    @Test
    fun keepsAFieldSomeoneNamedSchemaUnderTheWebAppsName() {
        val draft = ItemCodec.draft(item("""{"pin":"1","@schema":"my value"}"""), null)
        assertEquals(listOf("pin" to "1", ItemCodec.RENAMED_SCHEMA_FIELD to "my value"), draft.fields)
        assertEquals(json("""{"pin":"1","@schema (imported)":"my value"}"""), saved(draft))
    }
}
