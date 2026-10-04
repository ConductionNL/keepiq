// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.Totp

/** How the screens treat a type (browser-extension/src/lib/item-form.js formKind). */
enum class FormKind {
    LOGIN, NOTE, TOTP, CARD, IDENTITY, PASSKEY, GENERIC;

    companion object {
        fun of(typeName: String): FormKind = when (typeName) {
            "login" -> LOGIN
            "note" -> NOTE
            "totp" -> TOTP
            "card" -> CARD
            "identity" -> IDENTITY
            "passkey" -> PASSKEY
            else -> GENERIC
        }
    }
}

/** The card and identity members, in the order the web app writes them (src/cardIdentity/cardIdentity.js). */
object Composite {
    val CARD_FIELDS = listOf("number", "expiry", "cvv", "pin", "cardholder")
    val IDENTITY_FIELDS = listOf("firstName", "lastName", "address", "phone", "email", "bsn")

    /** MASKED_COMPOSITE: shown hidden until revealed. */
    val MASKED = setOf("number", "cvv", "pin", "bsn")

    fun fieldsOf(kind: FormKind): List<String> = when (kind) {
        FormKind.CARD -> CARD_FIELDS
        FormKind.IDENTITY -> IDENTITY_FIELDS
        else -> emptyList()
    }

    /** parsePayload: a JSON object, or null. */
    fun parse(raw: String): Map<String, String>? {
        if (raw.isEmpty() || raw[0] != '{') return null
        val obj = runCatching { Json.parseToJsonElement(raw) as? JsonObject }.getOrNull() ?: return null
        return obj.mapValues { (_, v) -> (v as? JsonPrimitive)?.contentOrNull ?: v.toString() }
    }

    /** serializeCard / serializeIdentity: every member, in order, as a string. */
    fun serialize(kind: FormKind, values: Map<String, String>): String =
        JsonObject(fieldsOf(kind).associateWith { JsonPrimitive(values[it] ?: "") }).toString()

    /** cardLast4. */
    fun last4(number: String): String = number.filter { it.isDigit() }.let { if (it.length >= 4) it.takeLast(4) else "" }
}

/** What a passkey item shows: never its private key (extension-vault "a passkey's private key stays in the worker"). */
data class PasskeySummary(val rpId: String, val rpName: String?, val userName: String?, val createdAt: String?)

/**
 * An opened item. [secret] is the decrypted `key` field; it is empty for a
 * passkey and for a use-only copy, whose value the app never shows.
 */
class DecryptedItem(
    val row: VaultRow,
    val typeName: String,
    val type: SecretType?,
    val login: String,
    val secret: String,
    val additionalFields: JsonObject?,
    val additionalFieldsError: Boolean,
    val passkey: PasskeySummary?,
    /** Read from the offline store because the server could not be reached. */
    val fromCache: Boolean,
) {
    val kind: FormKind get() = FormKind.of(typeName)

    /** The notes: a note's value, else the additional field named "notes" (any case). */
    val notes: String
        get() = if (kind == FormKind.NOTE) {
            secret
        } else {
            notesKey()?.let { additionalFields?.get(it)?.asText() } ?: ""
        }

    /** The card or identity members, or null when the value is not one. */
    val composite: Map<String, String>? get() = Composite.parse(secret)

    /** The typed fields of the type, with their values, in the type's order. */
    val typedValues: List<Pair<TypeField, String>>
        get() = (type?.fields ?: emptyList()).map { f -> f to (additionalFields?.get(f.label)?.asText() ?: "") }

    /** Additional fields that are neither notes nor typed fields. */
    val extraFields: List<Pair<String, String>>
        get() {
            val typed = type?.fields?.map { it.label }?.toSet() ?: emptySet()
            val notes = notesKey()
            return additionalFields?.entries
                ?.filter { (k, _) -> k != notes && k !in typed }
                ?.map { (k, v) -> k to v.asText() } ?: emptyList()
        }

    /** The TOTP parameters, or null when the value is not an authenticator secret. */
    val totp: Totp.Params?
        get() = if (kind == FormKind.TOTP && secret.isNotBlank()) runCatching { Totp.parse(secret) }.getOrNull() else null

    private fun notesKey(): String? = additionalFields?.keys?.firstOrNull { it.lowercase() == ItemCodec.NOTES_FIELD }

    override fun toString(): String = "DecryptedItem(id=${row.id}, type=$typeName)"
}

