// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.vault

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.api.KeepiqApiException
import nl.conduction.keepiq.shared.crypto.FederatedCertificate
import nl.conduction.keepiq.shared.crypto.FederatedCertificateException
import nl.conduction.keepiq.shared.crypto.FederatedCertificateReason
import nl.conduction.keepiq.shared.crypto.FederatedCertificateUnsupportedException
import nl.conduction.keepiq.shared.crypto.RsaFields
import nl.conduction.keepiq.shared.crypto.RsaPublicKey
import nl.conduction.keepiq.shared.send.IsoTime

/** Plaintext or ciphertext per value field: null for a cleared one, `""` for a removed `totp` or `passkeys`; an absent field is not sent. */
typealias Values = Map<String, String?>

/** The answer of `write-context` (ShareWriteContext::writeContext). */
sealed class WriteContext {
    abstract val ownerCertificate: String?
    abstract val sourceUpdatedAt: String?

    data class Source(
        val sourceSecretId: String,
        val effectiveGrade: String,
        override val ownerCertificate: String?,
        override val sourceUpdatedAt: String?,
    ) : WriteContext()

    /** The caller's row leads to no source they stand on: it is saved alone, and share-save judges it again. */
    data object OwnRow : WriteContext() {
        override val ownerCertificate: String? = null
        override val sourceUpdatedAt: String? = null
    }

    /** A copy without a write grade: a change would reach that copy alone, so it is refused (keepiq#1287). */
    fun isViewOnly(id: String): Boolean = this is Source && sourceSecretId != id && effectiveGrade !in ShareSave.COPY_EDITOR_GRADES
}

/** What the form opened: the ciphertext of each value field it held in full, the row version, and the source version write-context named. */
class Opened(val shown: Values, val updatedAt: String, val sourceUpdatedAt: String?)

class ShareSaveInput(
    val id: String,
    /** The editor's own sparse body, its value fields encrypted to the editor. */
    val patch: JsonObject,
    /** The patch's value fields as typed. */
    val plain: Values,
    val baseUpdatedAt: String,
    val context: WriteContext,
    /** The form's record; null when the save had no form. */
    val opened: Opened?,
    /** Partner shares the user agreed to leave out (Save without them). */
    val withoutUnreachable: Set<String> = emptySet(),
)

/** What the item's detail says after a save that did not reach everyone. */
data class ShareSaveNote(
    val ownerOnly: Boolean = false,
    val suspended: List<String> = emptyList(),
    val paused: List<String> = emptyList(),
)

/** A share-save refused on the phone, or a server refusal read; [code] as the extension names it. */
class ShareSaveException(
    val code: String,
    val partners: List<PartnerShare> = emptyList(),
    val requiredVersion: String? = null,
) : Exception(code)

/**
 * A value change of a possibly shared item in one `PUT /share-save`, ported
 * from browser-extension/src/vault/share-save.ts: the row, every copy (each
 * sealed to its holder) and every partner share (each sealed to a verified
 * partner certificate) in one transaction, or nothing.
 */
