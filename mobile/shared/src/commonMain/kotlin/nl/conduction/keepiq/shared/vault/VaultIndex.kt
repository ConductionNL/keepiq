// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.shared.store.StoredFolder
import nl.conduction.keepiq.shared.store.StoredSecret

/**
 * One item as the server or the store gives it: plaintext metadata and the
 * ciphertext untouched. Nothing here is decrypted.
 */
data class VaultRow(
    val id: String,
    val name: String,
    val url: String?,
    val typeId: String?,
    val folderId: String?,
    val key: String?,
    val login: String?,
    val additionalFields: String?,
    val updatedAt: String?,
    val useOnly: Boolean,
    val readOnly: Boolean,
    val blocked: Boolean,
    val blockedReason: String?,
    val trashed: Boolean = false,
) {
    override fun toString(): String = "VaultRow(id=$id, typeId=$typeId, folderId=$folderId)"

    companion object {
        fun from(stored: StoredSecret): VaultRow = VaultRow(
            id = stored.id,
            name = stored.name,
            url = stored.url,
            typeId = stored.typeId,
            folderId = stored.folderId,
            key = stored.key,
            login = stored.login,
            additionalFields = stored.additionalFields,
            updatedAt = stored.updatedAt,
            useOnly = stored.useOnly,
            readOnly = stored.readOnly,
            blocked = stored.blocked,
            blockedReason = stored.blockedReason,
        )

        /** From a server row (Secret::jsonSerialize). Returns null without an id. */
        fun from(obj: JsonObject): VaultRow? {
            val id = obj.text("id") ?: return null
            return VaultRow(
                id = id,
                name = obj.text("name") ?: "",
                url = obj.text("url"),
                typeId = obj.text("typeId"),
                folderId = obj.text("folderId"),
                key = obj.text("key"),
                login = obj.text("login"),
                additionalFields = obj.text("additionalFields"),
                updatedAt = obj.text("updatedAt"),
                useOnly = obj.flag("useOnly"),
                readOnly = obj.flag("readOnly"),
                blocked = obj.flag("blocked"),
                blockedReason = obj.text("blockedReason"),
                trashed = obj.text("trashedAt") != null || obj.text("archivedAt") != null,
            )
        }
    }
}

/** A secret type from `/api/v1/secret-types`: its name and the typed fields it adds. */
data class SecretType(val id: String, val name: String, val label: String?, val fields: List<TypeField>) {
    companion object {
        fun from(obj: JsonObject): SecretType? {
            val id = obj.text("id") ?: return null
            val fields = (obj["fields"] as? JsonArray)?.mapNotNull { f ->
                val o = f as? JsonObject ?: return@mapNotNull null
                val label = o.text("label")?.takeIf { it.isNotBlank() } ?: return@mapNotNull null
                TypeField(
                    key = o.text("key") ?: label,
                    label = label,
                    kind = o.text("kind")?.takeIf { it in TypeField.KINDS } ?: "text",
                    required = o.flag("required"),
                )
            } ?: emptyList()
            return SecretType(id, obj.text("name") ?: obj.text("slug") ?: "login", obj.text("label"), fields)
        }
    }
}

/**
 * One typed field of a secret type (src/utils/typedFields.js). Its value
 * lives in the encrypted additional fields under the field's [label].
 */
data class TypeField(val key: String, val label: String, val kind: String, val required: Boolean) {
    val hidden: Boolean get() = kind == "hidden"

    companion object {
        /** FIELD_KINDS in src/utils/typedFields.js. */
        val KINDS = setOf("text", "hidden", "url", "email")
    }
}

data class VaultFolder(val id: String, val name: String, val parentId: String?) {
    companion object {
        fun from(stored: StoredFolder) = VaultFolder(stored.id, stored.name, stored.parentId)

        fun from(obj: JsonObject): VaultFolder? =
            obj.text("id")?.let { VaultFolder(it, obj.text("name") ?: "", obj.text("parentId")) }
    }
}

/** One list entry: what the vault list shows, never a decrypted value. */
data class IndexEntry(
    val id: String,
    val name: String,
    val url: String,
    val typeName: String,
    val folderId: String?,
    val folderName: String,
    val blocked: Boolean,
    val useOnly: Boolean,
    val readOnly: Boolean,
)

/** A folder in tree order, with its depth (browser-extension/src/lib/folder-rules.js folderTree). */
data class FolderNode(val id: String, val name: String, val parentId: String?, val depth: Int)

/** What the vault list says (browser-extension/src/lib/vault-index.js listState). */
enum class ListState { LOADING, EMPTY, NO_MATCH, ALL_BLOCKED, ITEMS }

/**
 * The vault index the list browses (browser-extension/src/lib/vault-index.js):
 * names, addresses, types and folders. Search runs on the device over names
 * and addresses only; user names are encrypted, and the list decrypts
 * nothing (design, "RSA-4096 on a phone").
 */
object VaultIndex {
    /** The folder filter value for items in no folder (NO_FOLDER). */
    const val NO_FOLDER = "__none__"