/**
 * The plaintext parts of an item, before encryption
 * (browser-extension/src/lib/item-form.js partsFromDraft).
 */
data class ItemParts(
    val name: String,
    val url: String,
    val folderId: String?,
    val login: String,
    val key: String,
    val additionalFields: JsonObject?,
) {
    override fun toString(): String = "ItemParts(name=$name, folderId=$folderId)"
}

/** The form's draft (draftFromItem). */
data class ItemDraft(
    val kind: FormKind,
    val typeId: String?,
    val name: String = "",
    val url: String = "",
    val folderId: String? = null,
    val login: String = "",
    val secret: String = "",
    val notes: String = "",
    val composite: Map<String, String> = emptyMap(),
    /** Typed field values by field key. */
    val typed: Map<String, String> = emptyMap(),
    /** Other additional fields, name and value, in order. */
    val fields: List<Pair<String, String>> = emptyList(),
    /** Additional members that are not text: kept as they are, never shown. */
    val preserved: Map<String, JsonElement> = emptyMap(),
) {
    override fun toString(): String = "ItemDraft(kind=$kind, name=$name)"

    val fieldNames: List<String> get() = fields.map { it.first }
    val fieldValues: List<String> get() = fields.map { it.second }

    /**
     * The same draft with what a form edited; kind, type and preserved
     * members stay. For Swift, which sees no default arguments.
     */
    fun edited(
        name: String,
        url: String,
        folderId: String?,
        login: String,
        secret: String,
        notes: String,
        composite: Map<String, String>,
        typed: Map<String, String>,
        fieldNames: List<String>,
        fieldValues: List<String>,
    ): ItemDraft = copy(
        name = name, url = url, folderId = folderId, login = login, secret = secret, notes = notes,
        composite = composite, typed = typed, fields = fieldNames.zip(fieldValues),
    )
}

/** Why a draft cannot be saved, per field. */
enum class DraftProblem { NAME_MISSING, TOO_LONG, FIELD_NAME_MISSING, FIELD_NAME_RESERVED, FIELD_NAME_TAKEN, NOT_AN_AUTHENTICATOR_SECRET, REQUIRED }

/**
 * Decrypting an item for the detail screen, and turning a form into the
 * request body: the field shapes the web app writes (src/store/modules/secret.js
 * createSecret and updateSecret), encrypted on the device.
 */
object ItemCodec {
    const val NOTES_FIELD = "notes"
    const val MAX_NAME_CHARS = 255
    const val MAX_FIELD_CHARS = 4096
    const val MAX_PAYLOAD_BYTES = 65536

    /** RESERVED_MEMBER_NAMES (src/utils/additionalFields.js), plus the notes member. */
    val RESERVED_FIELD_NAMES = setOf("key", "login", "url")

    /**
     * Opens an item. A blocked item is not decrypted. A use-only copy shows
     * its login name and, for an authenticator, the current code, as the web
     * app may (use-only-shares); its value and additional fields stay closed.
     */
    fun open(row: VaultRow, type: SecretType?, keys: VaultKeys, fromCache: Boolean, now: () -> String = { "" }): DecryptedItem {
        val typeName = type?.name ?: "login"
        val kind = FormKind.of(typeName)
        if (row.blocked) {
            return DecryptedItem(row, typeName, type, "", "", null, false, null, fromCache)
        }
        val login = keys.decryptField(row.login)
        if (row.useOnly) {
            val code = if (kind == FormKind.TOTP) keys.decryptField(row.key) else ""
            return DecryptedItem(row, typeName, type, login, code, null, false, null, fromCache)
        }
        var secret = keys.decryptField(row.key)
        var passkey: PasskeySummary? = null
        if (kind == FormKind.PASSKEY) {
            passkey = PasskeyCredential.parse(secret, now)?.let {
                PasskeySummary(it.rpId, it.rpName.ifEmpty { null }, (it.userName.ifEmpty { it.userDisplayName }).ifEmpty { null }, it.createdAt.ifEmpty { null })
            }
            secret = ""
        }
        var fields: JsonObject? = null
        var fieldsError = false
        if (!row.additionalFields.isNullOrEmpty()) {
            val parsed = runCatching { Json.parseToJsonElement(keys.decryptField(row.additionalFields)) }.getOrNull()
            if (parsed is JsonObject) fields = parsed else fieldsError = true
        }
        return DecryptedItem(row, typeName, type, login, secret, fields, fieldsError, passkey, fromCache)
    }

