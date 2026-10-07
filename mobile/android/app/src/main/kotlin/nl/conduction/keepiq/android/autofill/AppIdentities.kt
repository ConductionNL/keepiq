// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.content.Context
import android.content.pm.PackageManager
import android.content.pm.Signature
import android.util.Log
import nl.conduction.keepiq.shared.account.SecureStorage
import nl.conduction.keepiq.shared.autofill.AppIdentity
import nl.conduction.keepiq.shared.autofill.AssetLinks
import nl.conduction.keepiq.shared.autofill.AutofillTarget
import nl.conduction.keepiq.shared.autofill.SiteMatch
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest

/**
 * Who asks for autofill (task 4.1). An app is its package name and the
 * SHA-256 of its signing certificates, read from the package manager, never
 * from the form. A website is the domain a browser reports, trusted only
 * from a browser: an app that shows a page in its own WebView could report
 * any domain, so for it Keepiq uses the app's own identity instead.
 *
 * A browser is one of the [BROWSERS] package names. Its certificate is
 * recorded the first time it asks, and a later request from that package
 * with another certificate is treated as an app, not as a browser.
 */
class AppIdentities(private val context: Context, private val storage: SecureStorage) {
    private val prefs = context.getSharedPreferences("keepiq.assetlinks", Context.MODE_PRIVATE)

    fun identity(packageName: String): AppIdentity? = try {
        val pm = context.packageManager
        val info = pm.getPackageInfo(packageName, PackageManager.GET_SIGNING_CERTIFICATES)
        val signing = info.signingInfo ?: return null
        val signatures: Array<Signature> = if (signing.hasMultipleSigners()) signing.apkContentsSigners else signing.signingCertificateHistory
        AppIdentity(packageName, signatures.map { fingerprint(it.toByteArray()) }.toSet())
    } catch (e: PackageManager.NameNotFoundException) {
        null
    }

    fun label(packageName: String): String? = runCatching {
        val pm = context.packageManager
        pm.getApplicationLabel(pm.getApplicationInfo(packageName, 0)).toString()
    }.getOrNull()

    /**
     * The target of a form: the reported website when a trusted browser
     * asks, else the app, with the websites Digital Asset Links verified for
     * it. Runs network requests; call it off the main thread.
     */
    fun target(form: ParsedForm): AutofillTarget? {
        val app = identity(form.packageName) ?: return null
        val domain = form.webDomain
        if (domain != null && isTrustedBrowser(app)) return AutofillTarget.Web(domain)
        return AutofillTarget.App(app, verifiedHosts(app))
    }

    fun isTrustedBrowser(app: AppIdentity): Boolean {
        if (app.packageName !in BROWSERS && app.packageName !in e2eBrowsers()) return false
        val key = "autofill:browser:${app.packageName}"
        val known = storage.read(key)?.split(',')?.toSet()
        if (known == null) {
            storage.write(key, app.certFingerprints.sorted().joinToString(","))
            return true
        }
        if (app.certFingerprints.any { it in known }) return true
        Log.w(TAG, "${app.packageName} is signed with another certificate than before; not trusted as a browser")
        return false
    }

    /** The hosts of the websites the app names in asset_statements that list the app back. */
    fun verifiedHosts(app: AppIdentity): List<String> =
        declaredSites(app.packageName).filter { site -> siteAllows(site, app) }.mapNotNull { site ->
            SiteMatch.hostOf(site).takeIf { it.isNotEmpty() }
        }

    private fun declaredSites(packageName: String): List<String> = try {
        val pm = context.packageManager
        val info = pm.getApplicationInfo(packageName, PackageManager.GET_META_DATA)
        val res = info.metaData?.getInt("asset_statements", 0) ?: 0
        if (res == 0) {
            emptyList()
        } else {
            AssetLinks.declaredSites(pm.getResourcesForApplication(info).getString(res))
        }
    } catch (e: Exception) {
        emptyList()
    }

