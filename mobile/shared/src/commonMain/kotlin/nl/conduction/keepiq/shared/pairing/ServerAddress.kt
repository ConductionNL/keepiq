// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.pairing

import io.ktor.http.URLProtocol
import io.ktor.http.Url

/** The address the user typed cannot be used. The message says why, in words the user can act on. */
class ServerAddressException(message: String) : Exception(message)

/**
 * Cleans up a server address as typed or pasted, as the extension does
 * (browser-extension/src/lib/server-url.js normalizeServerUrl): a missing
 * scheme becomes https, a pasted Nextcloud page is cut back to the server
 * folder, and the trailing slash goes. Unlike the extension, plain http is
 * refused for every host, local ones too (design D3): a phone has no local
 * Nextcloud to pair with.
 */
object ServerAddress {
    // Where a pasted Nextcloud page address stops being the server address.
    private val pagePath = Regex("/(index\\.php|apps|login|ocs|remote\\.php|settings|s)(/|$)")
    private val scheme = Regex("^[a-zA-Z][a-zA-Z0-9+.-]*://")

    /** @throws ServerAddressException when the address is empty, malformed, carries credentials or is not https. */
    @Throws(ServerAddressException::class)
    fun normalize(raw: String): String {
        var text = raw.trim()
        if (text.isEmpty()) throw ServerAddressException("Enter the address of your Nextcloud.")
        if (!scheme.containsMatchIn(text)) text = "https://$text"
        val url = runCatching { Url(text) }.getOrNull()
            ?: throw ServerAddressException("This is not a valid address.")
        if (url.protocol != URLProtocol.HTTPS && url.protocol != URLProtocol.HTTP) {
            throw ServerAddressException("This is not a valid address.")
        }
        if (url.host.isBlank() || text.substringAfter("://").startsWith("/")) {
            throw ServerAddressException("This is not a valid address.")
        }
        if (url.user != null || url.password != null) {
            throw ServerAddressException("Leave the user name and password out of the address.")
        }
        if (url.protocol != URLProtocol.HTTPS) {
            throw ServerAddressException("Keepiq needs an https address, so your app password is never sent in clear.")
        }
        val path = url.encodedPath
        val cut = pagePath.find(path)?.range?.first ?: -1
        val folder = (if (cut == -1) path else path.substring(0, cut)).trimEnd('/')
        val port = if (url.specifiedPort == 0 || url.specifiedPort == 443) "" else ":${url.specifiedPort}"
        return "https://${url.host.lowercase()}$port$folder"
    }

    /** The host and port of an https address, for comparing two addresses. */
    internal fun origin(address: String): String? {
        val url = runCatching { Url(address) }.getOrNull() ?: return null
        if (url.protocol != URLProtocol.HTTPS) return null
        return "${url.host.lowercase()}:${url.port}"
    }
}
