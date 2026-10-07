// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import android.content.Context
import android.content.pm.Signature
import android.util.Base64
import android.util.Log
import androidx.credentials.provider.CallingAppInfo
import nl.conduction.keepiq.android.R
import nl.conduction.keepiq.android.autofill.AppIdentities
import nl.conduction.keepiq.shared.autofill.AppIdentity
import nl.conduction.keepiq.shared.autofill.AutofillTarget
import nl.conduction.keepiq.shared.autofill.SiteMatch
import nl.conduction.keepiq.shared.passkey.Passkeys
import java.security.MessageDigest

/**
 * Who asks Credential Manager for a credential, from what the system
 * verified (design D7, "The relying party comes from the operating
 * system"): the calling package and its signing certificates, and for a
 * browser the web origin it set, which counts only when the browser is on
 * the privileged allowlist with its certificate.
 */
sealed class CredentialCaller {
    abstract val identity: AppIdentity

    /** The origin the clientDataJSON names. */
    abstract val origin: String

    /** A browser on the allowlist, asking for [origin], a web origin. */
    data class Browser(override val identity: AppIdentity, override val origin: String) : CredentialCaller()

    /** An app, asking for itself: origin `android:apk-key-hash:<sha-256 of its certificate>`. */
    data class App(override val identity: AppIdentity, override val origin: String) : CredentialCaller()

    companion object {
        private const val TAG = "KeepiqPasskeys"

        /** The allowlist of browsers whose origin Keepiq believes (res/raw/privileged_browsers.json). */
        fun allowlist(context: Context): String =
            context.resources.openRawResource(R.raw.privileged_browsers).use { it.readBytes().toString(Charsets.UTF_8) }

        /**
         * The caller, or null when it cannot be trusted: a package that sets
         * an origin without being an allowlisted browser is refused outright.
         */
        fun of(context: Context, info: CallingAppInfo): CredentialCaller? {
            val signing = info.signingInfo
            val signatures: Array<Signature> = if (signing.hasMultipleSigners()) signing.apkContentsSigners else signing.signingCertificateHistory
            if (signatures.isEmpty()) return null
            val identity = AppIdentity(info.packageName, signatures.map { AppIdentities.fingerprint(it.toByteArray()) }.toSet())
            if (info.isOriginPopulated()) {
                val origin = try {
                    info.getOrigin(allowlist(context))
                } catch (e: IllegalStateException) {
                    Log.w(TAG, "${info.packageName} set an origin but is not an allowlisted browser")
                    return null
                } ?: return null
                return Browser(identity, origin.trimEnd('/'))
            }
            // The current certificate: the last of a rotation history, the first of several signers.
            val current = if (signing.hasMultipleSigners()) signatures.first() else signatures.last()
            val hash = MessageDigest.getInstance("SHA-256").digest(current.toByteArray())
            return App(identity, "android:apk-key-hash:" + Base64.encodeToString(hash, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP))
        }
    }

    /**
     * Whether this caller may use passkeys of [rpId]. A browser: the rpId
     * is its origin's host or a parent of it (the extension's rp.js rule).
     * An app: the site at [rpId] lists the app in its assetlinks.json
     * (Digital Asset Links), which costs a network request; call it off the
     * main thread.
     */
    fun mayUse(rpId: String, identities: AppIdentities): Boolean = when (this) {
        is Browser -> Passkeys.rpIdAllowed(rpId, origin)
        is App -> rpId.isNotEmpty() && identities.siteAllows("https://" + rpId.lowercase(), identity)
    }

    /** Where this caller's passwords come from: the site of a browser's origin, or the app with its verified sites. */
    fun passwordTarget(identities: AppIdentities): AutofillTarget = when (this) {
        is Browser -> AutofillTarget.Web(SiteMatch.hostOf(origin))
        is App -> AutofillTarget.App(identity, identities.verifiedHosts(identity))
    }
}
