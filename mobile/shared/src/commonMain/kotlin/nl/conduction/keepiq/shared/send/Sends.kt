// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.send

import kotlinx.coroutines.CancellationException
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.booleanOrNull
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.longOrNull
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.SendCrypto
import nl.conduction.keepiq.shared.vault.WriteProblem
import nl.conduction.keepiq.shared.vault.WriteProblemKind

/** An expiry choice (browser-extension/src/lib/send-form.js EXPIRY_PRESETS); [seconds] is null for Custom. */
enum class SendExpiry(val seconds: Long?) {
    HOUR(3_600), DAY(86_400), TWO_DAYS(2 * 86_400), THREE_DAYS(3 * 86_400),
    SEVEN_DAYS(7 * 86_400), THIRTY_DAYS(30 * 86_400), CUSTOM(null),
}

/** Why a Send form cannot be sent. */
enum class SendFormProblem { NOTHING_TO_SEND, CUSTOM_HOURS, CUSTOM_HOURS_TOO_MANY, VIEWS_OUT_OF_RANGE, PASSWORD_NOT_AVAILABLE }

/** What a Send carries: free text, or a user name and password as two lines. */
enum class SendPayloadType(val wire: String) { TEXT("text"), CREDENTIAL("credential") }

/** One of the account's own sends: metadata only, never the payload. */
data class SendSummary(
    val id: String,
    val payloadType: String,
    val createdAt: String?,
    val expiresAt: String?,
    val viewCount: Long,
    val maxViews: Long,
    val hasPassword: Boolean,
)

/** A created Send: the link to share. Without a password the key rides the fragment. */
data class CreatedSend(val id: String?, val link: String, val hasPassword: Boolean) {
    override fun toString(): String = "CreatedSend(id=$id, hasPassword=$hasPassword)"
}

sealed class SendResult<out T> {
    data class Done<T>(val value: T) : SendResult<T>()

    data class Problem(val form: SendFormProblem? = null, val write: WriteProblem? = null) : SendResult<Nothing>()

    /** The value when done, else null. For Swift, which cannot match a generic subclass. */
    val valueOrNull: T? get() = (this as? Done<T>)?.value

    /** The problem when not done, else null. */
    val problemOrNull: Problem? get() = this as? Problem
}

/**
 * The Send form's rules (browser-extension/src/lib/send-form.js) and the
 * create, list and delete calls, encrypted on the device as the web app
 * and the extension do (src/store/modules/ephemeralSend.js createSend,
 * the extension's send-create): a fresh AES-256-GCM key, the payload sealed
 * under it, and with a password the key wrapped under Argon2id of it.
 */
object SendForm {
    /** EphemeralSendService::MAX_VIEWS_CAP. */
    const val MAX_VIEWS_CAP = 100

    /** The longest custom expiry, in hours (30 days). */
    const val MAX_CUSTOM_HOURS = 720

    /** expirySeconds, or the problem to show under the field. */
    fun expirySeconds(expiry: SendExpiry, customHours: String?): Pair<Long?, SendFormProblem?> {
        expiry.seconds?.let { return it to null }
        val hours = customHours?.trim()?.toLongOrNull() ?: return null to SendFormProblem.CUSTOM_HOURS
        if (hours < 1) return null to SendFormProblem.CUSTOM_HOURS
        if (hours > MAX_CUSTOM_HOURS) return null to SendFormProblem.CUSTOM_HOURS_TOO_MANY
        return hours * 3_600 to null
    }

    /** maxViewsFrom: 1 to 100. */
    fun maxViews(value: String?): Int? = value?.trim()?.toIntOrNull()?.takeIf { it in 1..MAX_VIEWS_CAP }

    /** credentialPayload: exactly two lines, as the recipient page shows the payload as text. */
    fun credentialPayload(username: String, password: String): String = "Username: $username\nPassword: $password"

    /**
     * expiresIn, as numbers the screens put into words: minutes left (0 or
     * less: expired), or null when the time cannot be read.
     */
    fun minutesLeft(expiresAtMillis: Long?, nowMillis: Long): Long? =
        expiresAtMillis?.let { (it - nowMillis + 30_000) / 60_000 }
}