    /** buildIndex: trashed and archived rows left out, sorted by name. */
    fun build(rows: List<VaultRow>, types: List<SecretType>, folders: List<VaultFolder>): List<IndexEntry> {
        val typeNames = types.associate { it.id to it.name }
        val folderNames = folders.associate { it.id to it.name }
        return rows.filter { !it.trashed }.map { r ->
            IndexEntry(
                id = r.id,
                name = r.name,
                url = r.url ?: "",
                typeName = r.typeId?.let { typeNames[it] } ?: "login",
                folderId = r.folderId,
                folderName = r.folderId?.let { folderNames[it] } ?: "",
                blocked = r.blocked,
                useOnly = r.useOnly,
                readOnly = r.readOnly,
            )
        }.sortedWith(byName)
    }

    /** filterIndex: the query matches name and address, case-insensitive; [folderId] narrows to one folder. */
    fun filter(entries: List<IndexEntry>, query: String = "", folderId: String? = null, typeName: String? = null): List<IndexEntry> {
        val needle = query.trim().lowercase()
        return entries.filter { e ->
            (folderId.isNullOrEmpty() || (if (folderId == NO_FOLDER) e.folderId == null else e.folderId == folderId)) &&
                (typeName.isNullOrEmpty() || e.typeName == typeName) &&
                (needle.isEmpty() || e.name.lowercase().contains(needle) || e.url.lowercase().contains(needle))
        }
    }

    /** listState. [index] is null while loading. */
    fun listState(index: List<IndexEntry>?, shown: List<IndexEntry>): ListState = when {
        index == null -> ListState.LOADING
        index.isEmpty() -> ListState.EMPTY
        shown.isEmpty() -> ListState.NO_MATCH
        index.all { it.blocked } -> ListState.ALL_BLOCKED
        else -> ListState.ITEMS
    }

    /** folderTree: depth first, siblings sorted by name, a folder whose parent is missing at the top. */
    fun folderTree(folders: List<VaultFolder>): List<FolderNode> {
        val ids = folders.map { it.id }.toSet()
        val children = folders.groupBy { f -> f.parentId?.takeIf { it in ids } }
        val out = ArrayList<FolderNode>()
        val seen = HashSet<String>()
        fun walk(parent: String?, depth: Int) {
            for (f in (children[parent] ?: emptyList()).sortedWith(compareBy(Collation.comparator) { it.name })) {
                if (!seen.add(f.id)) continue
                out += FolderNode(f.id, f.name, f.parentId, depth)
                walk(f.id, depth + 1)
            }
        }
        walk(null, 0)
        return out
    }

    /** The direct subfolders of [parentId] (null: the top level), sorted by name. */
    fun subfolders(folders: List<VaultFolder>, parentId: String?): List<VaultFolder> {
        val ids = folders.map { it.id }.toSet()
        return folders.filter { f -> f.parentId?.takeIf { it in ids } == parentId }
            .sortedWith(compareBy(Collation.comparator) { it.name })
    }

    /** folderPath: "Work / Clients", or null for no folder. */
    fun folderPath(folders: List<VaultFolder>, folderId: String?): String? {
        val byId = folders.associateBy { it.id }
        val names = ArrayList<String>()
        val seen = HashSet<String>()
        var current = folderId?.let { byId[it] }
        while (current != null && seen.add(current.id)) {
            names.add(0, current.name)
            current = current.parentId?.let { byId[it] }
        }
        return if (names.isEmpty()) null else names.joinToString(" / ")
    }

    /** byName: case- and accent-insensitive, then a fixed order by id so the list does not shuffle. */
    val byName: Comparator<IndexEntry> = Comparator { a, b ->
        val c = Collation.comparator.compare(a.name, b.name)
        if (c != 0) c else a.id.compareTo(b.id)
    }
}

/**
 * A stand-in for `localeCompare(…, { sensitivity: 'base' })`: letters are
 * compared without case and without the accents of the Latin alphabets the
 * vault's users write in. Common code has no ICU collator.
 */
object Collation {
    private val folds: Map<Char, String> = buildMap {
        fun add(base: String, accented: String) = accented.forEach { put(it, base) }
        add("a", "àáâãäåāăąǎ"); add("c", "çćĉċč"); add("d", "ďđ"); add("e", "èéêëēĕėęě")
        add("g", "ĝğġģ"); add("h", "ĥħ"); add("i", "ìíîïĩīĭįı"); add("j", "ĵ"); add("k", "ķ")
        add("l", "ĺļľŀł"); add("n", "ñńņňŉ"); add("o", "òóôõöøōŏőǒ"); add("r", "ŕŗř")
        add("s", "śŝşšș"); add("t", "ţťŧț"); add("u", "ùúûüũūŭůűųǔ"); add("w", "ŵ")
        add("y", "ýÿŷ"); add("z", "źżž"); put('ß', "ss"); put('æ', "ae"); put('œ', "oe"); put('ĳ', "ij")
    }

    fun fold(text: String): String {
        val sb = StringBuilder(text.length)
        for (c in text.lowercase()) sb.append(folds[c] ?: c.toString())
        return sb.toString()
    }

    val comparator: Comparator<String> = Comparator { a, b -> fold(a).compareTo(fold(b)) }
}

internal fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull

internal fun JsonObject.flag(name: String): Boolean = (this[name] as? JsonPrimitive)?.contentOrNull == "true"