class ShareSave(private val api: KeepiqApi, private val keys: VaultKeys, private val now: () -> Long) {
    companion object {
        val VALUE_FIELDS = listOf("key", "login", "additionalFields", "totp", "passkeys")

        /** Fields a partner may not store (ADR-003 § Partner certificate). */
        private val PARTNER_OPTIONAL = listOf("totp", "passkeys")

        /** `ShareSaveRequest::MAX_ENTRIES` per list, and `ShareController::MAX_RECIPIENT_PROBE`. */
        internal const val BATCH = 100
        private const val MAX_ROUNDS = 3
        internal val COPY_EDITOR_GRADES = setOf("owner", "write", "manage")

        /** Lookup refusals that are a verdict on the recipient, with the status each comes with. */
        private val PARTNER_GONE = mapOf("unknown_recipient" to 404, "not_a_partner" to 403, "bad_chain" to 502)

        /** The first Keepiq that stores `totp` and `passkeys` for a partner (Application::TOTP_PASSKEYS_FIELDS_SINCE). */
        const val TOTP_PASSKEYS_FIELDS_SINCE = "0.3.12"

        private val PIN = Regex("^[0-9a-fA-F]{64}$")

        const val VIEW_ONLY = "view_only"
        const val STALE_SOURCE = "stale_source"
        const val INCOMPLETE = "incomplete"
        const val UNVERIFIED = "unverified"
        const val UNREADABLE = "unreadable"
        const val BAD_CERTIFICATE = "bad_certificate"
        const val PARTNER_UNREACHABLE = "partner_unreachable"
        const val PARTNER_UPGRADE_REQUIRED = "partner_upgrade_required"
        const val PARTNER_CLOCK = "partner_clock"
        const val PARTNER_LOOKUP_LIMIT = "partner_lookup_limit"
        const val FEDERATION_UNAVAILABLE = "federation_unavailable"
        const val KEEPIQ_OUTDATED = "keepiq_outdated"
        const val FORBIDDEN = "forbidden"
        const val SERVER_ERROR = "server_error"
        const val UNKNOWN = "unknown"

        /** The server sent a value ciphertext the device doesn't hold for the item. */
        const val CONFLICT = "conflict"

        /**
         * What a passkey write passes on as read, so what [holds] checks for it:
         * `totp`, vouched for partners, and `passkeys`, rewritten for everyone.
         * The other fields are vouched for only when they hold nothing ([openedForPasskeys]).
         */
        val PASSKEY_WRITE_HELD = setOf("totp", "passkeys")

        /**
         * Whether every value ciphertext of [fresh] is the one the device holds
         * for it ([held]: from the store, the list or the autofill index; a
         * field it lacks is not compared). Every item is encrypted to the same
         * key, so a ciphertext the device doesn't hold for this item may be
         * another's, which a save would pass on (ADR-002, the extension's `cachedMatch`).
         */
        fun holds(fresh: VaultRow, held: Values): Boolean = held.all { (field, ciphertext) -> fresh.values[field] == ciphertext }

        /**
         * The ciphertext a passkey save vouches for: `totp` and `passkeys`, read
         * fresh, and the other value fields only where they hold nothing; any
         * other field fails partner shares as unverified, as in the extension.
         * The caller has checked [row] with [holds].
         */
        fun openedForPasskeys(row: VaultRow, keys: VaultKeys, sourceUpdatedAt: String?): Opened {
            val shown = linkedMapOf<String, String?>("totp" to row.totp?.ifEmpty { null }, "passkeys" to row.passkeys?.ifEmpty { null })
            val held = mapOf("key" to row.key, "login" to row.login, "additionalFields" to row.additionalFields)
            for ((field, ciphertext) in held) {
                if (holdsNothing(field, ciphertext, keys)) shown[field] = ciphertext?.ifEmpty { null }
            }
            return Opened(shown, row.updatedAt ?: "", sourceUpdatedAt)
        }

        private fun holdsNothing(field: String, ciphertext: String?, keys: VaultKeys): Boolean {
            if (ciphertext.isNullOrEmpty()) return true
            val plain = try {
                keys.decryptField(ciphertext)
            } catch (e: Exception) {
                return false
            }
            if (plain.isEmpty()) return true
            return field == "additionalFields" && (runCatching { Json.parseToJsonElement(plain) }.getOrNull() as? JsonObject)?.isEmpty() == true
        }
    }

    /** Reads write-context; a 404 is checked against the row, which only Keepiq's 404 on the row itself proves gone. */
    suspend fun readContext(id: String): WriteContext {
        val context = try {
            api.writeContext(id)
        } catch (e: KeepiqApiException) {
            if (notKeepiq(e)) throw ShareSaveException(SERVER_ERROR)
            throw if (e.status == 404) goneOrRefused(id) else e
        }
        if (context?.get("sourceSecretId") is JsonNull) return WriteContext.OwnRow
        val source = context?.text("sourceSecretId") ?: throw ShareSaveException(UNKNOWN)
        return WriteContext.Source(source, context.text("effectiveGrade") ?: "none", context.text("ownerCertificate"), context.text("sourceUpdatedAt"))
    }