/** Create, list and delete the account's sends. */
class SendService(private val api: KeepiqApi) {
    /**
     * Creates a Send. A password needs Argon2id, which iOS does not have yet
     * (task 1.3.1); [passwordAvailable] says whether this device can wrap.
     */
    suspend fun create(
        payloadType: SendPayloadType,
        plaintext: String,
        maxViews: String?,
        expiry: SendExpiry,
        customHours: String?,
        password: String,
        passwordAvailable: Boolean,
    ): SendResult<CreatedSend> {
        if (plaintext.trim().isEmpty()) return SendResult.Problem(SendFormProblem.NOTHING_TO_SEND)
        val views = SendForm.maxViews(maxViews) ?: return SendResult.Problem(SendFormProblem.VIEWS_OUT_OF_RANGE)
        val (ttl, ttlProblem) = SendForm.expirySeconds(expiry, customHours)
        if (ttlProblem != null || ttl == null) return SendResult.Problem(ttlProblem ?: SendFormProblem.CUSTOM_HOURS)
        if (password.isNotEmpty() && !passwordAvailable) return SendResult.Problem(SendFormProblem.PASSWORD_NOT_AVAILABLE)

        val sealed = SendCrypto.sealPayload(plaintext)
        val body = LinkedHashMap<String, JsonElement>()
        body["encryptedPayload"] = JsonPrimitive(sealed.encryptedPayload)
        body["payloadType"] = JsonPrimitive(payloadType.wire)
        body["maxViews"] = JsonPrimitive(views)
        body["ttlSeconds"] = JsonPrimitive(ttl)
        body["hasPassword"] = JsonPrimitive(password.isNotEmpty())
        if (password.isNotEmpty()) {
            val wrap = SendCrypto.wrapKey(sealed.rawKey, password)
            body["wrappedKey"] = JsonPrimitive(wrap.wrappedKey)
            body["argon2idSalt"] = JsonPrimitive(wrap.argon2idSalt)
        }
        return try {
            val send = api.createSend(JsonObject(body))
            val token = (send?.get("token") as? JsonPrimitive)?.contentOrNull
                ?: return SendResult.Problem(write = WriteProblem(WriteProblemKind.FAILED))
            val link = SendCrypto.sendLink(api.publicBase, token, if (password.isNotEmpty()) null else sealed.rawKey)
            SendResult.Done(CreatedSend((send["id"] as? JsonPrimitive)?.contentOrNull, link, password.isNotEmpty()))
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            SendResult.Problem(write = WriteProblem.from(e).offlineWhenUnreachable())
        }
    }

    suspend fun list(): SendResult<List<SendSummary>> = try {
        SendResult.Done(
            api.listSends().mapNotNull { s ->
                val id = s.text("id") ?: return@mapNotNull null
                SendSummary(
                    id = id,
                    payloadType = s.text("payloadType") ?: "text",
                    createdAt = s.text("createdAt"),
                    expiresAt = s.text("expiresAt"),
                    viewCount = (s["viewCount"] as? JsonPrimitive)?.longOrNull ?: 0,
                    maxViews = (s["maxViews"] as? JsonPrimitive)?.longOrNull ?: 1,
                    hasPassword = (s["hasPassword"] as? JsonPrimitive)?.booleanOrNull == true,
                )
            },
        )
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        SendResult.Problem(write = WriteProblem.from(e).offlineWhenUnreachable())
    }

    /** Ends a send: its link stops working. */
    suspend fun delete(id: String): SendResult<Unit> = try {
        api.revokeSend(id)
        SendResult.Done(Unit)
    } catch (e: CancellationException) {
        throw e
    } catch (e: Exception) {
        SendResult.Problem(write = WriteProblem.from(e).offlineWhenUnreachable())
    }

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull
}

/** No send while the server is away (extension-send-details "no send while offline"). */
internal fun WriteProblem.offlineWhenUnreachable(): WriteProblem =
    if (kind == WriteProblemKind.UNREACHABLE) WriteProblem(WriteProblemKind.OFFLINE) else this