    /** draftFromItem; an empty draft of [type] when [item] is null. */
    fun draft(item: DecryptedItem?, type: SecretType?): ItemDraft {
        val kind = FormKind.of(type?.name ?: item?.typeName ?: "login")
        if (item == null) return ItemDraft(kind = kind, typeId = type?.id, typed = type?.fields?.associate { it.key to "" } ?: emptyMap())
        val fields = item.additionalFields ?: JsonObject(emptyMap())
        val notesKey = fields.keys.firstOrNull { it.lowercase() == NOTES_FIELD }
        val typedLabels = type?.fields?.associate { it.label to it.key } ?: emptyMap()
        val typed = LinkedHashMap<String, String>()
        type?.fields?.forEach { typed[it.key] = "" }
        val extra = ArrayList<Pair<String, String>>()
        val preserved = LinkedHashMap<String, JsonElement>()
        for ((name, value) in fields) {
            if (name == notesKey) continue
            val key = typedLabels[name]
            when {
                key != null -> typed[key] = value.asText()
                value is JsonPrimitive && value.isString -> extra += name to value.content
                else -> preserved[name] = value
            }
        }
        val composite = if (kind == FormKind.CARD || kind == FormKind.IDENTITY) {
            val parsed = Composite.parse(item.secret) ?: emptyMap()
            Composite.fieldsOf(kind).associateWith { parsed[it] ?: "" }
        } else {
            emptyMap()
        }
        return ItemDraft(
            kind = kind,
            typeId = item.row.typeId,
            name = item.row.name,
            url = item.row.url ?: "",
            folderId = item.row.folderId,
            login = item.login,
            secret = if (kind == FormKind.NOTE || kind == FormKind.CARD || kind == FormKind.IDENTITY) "" else item.secret,
            notes = item.notes,
            composite = composite,
            typed = typed,
            fields = extra,
            preserved = preserved,
        )
    }

    /** partsFromDraft, with the typed fields merged under their labels (mergeTypedValues). */
    fun parts(draft: ItemDraft, type: SecretType?): ItemParts {
        val key = when (draft.kind) {
            FormKind.NOTE -> draft.notes
            FormKind.CARD, FormKind.IDENTITY -> Composite.serialize(draft.kind, draft.composite)
            else -> draft.secret
        }
        val fields = LinkedHashMap<String, JsonElement>()
        fields.putAll(draft.preserved)
        for ((name, value) in draft.fields) fields[name.trim()] = JsonPrimitive(value)
        for (f in type?.fields ?: emptyList()) {
            val value = draft.typed[f.key] ?: ""
            if (value != "") fields[f.label] = JsonPrimitive(value) else fields.remove(f.label)
        }
        if (draft.kind != FormKind.NOTE && draft.notes.trim().isNotEmpty()) fields[NOTES_FIELD] = JsonPrimitive(draft.notes)
        return ItemParts(
            name = draft.name.trim(),
            url = draft.url.trim(),
            folderId = draft.folderId?.takeIf { it.isNotEmpty() },
            login = if (draft.kind == FormKind.LOGIN || draft.kind == FormKind.GENERIC) draft.login else "",
            key = key,
            additionalFields = if (fields.isEmpty()) null else JsonObject(fields),
        )
    }

