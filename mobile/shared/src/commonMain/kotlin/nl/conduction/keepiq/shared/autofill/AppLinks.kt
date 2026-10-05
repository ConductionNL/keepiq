// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull

/**
 * An Android app as the autofill service sees it: its package name and the
 * SHA-256 fingerprints of the certificates it is signed with, upper-case hex
 * with colons, as Digital Asset Links writes them.
 */
data class AppIdentity(val packageName: String, val certFingerprints: Set<String>) {
    companion object {
        /** "14:6d:e9" and "146DE9" both read as "14:6D:E9". */
        fun normalizeFingerprint(text: String): String? {
            val hex = text.filter { it != ':' }.uppercase()
            if (hex.isEmpty() || hex.length % 2 != 0 || !hex.all { it in '0'..'9' || it in 'A'..'F' }) return null
            return hex.chunked(2).joinToString(":")
        }
    }
}

/**
 * The address an item holds when it belongs to an Android app rather than a
 * website: `androidapp://<package>#sha256_cert_fingerprints=<fingerprint>`.
 * The package name alone is the convention other password managers use; the
 * fingerprint is added so a look-alike app, with the same package name and
 * another signing certificate, never matches (mobile-system-autofill, "A
 * look-alike app gets nothing"). An `androidapp://` address without a
 * fingerprint matches no app.
 */
data class AppLink(val packageName: String, val certFingerprints: Set<String>) {
    /** Whether [app] is this app: the same package, signed with one of the recorded certificates. */
    fun matches(app: AppIdentity): Boolean =
        app.packageName == packageName && certFingerprints.isNotEmpty() && app.certFingerprints.any { it in certFingerprints }

    fun toUrl(): String = buildString {
        append(SCHEME).append(packageName)
        if (certFingerprints.isNotEmpty()) append('#').append(FRAGMENT).append(certFingerprints.sorted().joinToString(","))
    }

    companion object {
        const val SCHEME = "androidapp://"
        private const val FRAGMENT = "sha256_cert_fingerprints="
        private val PACKAGE = Regex("^[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+$")

        fun of(app: AppIdentity): AppLink = AppLink(app.packageName, app.certFingerprints)

        /** The link in an item address, or null for a website address. */
        fun parse(url: String?): AppLink? {
            val text = url?.trim() ?: return null
            if (!text.startsWith(SCHEME, ignoreCase = true)) return null
            val rest = text.substring(SCHEME.length)
            val packageName = rest.substringBefore('#').substringBefore('/').substringBefore('?')
            if (!PACKAGE.matches(packageName)) return null
            val fragment = rest.substringAfter('#', "")
            val prints = if (fragment.startsWith(FRAGMENT)) {
                fragment.substring(FRAGMENT.length).split(',').mapNotNull { AppIdentity.normalizeFingerprint(it.trim()) }.toSet()
            } else {
                emptySet()
            }
            return AppLink(packageName, prints)
        }
    }
}

/**
 * Digital Asset Links (https://developers.google.com/digital-asset-links):
 * a website lists the apps that may use its logins in
 * `/.well-known/assetlinks.json`, and an app names the websites it belongs
 * to in its `asset_statements` manifest entry. Keepiq gives an app a
 * website's logins only when both sides agree: the app names the site, and
 * the site lists the app's package and signing certificate with the
 * `delegate_permission/common.get_login_creds` relation.
 */
object AssetLinks {
    const val LOGIN_RELATION = "delegate_permission/common.get_login_creds"
    private val json = Json { ignoreUnknownKeys = true }

    /**
     * The websites an app's `asset_statements` resource names, as https
     * origins: statements whose target is a web site, and `include` entries,
     * whose site is the origin of the included file.
     */
    fun declaredSites(assetStatements: String): List<String> {
        val array = runCatching { json.parseToJsonElement(assetStatements) as? JsonArray }.getOrNull() ?: return emptyList()
        return array.mapNotNull { element ->
            val statement = element as? JsonObject ?: return@mapNotNull null
            val include = statement.text("include")
            val site = when {
                include != null -> originOf(include)
                else -> {
                    val target = statement["target"] as? JsonObject
                    if (target?.text("namespace") == "web") target.text("site")?.let { originOf(it) } else null
                }
            }
            site
        }.distinct()
    }

    /**
     * Whether a site's assetlinks.json lets [app] use its logins: a
     * statement with the login relation whose android_app target names the
     * package and one of its certificate fingerprints.
     */
    fun allowsLogins(assetLinksJson: String, app: AppIdentity): Boolean {
        val array = runCatching { json.parseToJsonElement(assetLinksJson) as? JsonArray }.getOrNull() ?: return false
        return array.any { element ->
            val statement = element as? JsonObject ?: return@any false
            val relations = (statement["relation"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull } ?: emptyList()
            val target = statement["target"] as? JsonObject ?: return@any false
            if (LOGIN_RELATION !in relations || target.text("namespace") != "android_app") return@any false
            if (target.text("package_name") != app.packageName) return@any false
            val prints = (target["sha256_cert_fingerprints"] as? JsonArray)
                ?.mapNotNull { (it as? JsonPrimitive)?.contentOrNull?.let { p -> AppIdentity.normalizeFingerprint(p) } } ?: emptyList()
            prints.any { it in app.certFingerprints }
        }
    }

    /** The statement file of a site. */
    fun statementUrl(site: String): String = site.trimEnd('/') + "/.well-known/assetlinks.json"

    /** "https://example.com:8443" from any https URL; null for another scheme. */
    fun originOf(url: String): String? {
        val text = url.trim()
        if (!text.startsWith("https://", ignoreCase = true)) return null
        val authority = text.substring(8).substringBefore('/').substringBefore('?').substringBefore('#')
        if (authority.isEmpty() || authority.contains('@')) return null
        if (UrlHost.hostname("https://$authority").isNullOrEmpty()) return null
        return "https://" + authority.lowercase()
    }

    private fun JsonObject.text(name: String): String? = (this[name] as? JsonPrimitive)?.contentOrNull
}