    /**
     * Whether [site] lists [app] in its assetlinks.json with the login
     * relation, cached for a day. The passkey provider asks this for the
     * rpId an app names (task 5.1).
     */
    fun siteAllows(site: String, app: AppIdentity): Boolean {
        val cacheKey = site + "|" + app.packageName + "|" + app.certFingerprints.sorted().joinToString(",")
        val cached = prefs.getString(cacheKey, null)
        val now = System.currentTimeMillis()
        if (cached != null) {
            val (at, ok) = cached.split(':').let { it[0].toLong() to (it[1] == "1") }
            if (now - at < CACHE_MILLIS) return ok
        }
        val ok = try {
            AssetLinks.allowsLogins(fetch(AssetLinks.statementUrl(site)), app)
        } catch (e: Exception) {
            Log.i(TAG, "assetlinks of $site could not be read: ${e.javaClass.simpleName}")
            // Unreachable: use a recent answer, else nothing.
            return cached?.endsWith(":1") == true && now - cached.substringBefore(':').toLong() < STALE_MILLIS
        }
        prefs.edit().putString(cacheKey, "$now:${if (ok) 1 else 0}").apply()
        return ok
    }

    /** The statement file: https only, no redirects, at most 256 kB. */
    private fun fetch(url: String): String {
        val connection = URL(url).openConnection() as HttpURLConnection
        connection.instanceFollowRedirects = false
        connection.connectTimeout = 2_500
        connection.readTimeout = 2_500
        try {
            if (connection.responseCode != 200) throw IllegalStateException("status ${connection.responseCode}")
            val bytes = connection.inputStream.use { it.readNBytesCompat(256 * 1024) }
            return String(bytes, Charsets.UTF_8)
        } finally {
            connection.disconnect()
        }
    }

    private fun java.io.InputStream.readNBytesCompat(limit: Int): ByteArray {
        val out = java.io.ByteArrayOutputStream()
        val buffer = ByteArray(8192)
        while (out.size() < limit) {
            val n = read(buffer)
            if (n < 0) break
            out.write(buffer, 0, minOf(n, limit - out.size()))
        }
        return out.toByteArray()
    }

    /** Extra browsers of the e2e build only (src/e2e/AndroidManifest.xml); none in debug or release. */
    private fun e2eBrowsers(): Set<String> = runCatching {
        context.packageManager.getApplicationInfo(context.packageName, PackageManager.GET_META_DATA)
            .metaData?.getString(E2E_BROWSERS)?.split(',')?.map { it.trim() }?.toSet()
    }.getOrNull() ?: emptySet()

    companion object {
        private const val TAG = "KeepiqAutofill"
        private const val CACHE_MILLIS = 24L * 3600_000L
        private const val STALE_MILLIS = 7L * 24 * 3600_000L
        const val E2E_BROWSERS = "nl.conduction.keepiq.e2e.BROWSERS"

        /** Browsers that report the web domain to an autofill service (design D6), and their common forks. */
        val BROWSERS = setOf(
            "com.android.chrome", "com.chrome.beta", "com.chrome.dev", "com.chrome.canary",
            "org.chromium.chrome", "org.mozilla.firefox", "org.mozilla.firefox_beta", "org.mozilla.fenix",
            "org.mozilla.fennec_fdroid", "us.spotco.fennec_dos", "org.ironfoxoss.ironfox", "com.brave.browser",
            "com.microsoft.emmx", "com.sec.android.app.sbrowser", "com.vivaldi.browser", "com.duckduckgo.mobile.android",
            "org.torproject.torbrowser", "com.kiwibrowser.browser", "org.bromite.bromite", "org.cromite.cromite",
        )

        fun fingerprint(der: ByteArray): String =
            MessageDigest.getInstance("SHA-256").digest(der).joinToString(":") { "%02X".format(it) }
    }
}