    /** validateDraft: problems by field ("name", "url", "login", "secret", "fields", "field-<i>", "typed-<key>"). */
    fun validate(draft: ItemDraft, type: SecretType?): Map<String, DraftProblem> {
        val errors = LinkedHashMap<String, DraftProblem>()
        if (draft.name.trim().isEmpty()) errors["name"] = DraftProblem.NAME_MISSING
        if (draft.name.length > MAX_NAME_CHARS) errors["name"] = DraftProblem.TOO_LONG
        if (draft.url.length > MAX_FIELD_CHARS) errors["url"] = DraftProblem.TOO_LONG
        if (draft.login.length > MAX_FIELD_CHARS) errors["login"] = DraftProblem.TOO_LONG
        val seen = HashSet<String>()
        val typedLabels = type?.fields?.map { it.label.lowercase() }?.toSet() ?: emptySet()
        draft.fields.forEachIndexed { i, (name, value) ->
            val clean = name.trim().lowercase()
            when {
                clean.isEmpty() -> errors["field-$i"] = DraftProblem.FIELD_NAME_MISSING
                clean in RESERVED_FIELD_NAMES || clean == NOTES_FIELD -> errors["field-$i"] = DraftProblem.FIELD_NAME_RESERVED
                clean in seen || clean in typedLabels -> errors["field-$i"] = DraftProblem.FIELD_NAME_TAKEN
                value.length > MAX_FIELD_CHARS -> errors["field-$i"] = DraftProblem.TOO_LONG
            }
            seen += clean
        }
        for (f in type?.fields ?: emptyList()) {
            if (f.required && (draft.typed[f.key] ?: "").trim().isEmpty()) errors["typed-${f.key}"] = DraftProblem.REQUIRED
        }
        if (draft.kind == FormKind.TOTP && draft.secret.trim().isNotEmpty() && runCatching { Totp.parse(draft.secret) }.isFailure) {
            errors["secret"] = DraftProblem.NOT_AN_AUTHENTICATOR_SECRET
        }
        val parts = parts(draft, type)
        if (Encoding.utf8(parts.key).size > MAX_PAYLOAD_BYTES) errors["secret"] = DraftProblem.TOO_LONG
        if (parts.additionalFields != null && Encoding.utf8(parts.additionalFields.toString()).size > MAX_PAYLOAD_BYTES) {
            errors["fields"] = DraftProblem.TOO_LONG
        }
        return errors
    }

    /** The fields whose plaintext differs (changedParts): only those are sent on an update. */
    fun changed(before: ItemParts, after: ItemParts): Set<String> = buildSet {
        if (before.name != after.name) add("name")
        if (before.url != after.url) add("url")
        if ((before.folderId ?: "") != (after.folderId ?: "")) add("folderId")
        if (before.login != after.login) add("login")
        if (before.key != after.key) add("key")
        if (before.additionalFields != after.additionalFields) add("additionalFields")
    }

    /**
     * The create body, as src/store/modules/secret.js createSecret writes it:
     * `key` always encrypted (an empty value is one chunk), `login` only when
     * not empty, `additionalFields` as one encrypted JSON object when present.
     */
    fun createBody(parts: ItemParts, typeId: String?, keys: VaultKeys): JsonObject {
        val body = LinkedHashMap<String, JsonElement>()
        body["name"] = JsonPrimitive(parts.name)
        body["url"] = parts.url.ifEmpty { null }.json()
        body["typeId"] = typeId.json()
        body["folderId"] = parts.folderId.json()
        body["key"] = JsonPrimitive(keys.encryptField(parts.key))
        if (parts.login.isNotEmpty()) body["login"] = JsonPrimitive(keys.encryptField(parts.login))
        parts.additionalFields?.let { body["additionalFields"] = JsonPrimitive(keys.encryptField(it.toString())) }
        return JsonObject(body)
    }

    /**
     * A sparse update body with only [changed] fields, as updateSecret writes
     * it: an emptied login is sent as null, emptied additional fields as null
     * (the extension's vault-save), anything else encrypted.
     */
    fun updateBody(parts: ItemParts, changed: Set<String>, keys: VaultKeys): JsonObject {
        val body = LinkedHashMap<String, JsonElement>()
        if ("name" in changed) body["name"] = JsonPrimitive(parts.name)
        if ("url" in changed) body["url"] = parts.url.ifEmpty { null }.json()
        if ("folderId" in changed) body["folderId"] = parts.folderId.json()
        if ("key" in changed) body["key"] = JsonPrimitive(keys.encryptField(parts.key))
        if ("login" in changed) body["login"] = if (parts.login.isEmpty()) JsonNull else JsonPrimitive(keys.encryptField(parts.login))
        if ("additionalFields" in changed) {
            body["additionalFields"] = parts.additionalFields?.let { JsonPrimitive(keys.encryptField(it.toString())) } ?: JsonNull
        }
        return JsonObject(body)
    }

    private fun String?.json(): JsonElement = if (this == null) JsonNull else JsonPrimitive(this)
}

internal fun JsonElement.asText(): String = when (this) {
    is JsonNull -> ""
    is JsonPrimitive -> contentOrNull ?: ""
    else -> toString()
}