    /** Saves; returns the note the item's detail shows, or null when everyone got the change. */
    suspend fun save(input: ShareSaveInput): ShareSaveNote? {
        if (input.context.isViewOnly(input.id)) throw ShareSaveException(VIEW_ONLY)
        val partners = VerifiedPartners.session(api.accountId, now)
        return try {
            Run(input, partners).run().also { partners.succeeded() }
        } catch (e: Throwable) {
            partners.failed()
            throw e
        }
    }

    /**
     * A save with no edit form behind it, on [fresh] as just read: an autofill
     * update or a passkey write. With [vouchEmpty] the fields that hold
     * nothing count as opened ([openedForPasskeys]), after the caller checked
     * [fresh] with [holds]; without it, partner
     * shares refuse the save as unverified, as the extension's capture queue does.
     */
    suspend fun saveFresh(fresh: VaultRow, patch: JsonObject, plain: Values, vouchEmpty: Boolean): ShareSaveNote? {
        val base = fresh.updatedAt ?: throw ShareSaveException(SERVER_ERROR)
        val context = readContext(fresh.id)
        val opened = if (vouchEmpty) openedForPasskeys(fresh, keys, context.sourceUpdatedAt) else null
        return save(ShareSaveInput(fresh.id, patch, plain, base, context, opened))
    }

    private suspend fun goneOrRefused(id: String): Throwable {
        val row = try {
            api.getSecret(id)
        } catch (e: CancellationException) {
            throw e
        } catch (e: KeepiqApiException) {
            return if (notKeepiq(e)) ShareSaveException(SERVER_ERROR) else e
        }
        return ShareSaveException(if (row?.string("updatedAt") != null) FORBIDDEN else SERVER_ERROR)
    }

    /** Nextcloud's page for a route this Keepiq doesn't have: a 404 that is not JSON. */
    private fun notKeepiq(e: KeepiqApiException): Boolean = e.status == 404 && bodyOf(e) == null

    private fun bodyOf(e: KeepiqApiException): JsonObject? = runCatching { Json.parseToJsonElement(e.body) as? JsonObject }.getOrNull()

    private fun messageOf(e: KeepiqApiException): String? = bodyOf(e)?.text("message")

    /** A JSON string, `""` included. */
    private fun JsonObject.string(name: String): String? = (this[name] as? JsonPrimitive)?.takeIf { it.isString }?.content

    /** A JSON string that is not empty. */
    private fun JsonObject.text(name: String): String? = string(name)?.takeIf { it.isNotEmpty() }

    /** Only the JSON literal `true`, as the extension compares `=== true`. */
    private fun JsonObject.isTrue(name: String): Boolean = (this[name] as? JsonPrimitive)?.let { !it.isString && it.booleanOrNull == true } == true

    /** One armoured certificate or public key, as the extension's `decodePem` reads it; anything else is no key. */
    private val ARMOURED = Regex("""^\s*-----BEGIN (CERTIFICATE|PUBLIC KEY)-----[A-Za-z0-9+/=\s]+-----END \1-----\s*$""")

    private fun JsonElement?.list(): List<JsonElement> = (this as? JsonArray) ?: emptyList()

    private sealed class PartnerOutcome {
        class Verified(val partner: VerifiedPartner) : PartnerOutcome()
        class Suspend(val reason: String) : PartnerOutcome()
        data object Unreachable : PartnerOutcome()
    }

    private class RequiredCopy(val secretId: String, val holder: String, val targetUserId: String?)

    private class Required(val copies: List<RequiredCopy>, val skipRefused: Set<String>, val shares: List<PartnerShare>)

    private class Pending(val key: String, val entry: JsonObject)

    /** One list of the save, by key; [prefix] names the list in what was already staged. */
    private class Staged(private val prefix: String) {
        val entries = LinkedHashMap<String, JsonObject>()

