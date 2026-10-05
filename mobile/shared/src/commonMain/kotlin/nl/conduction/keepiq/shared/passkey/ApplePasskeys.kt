// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.passkey

import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.autofill.AutofillIndex
import nl.conduction.keepiq.shared.autofill.AutofillSites
import nl.conduction.keepiq.shared.crypto.ClientData
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.vault.VaultKeys

/**
 * One passkey for ASCredentialIdentityStore (design D7): the rpId, the user
 * name, the credential id and the user handle, never the key. Bytes are
 * standard base64, which Swift's Data reads and writes directly.
 */
data class PasskeyIdentity(
    val rpId: String,
    val userName: String,
    val credentialId: String,
    val userHandle: String,
    val recordIdentifier: String,
) {
    override fun toString(): String = "PasskeyIdentity(rpId=$rpId)"
}

/** What ASPasskeyAssertionCredential needs, in standard base64. */
class AppleAssertion(val userHandle: String, val signature: String, val authenticatorData: String, val credentialId: String)

/** What ASPasskeyRegistrationCredential needs, in standard base64. */
class AppleRegistration(val credentialId: String, val attestationObject: String, val userName: String)

/**
 * The iOS AutoFill extension's passkey calls (task 5.2), with bytes as
 * base64 so Swift never handles a Kotlin byte array. iOS always hands the
 * extension the hash of a clientDataJSON it built for a verified origin,
 * and the rpId the system checked against the app's associated domains or
 * the page, so both arrive here already verified.
 */
object ApplePasskeys {
    /** The passkeys of the index, for the identity store. Decrypts each passkey item once. */
    fun identities(accountId: String, index: AutofillIndex, keys: VaultKeys): List<PasskeyIdentity> =
        index.entries.filter { it.isPasskey }.mapNotNull { e ->
            val record = runCatching { PasskeyCredential.parse(keys.decryptField(e.key)) { "" } }.getOrNull() ?: return@mapNotNull null
            PasskeyIdentity(
                rpId = record.rpId,
                userName = record.userName.ifEmpty { record.userDisplayName }.ifEmpty { e.name },
                credentialId = base64(Encoding.fromBase64Url(record.credentialId)),
                userHandle = record.userHandle.takeIf { it.isNotEmpty() }?.let { base64(Encoding.fromBase64Url(it)) } ?: "",
                recordIdentifier = AutofillSites.recordIdentifier(accountId, e.id),
            )
        }

    /** The passkeys for [rpId] in one site file; [allowed] narrows them to those credential ids (base64). */
    fun choices(siteJson: String, rpId: String, keys: VaultKeys, allowed: List<String>): List<PasskeyChoice> =
        Passkeys.candidates(
            AutofillIndex.fromJson(siteJson),
            rpId,
            keys,
            allowed.mapNotNull { runCatching { Encoding.toBase64Url(Encoding.fromBase64(it)) }.getOrNull() },
        )

    /** The site file a passkey for [rpId] is in. */
    fun siteKey(rpId: String): String = AutofillSites.siteKey(rpId)

    @Throws(Exception::class)
    suspend fun assert(api: KeepiqApi, keys: VaultKeys, choice: PasskeyChoice, clientDataHash: String, rpId: String): AppleAssertion {
        val a = Passkeys.sign(api, keys, choice, ClientData.hashed(Encoding.fromBase64(clientDataHash)), rpId)
        return AppleAssertion(
            userHandle = a.userHandle?.let { base64(it) } ?: "",
            signature = base64(a.signature),
            authenticatorData = base64(a.authenticatorData),
            credentialId = base64(a.rawId),
        )
    }

    @Throws(Exception::class)
    suspend fun register(
        api: KeepiqApi,
        keys: VaultKeys,
        siteJson: String,
        rpId: String,
        userName: String,
        userHandle: String,
        clientDataHash: String,
        algorithms: List<Long>,
        nowMillis: Long,
    ): AppleRegistration {
        val request = CreateRequest(
            rpId = rpId,
            rpName = null,
            userName = userName,
            userDisplayName = userName,
            userHandle = userHandle.takeIf { it.isNotEmpty() }?.let { Encoding.fromBase64(it) },
            algorithms = algorithms,
            excludeCredentialIds = emptyList(),
        )
        val r = Passkeys.create(api, keys, AutofillIndex.fromJson(siteJson), request, ClientData.hashed(Encoding.fromBase64(clientDataHash)), nowMillis)
        return AppleRegistration(base64(r.credentialId), base64(r.attestationObject), userName)
    }

    private fun base64(bytes: ByteArray): String = Encoding.toBase64(bytes)
}
