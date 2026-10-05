// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import nl.conduction.keepiq.shared.unlock.UnlockedVault
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import nl.conduction.keepiq.shared.vault.VaultKeys

/** One entry for ASCredentialIdentityStore: a site, a user name and the record that finds the item again. */
data class PasswordIdentity(val host: String, val user: String, val recordIdentifier: String)

/**
 * The iOS side of the autofill index (task 4.4). The AutoFill extension
 * runs with a small memory limit, so it never loads the whole vault: the
 * app writes one small file per site (registrable domain), and the
 * extension reads only the files of the sites it is asked about
 * (design, "iOS extension memory").
 */
object AutofillSites {
    /** The index split per site: site key to the JSON of its entries. App-linked items are Android only. */
    fun split(index: AutofillIndex): Map<String, String> =
        index.entries
            .filter { AppLink.parse(it.url) == null }
            .groupBy { siteKey(it.url ?: "") }
            .filterKeys { it.isNotEmpty() }
            .mapValues { (_, entries) -> AutofillIndex(entries).toJson() }

    /** The file a service identifier (a domain or a URL) is in. */
    fun siteKey(serviceIdentifier: String): String = SiteMatch.registrableDomain(SiteMatch.hostOf(serviceIdentifier))

    /** The host a service identifier names, to match on. */
    fun hostOf(serviceIdentifier: String): String = SiteMatch.hostOf(serviceIdentifier)

    /** The logins of one site file that match [serviceIdentifier], best first. */
    fun matches(siteJson: String, serviceIdentifier: String): List<AutofillEntry> =
        AutofillIndex.fromJson(siteJson).candidates(AutofillTarget.Web(hostOf(serviceIdentifier)), totp = false)

    /** The authenticator items of one site file for [serviceIdentifier]. */
    fun codeItems(siteJson: String, serviceIdentifier: String): List<AutofillEntry> =
        AutofillIndex.fromJson(siteJson).candidates(AutofillTarget.Web(hostOf(serviceIdentifier)), totp = true)

    /** Logins of one site file whose name or address holds [query] ("Pick another login"). */
    fun search(siteJson: String, query: String): List<AutofillEntry> {
        val needle = query.trim().lowercase()
        return AutofillIndex.fromJson(siteJson).entries.filter { e ->
            e.isLogin && (needle.isEmpty() || e.name.lowercase().contains(needle) || (e.url ?: "").lowercase().contains(needle))
        }
    }

    /**
     * What ASCredentialIdentityStore gets: the site and the user name of
     * each login, never a password. Decrypts the user names only.
     */
    fun identities(accountId: String, index: AutofillIndex, keys: VaultKeys): List<PasswordIdentity> =
        index.entries.filter { it.isLogin && AppLink.parse(it.url) == null }.mapNotNull { e ->
            val host = SiteMatch.hostOf(e.url ?: "").takeIf { it.isNotEmpty() } ?: return@mapNotNull null
            val user = runCatching { keys.decryptField(e.login) }.getOrNull() ?: return@mapNotNull null
            PasswordIdentity(host, user, recordIdentifier(accountId, e.id))
        }

    fun recordIdentifier(accountId: String, itemId: String): String = "$accountId|$itemId"

    /** The item id of a record identifier, or null for another account's record. */
    fun itemId(recordIdentifier: String, accountId: String): String? =
        recordIdentifier.split('|').takeIf { it.size == 2 && it[0] == accountId }?.get(1)

    /** The keys of an unlocked vault, for Swift. */
    @Throws(Exception::class)
    fun keysOf(vault: UnlockedVault): VaultKeys =
        RsaVaultKeys.fromPem(vault.privateKeyPem, vault.certificate ?: throw IllegalStateException("The vault has no certificate"), vault.suiteId, vault.unlockKeyEpoch)

    /** Decrypts one field, for Swift. */
    @Throws(Exception::class)
    fun decrypt(keys: VaultKeys, ciphertext: String?): String = keys.decryptField(ciphertext)

    /** The current code of an authenticator entry, or null. */
    fun code(keys: VaultKeys, entry: AutofillEntry, nowMillis: Long): String? = runCatching {
        nl.conduction.keepiq.shared.crypto.Totp.generate(nl.conduction.keepiq.shared.crypto.Totp.parse(keys.decryptField(entry.key)), nowMillis)
    }.getOrNull()
}