        fun unsent(sent: Set<String>): List<Pending> = entries.filterKeys { (prefix + it) !in sent }.map { (key, entry) -> Pending(prefix + key, entry) }

        /** Drops every entry [keep] refuses; true when one of them was already staged. */
        fun drop(keep: (String) -> Boolean, sent: Set<String>): Boolean {
            var restage = false
            for (key in entries.keys.toList()) {
                if (keep(key)) continue
                entries.remove(key)
                restage = restage || (prefix + key) in sent
            }
            return restage
        }
    }

    /** What one round of partner shares found: the whole value they get, the fields they must store, and who could not take it. */
    private class PartnerRound(val value: Values, val adds: List<String>) {
        val unreachable = ArrayList<PartnerShare>()
        val tooOld = ArrayList<PartnerShare>()
    }

    /** One share-save: the lists sent so far, the certificates read for them, and the stage the server holds. */
    private inner class Run(private val input: ShareSaveInput, private val partners: VerifiedPartners.Session) {
        private val copyPath = input.context is WriteContext.Source && input.context.sourceSecretId != input.id
        private val sourceBaseUpdatedAt = sourceBaseOf()
        private var ownerCertificate = input.context.ownerCertificate
        private val copies = Staged("c:")
        private val skipped = Staged("s:")
        private val federated = Staged("f:")
        private val suspend = Staged("p:")
        private val cloudIds = HashMap<String, String>()
        private val certificates = HashMap<String, String?>()
        private val publicKeys = HashMap<String, RsaPublicKey>()
        /** Holders whose skip the server refused and whose certificate was read again; once each. */
        private val reread = HashSet<String>()
        private var whole: Values? = null
        private var stageId: String? = null
        private var sent = HashSet<String>()
        private var rounds = 0
        /** Set when a fill dropped an entry already staged, which cannot be taken back, so a new stage starts. */
        private var restage = false

        /** A form opened while its row was saved alone made its edit from no source version, so the source's current one would hide a change since. */
        private fun sourceBaseOf(): String? {
            val opened = input.opened ?: return input.context.sourceUpdatedAt
            if (copyPath && opened.sourceUpdatedAt == null) throw ShareSaveException(STALE_SOURCE)
            return opened.sourceUpdatedAt
        }

        suspend fun run(): ShareSaveNote? {
            val answer = sendUntilComplete()
            return if (copyPath) copyNote(answer) else partnerNote()
        }

        private suspend fun sendUntilComplete(): JsonObject? {
            while (true) {
                try {
                    return send()
                } catch (e: CancellationException) {
                    throw e
                } catch (e: Exception) {
                    recover(e)
                }
            }
        }

        private suspend fun recover(error: Exception) {
            val api = error as? KeepiqApiException
            val restart = api != null && api.status == 404 && api.code == "stage_unknown" && stageId != null
            val incomplete = api != null && api.status == 409 && api.code == INCOMPLETE
            if (!restart && !incomplete) throw saveRefusal(refusalOf(error))
            if (++rounds >= MAX_ROUNDS) throw ShareSaveException(INCOMPLETE)
            if (restart) {
                stageId = null
                sent = HashSet()
            } else {
                fill(bodyOf(api!!) ?: JsonObject(emptyMap()))
            }
        }

        /** An own row's share-save 404 is checked against the row, as a write-context 404 is. */
        private suspend fun saveRefusal(refusal: Throwable): Throwable =
            if (input.context is WriteContext.OwnRow && refusal is KeepiqApiException && refusal.status == 404) goneOrRefused(input.id) else refusal

        private fun refusalOf(error: Exception): Throwable {
            if (error !is KeepiqApiException) return error
            if (notKeepiq(error)) return ShareSaveException(KEEPIQ_OUTDATED)
            val refused = error.status == 403 || error.status == 428
            if (error.status == 409) {
                if (error.code == STALE_SOURCE) return ShareSaveException(STALE_SOURCE)
                if (error.code == PARTNER_UPGRADE_REQUIRED) return partnerUpgradeRefusal(error)
            }
            return if (refused && error.code == "not_allowed") ShareSaveException(FORBIDDEN) else error
        }

        private fun partnerUpgradeRefusal(error: KeepiqApiException): ShareSaveException {
            val body = bodyOf(error)
            val shareId = body?.text("shareId")
            val cloudId = shareId?.let { cloudIds[it] }
            return ShareSaveException(
                PARTNER_UPGRADE_REQUIRED,
                if (shareId != null && cloudId != null) listOf(PartnerShare(shareId, cloudId)) else emptyList(),
                body?.text("requiredVersion"),
            )
        }

        private fun copyNote(answer: JsonObject?): ShareSaveNote? =
            if ((answer?.get("federated") as? JsonObject)?.isTrue("ownerOnly") == true) ShareSaveNote(ownerOnly = true) else null

        /** Those the user left out through Save without them get their own line, worded for a pause, not a verdict on the recipient. */
        private fun partnerNote(): ShareSaveNote? {
            val reasons = suspend.entries.mapValues { (_, entry) -> entry.text("reason") }
            fun named(paused: Boolean) = reasons.filter { (it.value == PARTNER_UNREACHABLE) == paused }.keys.mapNotNull { cloudIds[it] }
            val suspended = named(false)
            val paused = named(true)
            if (suspended.isEmpty() && paused.isEmpty()) return null
            return ShareSaveNote(suspended = suspended, paused = paused)
        }

        private fun keyOf(certificate: String): RsaPublicKey = publicKeys.getOrPut(certificate) {
            try {
                if (!ARMOURED.matches(certificate)) throw ShareSaveException(BAD_CERTIFICATE)
                RsaPublicKey.fromPem(certificate)
            } catch (e: Exception) {
                throw ShareSaveException(BAD_CERTIFICATE)
            }
        }

        private fun encrypt(plain: String, key: RsaPublicKey): String = try {
            RsaFields.encrypt(plain, key)
        } catch (e: Exception) {
            throw ShareSaveException(BAD_CERTIFICATE)
        }

        /** `""` removes a `totp` or `passkeys` on the wire; encrypted, it would be one that does not read. */
        private fun sealValue(field: String, value: String?, key: RsaPublicKey): JsonElement = when {
            value == null -> JsonNull
            value.isEmpty() && field in PARTNER_OPTIONAL -> JsonPrimitive("")
            else -> JsonPrimitive(encrypt(value, key))
        }

        /** Exactly the fields the patch carries, with the same null-ness, or the server refuses it (`copy_fields_mismatch`). */
        private fun sealSparse(key: RsaPublicKey): Map<String, JsonElement> =
            VALUE_FIELDS.filter { input.plain.containsKey(it) }.associateWith { sealValue(it, input.plain[it], key) }

        /** Null where the partner does not store a field: a partner left out of it keeps its value only under the same certificate. */
        private fun sealWhole(value: Values, key: RsaPublicKey, valueFields: List<String>): Map<String, JsonElement> {
            fun sealed(text: String?): JsonElement = if (text.isNullOrEmpty()) JsonNull else JsonPrimitive(encrypt(text, key))
            fun optional(field: String): JsonElement = if (field in valueFields) sealed(value[field]) else JsonNull
            return mapOf(
                "key" to JsonPrimitive(encrypt(value["key"] ?: "", key)),
                "login" to sealed(value["login"]),
                "additionalFields" to sealed(value["additionalFields"]),
                "totp" to optional("totp"),
                "passkeys" to optional("passkeys"),
            )
        }

        /** The typed fields plus every other one as the form opened it in full; the server's rows are never decrypted for it (ADR-002). */
        private fun wholeValue(): Values {
            val opened = input.opened
            // The form's ciphertext is the item's only at the version the save is checked against.
            val base = IsoTime.parseMillis(input.baseUpdatedAt)
            val shown = opened?.shown?.takeIf { base != null && IsoTime.parseMillis(opened.updatedAt) == base }
            return VALUE_FIELDS.associateWith { field ->
                when {
                    input.plain.containsKey(field) -> input.plain[field]
                    shown == null || !shown.containsKey(field) -> throw ShareSaveException(UNVERIFIED)
                    else -> decryptShown(shown[field])
                }
            }
        }

        private fun decryptShown(ciphertext: String?): String? = try {
            if (ciphertext.isNullOrEmpty()) null else keys.decryptField(ciphertext)
        } catch (e: Exception) {
            throw ShareSaveException(UNREADABLE)
        }

        private fun envelope(): MutableMap<String, JsonElement> = linkedMapOf<String, JsonElement>("baseUpdatedAt" to JsonPrimitive(input.baseUpdatedAt)).also {
            if (copyPath) it["sourceBaseUpdatedAt"] = sourceBaseUpdatedAt?.let(::JsonPrimitive) ?: JsonNull
        }

        private fun pick(part: List<Pending>, prefix: String) = JsonArray(part.filter { it.key.startsWith(prefix) }.map { it.entry })

        private fun lists(local: List<Pending>, remote: List<Pending>): Map<String, JsonElement> = buildMap {
            put("copies", pick(local, "c:"))
            put("skipped", pick(local, "s:"))
            if (!copyPath) {
                put("federated", pick(remote, "f:"))
                put("suspend", pick(remote, "p:"))
            }
        }

        private suspend fun send(): JsonObject? {
            var local = copies.unsent(sent) + skipped.unsent(sent)
            var remote = federated.unsent(sent) + suspend.unsent(sent)
            // A chunk goes only while a list holds more than the bound, so each is full on that list and never empty.
            while (local.size > BATCH || remote.size > BATCH) {
                sendChunk(local.take(BATCH), remote.take(BATCH))
                local = local.drop(BATCH)
                remote = remote.drop(BATCH)
            }
            val body = envelope()
            body["patch"] = input.patch
            body.putAll(lists(local, remote))
            stageId?.let { body["stage"] = JsonObject(mapOf("id" to JsonPrimitive(it), "final" to JsonPrimitive(true))) }
            return api.shareSave(input.id, JsonObject(body))
        }

        private suspend fun sendChunk(local: List<Pending>, remote: List<Pending>) {
            val body = envelope()
            body.putAll(lists(local, remote))
            val stage = linkedMapOf<String, JsonElement>()
            stageId?.let { stage["id"] = JsonPrimitive(it) }
            stage["final"] = JsonPrimitive(false)
            body["stage"] = JsonObject(stage)
            val answer = api.shareSave(input.id, JsonObject(body))
            stageId = answer?.text("stageId") ?: throw ShareSaveException(UNKNOWN)
            for (pending in local + remote) sent.add(pending.key)
        }

        private fun dropFrom(staged: Staged, keep: (String) -> Boolean) {
            if (staged.drop(keep, sent)) restage = true
        }

        private fun incompleteOf(body: JsonObject): Required {
            val copiesBody = body["copies"] as? JsonObject
            val required = copiesBody?.get("required").list().mapNotNull { row ->
                val r = row as? JsonObject ?: return@mapNotNull null
                val secretId = r.text("secretId") ?: return@mapNotNull null
                RequiredCopy(secretId, (r["holder"] as? JsonPrimitive)?.content ?: "", r.text("targetUserId"))
            }
            val skipRefused = copiesBody?.get("skipRefused").list().mapNotNull { (it as? JsonPrimitive)?.takeIf { p -> p.isString }?.content?.ifEmpty { null } }.toSet()
            val shares = (body["federated"] as? JsonObject)?.get("required").list().mapNotNull { row ->
                val r = row as? JsonObject ?: return@mapNotNull null
                PartnerShare(r.text("shareId") ?: return@mapNotNull null, r.text("recipientCloudId") ?: return@mapNotNull null)
            }
            return Required(required, skipRefused, shares)
        }

        private suspend fun fill(body: JsonObject) {
            val required = incompleteOf(body)
            restage = false
            val copyIds = required.copies.map { it.secretId }.toSet()
            dropFrom(copies) { it in copyIds }
            dropFrom(skipped) { it in copyIds && it !in required.skipRefused }
            rereadRefused(required)
            val todo = required.copies.filter { it.secretId !in copies.entries && it.secretId !in skipped.entries }
            lookUpCertificates(todo)
            sealCopies(todo)
            if (!copyPath) fillPartners(required.shares)
            if (restage) {
                stageId = null
                sent = HashSet()
            }
        }

        private suspend fun rereadRefused(required: Required) {
            for (holder in required.copies.filter { it.secretId in required.skipRefused && it.secretId !in reread }) {
                reread.add(holder.secretId)
                if (holder.holder == "owner") {
                    ownerCertificate = readContext(input.id).ownerCertificate
                } else if (holder.targetUserId != null) {
                    certificates.remove(holder.targetUserId)
                }
            }
        }

        private suspend fun lookUpCertificates(todo: List<RequiredCopy>) {
            val lookups = todo.mapNotNull { copy -> copy.targetUserId?.takeIf { copy.holder != "owner" && it !in certificates } }.distinct()
            for (batch in lookups.chunked(BATCH)) {
                val answer = api.recipientCertificates(batch)
                for (userId in batch) certificates[userId] = null
                for (row in answer?.get("recipients").list()) {
                    val r = row as? JsonObject ?: continue
                    val userId = r.text("userId") ?: continue
                    val shareable = r.isTrue("shareable")
                    val certificate = r.text("certificate")
                    if (shareable && certificate != null) certificates[userId] = certificate
                }
            }
        }

        private fun certificateOf(holder: RequiredCopy): String? =
            if (holder.holder == "owner") ownerCertificate else holder.targetUserId?.let { certificates[it] }

        private fun sealCopies(todo: List<RequiredCopy>) {
            for (holder in todo) {
                val certificate = certificateOf(holder)
                if (!certificate.isNullOrEmpty()) {
                    copies.entries[holder.secretId] = JsonObject(mapOf("secretId" to JsonPrimitive(holder.secretId)) + sealSparse(keyOf(certificate)))
                } else {
                    skipped.entries[holder.secretId] = JsonObject(mapOf("secretId" to JsonPrimitive(holder.secretId), "reason" to JsonPrimitive("no_vault_key")))
                }
            }
        }

        private suspend fun fillPartners(required: List<PartnerShare>) {
            val shareIds = required.map { it.shareId }.toSet()
            dropFrom(federated) { it in shareIds }
            dropFrom(suspend) { it in shareIds }
            val todo = required.filter { it.shareId !in federated.entries && it.shareId !in suspend.entries }
            if (todo.isEmpty()) return
            val round = partnerRound()
            for (share in todo) fillPartner(share, round)
            if (round.tooOld.isNotEmpty()) throw ShareSaveException(PARTNER_UPGRADE_REQUIRED, round.tooOld, TOTP_PASSKEYS_FIELDS_SINCE)
            // Only shares the user agreed to leave out are suspended; naming every unreachable one lets one Save without them cover them all.
            if (round.unreachable.any { it.shareId !in input.withoutUnreachable }) throw ShareSaveException(PARTNER_UNREACHABLE, round.unreachable.toList())
            for (share in round.unreachable) suspend.entries[share.shareId] = suspension(share.shareId, PARTNER_UNREACHABLE)
        }

        private fun suspension(shareId: String, reason: String) = JsonObject(mapOf("shareId" to JsonPrimitive(shareId), "reason" to JsonPrimitive(reason)))

        /** A replaced or removed key or passkey reaches a partner that cannot store it as null; only a field the item did not hold stops the save. */
        private fun partnerRound(): PartnerRound {
            val value = whole ?: wholeValue().also { whole = it }
            val adds = PARTNER_OPTIONAL.filter { !input.plain[it].isNullOrEmpty() && input.opened?.shown?.get(it).isNullOrEmpty() }
            return PartnerRound(value, adds)
        }

        private suspend fun fillPartner(share: PartnerShare, round: PartnerRound) {
            val kept = partners.get(share)
            val outcome = if (kept != null) PartnerOutcome.Verified(kept) else lookUp(share)
            cloudIds[share.shareId] = share.recipientCloudId
            when (outcome) {
                PartnerOutcome.Unreachable -> round.unreachable.add(share)
                is PartnerOutcome.Suspend -> suspend.entries[share.shareId] = suspension(share.shareId, outcome.reason)
                is PartnerOutcome.Verified -> fillVerified(share, outcome.partner, kept != null, round)
            }
        }

        private fun fillVerified(share: PartnerShare, partner: VerifiedPartner, kept: Boolean, round: PartnerRound) {
            val key = try {
                RsaPublicKey.fromSpki(partner.certificate.spki)
            } catch (e: Exception) {
                throw ShareSaveException(BAD_CERTIFICATE)
            }
            if (!kept) partners.add(share, partner)
            if (round.adds.any { it !in partner.valueFields }) {
                round.tooOld.add(share)
            } else {
                val entry = mapOf("shareId" to JsonPrimitive(share.shareId), "certFingerprint" to JsonPrimitive(partner.certificate.fingerprint)) +
                    sealWhole(round.value, key, partner.valueFields)
                federated.entries[share.shareId] = JsonObject(entry)
            }
        }

        /** Looks the partner recipient up and verifies the answer against the share's own cloud id, not the lookup's echo of it. */
        private suspend fun lookUp(share: PartnerShare): PartnerOutcome {
            val answer = try {
                api.federatedRecipientCertificate(share.recipientCloudId)
            } catch (e: KeepiqApiException) {
                return lookupFailure(e)
            }
            // FederatedCertificateService::parseAnswer guarantees this shape, so another is this server's fault, and a suspension can't be undone.
            // An empty certificate is the partner's answer, so it is judged (malformed), not this server's fault.
            val certificate = answer?.string("certificate")
            val chain = (answer?.get("chain") as? JsonArray)?.map { (it as? JsonPrimitive)?.takeIf { p -> p.isString }?.content }
            val pin = answer?.text("partnerRootFingerprint")
            if (certificate == null || chain == null || chain.size != 2 || chain.any { it == null } || pin == null || !PIN.matches(pin)) {
                throw ShareSaveException(SERVER_ERROR)
            }
            // An older partner sends none, and stores none of those fields.
            val valueFields = answer?.get("valueFields").list().mapNotNull { (it as? JsonPrimitive)?.takeIf { p -> p.isString }?.content?.ifEmpty { null } }
            return try {
                PartnerOutcome.Verified(VerifiedPartner(FederatedCertificate.verify(certificate, chain.filterNotNull(), pin, share.recipientCloudId, now()), valueFields))
            } catch (e: FederatedCertificateException) {
                // Validity is judged by this phone's clock, which may be wrong, so it stops the save instead of suspending for good.
                if (e.reason == FederatedCertificateReason.NOT_VALID_NOW) throw ShareSaveException(PARTNER_CLOCK)
                PartnerOutcome.Suspend(e.reason.code)
            } catch (e: FederatedCertificateUnsupportedException) {
                throw ShareSaveException(BAD_CERTIFICATE)
            }
        }

        private fun lookupFailure(error: KeepiqApiException): PartnerOutcome {
            if (error.status == 429) throw ShareSaveException(PARTNER_LOOKUP_LIMIT)
            val message = messageOf(error)
            if (error.status == 502 && message == PARTNER_UNREACHABLE) return PartnerOutcome.Unreachable
            if (message != null && PARTNER_GONE[message] == error.status) return PartnerOutcome.Suspend(message)
            // This server failed, not the partner; its 409 is its own refusal, as a Nextcloud too old for partner sharing.
            if (error.status != 409) throw error
            throw ShareSaveException(if (message == FEDERATION_UNAVAILABLE) FEDERATION_UNAVAILABLE else SERVER_ERROR)
        }
    }
}
