// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

/** What a matcher needs of an item: its plaintext name and address, never a decrypted value. */
interface Matchable {
    val id: String
    val name: String
    val url: String?
    val useOnly: Boolean
    val lastUsedAt: String? get() = null
}

/** An item with the score it matched with; higher is better. */
data class Scored<T : Matchable>(val item: T, val score: Int)

/**
 * Site matching, the same as the browser extension's
 * (browser-extension/src/lib/match.js and useOnly.js): a registrable domain
 * approximated with a small public-suffix set, an exact host first, then the
 * same site, then the item name as a fallback. tests/vectors/autofill holds
 * the cases both run.
 *
 * Matching only narrows what the user picks from. Nothing is filled without
 * the user choosing an entry.
 */
object SiteMatch {
    /** MULTI_LABEL_SUFFIXES in match.js. */
    val MULTI_LABEL_SUFFIXES: Set<String> = setOf(
        "co.uk", "org.uk", "gov.uk", "ac.uk", "co.jp", "or.jp", "ne.jp", "com.au", "net.au", "org.au",
        "com.br", "co.nz", "co.za", "com.mx", "co.in", "gov.nl",
    )

    private val SCHEME = Regex("^[a-z]+://", RegexOption.IGNORE_CASE)

    /** hostOf: the host name of a URL or bare host, lower case; "" when it cannot be read. */
    fun hostOf(input: String?): String {
        if (input.isNullOrEmpty()) return ""
        val value = input.trim()
        val withScheme = if (SCHEME.containsMatchIn(value)) value else "https://$value"
        return UrlHost.hostname(withScheme)?.lowercase() ?: ""
    }

    /** isPublicSuffix: a single label, or one of the multi-label suffixes. */
    fun isPublicSuffix(host: String?): Boolean {
        val h = hostOf(host)
        return h != "" && (h.indexOf('.') == -1 || h in MULTI_LABEL_SUFFIXES)
    }

    /** registrableDomain: the eTLD+1 approximation. */
    fun registrableDomain(host: String?): String {
        val h = hostOf(host)
        if (h.isEmpty() || h.indexOf('.') == -1) return h
        val parts = h.split('.')
        val lastTwo = parts.takeLast(2).joinToString(".")
        val lastThree = parts.takeLast(3).joinToString(".")
        if (parts.size >= 3 && lastTwo in MULTI_LABEL_SUFFIXES) return lastThree
        return lastTwo
    }

    /** matchScore: 100 for the exact host, 80 for the same site, 40 or 20 for the name. */
    fun matchScore(name: String?, url: String?, targetHost: String?): Int {
        val target = hostOf(targetHost)
        if (target.isEmpty()) return 0
        val targetReg = registrableDomain(target)
        val secretHost = hostOf(url)
        if (secretHost.isNotEmpty()) {
            if (secretHost == target) return 100
            if (registrableDomain(secretHost) == targetReg && targetReg.isNotEmpty()) return 80
        }
        val lowerName = (name ?: "").lowercase()
        if (targetReg.isNotEmpty() && lowerName.contains(targetReg)) return 40
        val label = targetReg.split('.')[0]
        if (label.isNotEmpty() && label.length >= 3 && lowerName.contains(label)) return 20
        return 0
    }

    /** matchSecrets: the items with a positive score, best first, then the one used last. */
    fun <T : Matchable> matchSecrets(items: List<T>, targetHost: String?): List<Scored<T>> =
        items.map { Scored(it, matchScore(it.name, it.url, targetHost)) }
            .filter { it.score > 0 }
            .sortedWith { a, b ->
                val byScore = b.score - a.score
                if (byScore != 0) byScore else (b.item.lastUsedAt ?: "").compareTo(a.item.lastUsedAt ?: "")
            }

    /** allowedOnHost (useOnly.js): a use-only item fills only on its own registrable domain. */
    fun allowedOnHost(item: Matchable, host: String?): Boolean {
        if (!item.useOnly) return true
        val own = registrableDomain(hostOf(item.url ?: ""))
        return own != "" && own == registrableDomain(host)
    }

    /** filterForHost (useOnly.js). */
    fun <T : Matchable> filterForHost(items: List<Scored<T>>, host: String?): List<Scored<T>> =
        items.filter { allowedOnHost(it.item, host) }

    /** blocksSavePrompt (useOnly.js): a use-only item for this site means no save or update offer. */
    fun blocksSavePrompt(items: List<Matchable>, host: String?): Boolean =
        items.any { it.useOnly && allowedOnHost(it, host) }
}

/** What a submitted login means (browser-extension/src/lib/capture.js classifyCapture). */
sealed class SaveOffer {
    data object Save : SaveOffer()

    data class Update(val id: String, val name: String) : SaveOffer()

    /** Nothing to offer: the same login is stored, or several stored logins could be meant. */
    data object None : SaveOffer()
}

/** The decrypted login name and password of a stored item, for [Capture.classify]. */
data class PlainLogin(val login: String, val secret: String) {
    override fun toString(): String = "PlainLogin(…)"
}

/**
 * The extension's capture rules (capture.js and useOnly.js) on the device:
 * which stored logins a submitted one belongs to.
 */
object Capture {
    /**
     * classifyCapture: update when one stored login for the site has this
     * login name and another password, nothing when it has this password or
     * several have this login name, else save. Only same-site matches
     * (score 80 or more) count. [decrypt] returns null for an item it
     * cannot open, which is skipped.
     */
    fun <T : Matchable> classify(host: String, login: String, secret: String, items: List<T>, decrypt: (T) -> PlainLogin?): SaveOffer {
        val target = SiteMatch.hostOf(host)
        return classifyCandidates(SiteMatch.matchSecrets(items, target).filter { it.score >= 80 }.map { it.item }, login, secret, decrypt)
    }

    /** The decision of [classify] over candidates already narrowed to the site or app. */
    fun <T : Matchable> classifyCandidates(candidates: List<T>, login: String, secret: String, decrypt: (T) -> PlainLogin?): SaveOffer {
        val sameLogin = ArrayList<T>()
        for (item in candidates) {
            val plain = runCatching { decrypt(item) }.getOrNull() ?: continue
            if (plain.login != login) continue
            if (plain.secret == secret) return SaveOffer.None
            sameLogin.add(item)
        }
        return when {
            sameLogin.size == 1 -> SaveOffer.Update(sameLogin[0].id, sameLogin[0].name)
            sameLogin.size > 1 -> SaveOffer.None
            else -> SaveOffer.Save
        }
    }
}
