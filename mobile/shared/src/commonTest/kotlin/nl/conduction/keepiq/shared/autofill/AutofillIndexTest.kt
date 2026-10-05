// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import nl.conduction.keepiq.shared.account.InMemorySecureStorage
import nl.conduction.keepiq.shared.sync.LockReason
import nl.conduction.keepiq.shared.vault.SecretType
import nl.conduction.keepiq.shared.vault.VaultKeys
import nl.conduction.keepiq.shared.vault.VaultRow
import nl.conduction.keepiq.shared.vault.VaultState
import kotlin.test.AfterTest
import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertFalse
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** The autofill index (tasks 4.1, 4.2 and 4.6), app links and Digital Asset Links. */
class AutofillIndexTest {
    private val bankPrint = "14:6D:E9:83:C5:73:06:50:D8:EE:B9:95:2F:34:FC:64:16:A0:83:42:E6:1D:BE:A8:8A:04:96:B2:3F:CF:44:E5"
    private val otherPrint = "AA:BB:CC:DD"
    private val bank = AppIdentity("com.example.bank", setOf(bankPrint))
    private val lookAlike = AppIdentity("com.example.bank", setOf(otherPrint))

    private val types = listOf(
        SecretType("t-login", "login", null, emptyList()),
        SecretType("t-totp", "totp", null, emptyList()),
        SecretType("t-note", "note", null, emptyList()),
    )

    private fun row(id: String, name: String, url: String?, type: String = "t-login", trashed: Boolean = false, blocked: Boolean = false, useOnly: Boolean = false) =
        VaultRow(id, name, url, type, null, "key-$id", "login-$id", null, null, useOnly, false, blocked, null, trashed)

    private fun state(vararg rows: VaultRow, locked: LockReason? = null, onlineOnly: Boolean = false, needsConnection: Boolean = false) =
        VaultState(rows.toList(), emptyList(), types, 1L, offline = false, onlineOnly = onlineOnly, needsConnection = needsConnection, locked = locked)

    private val vault = state(
        row("web", "Example", "https://example.com"),
        row("sub", "Example login", "https://login.example.com"),
        row("app", "Bank app", AppLink("com.example.bank", setOf(bankPrint)).toUrl()),
        row("bare", "Bank app without certificate", "androidapp://com.example.bank"),
        row("code", "Example code", "https://example.com", type = "t-totp"),
        row("note", "Example note", "https://example.com", type = "t-note"),
        row("gone", "Example trashed", "https://example.com", trashed = true),
        row("blocked", "Example blocked", "https://example.com", blocked = true),
    )

    @AfterTest
    fun resetHub() {
        AutofillIndexHub.sink = null
    }

    @Test
    fun theIndexHoldsLoginsAndCodesNeverTrashedOrBlockedItems() {
        val index = AutofillIndex.of(vault)
        assertEquals(listOf("web", "sub", "app", "bare", "code"), index.entries.map { it.id })
        // Ciphertext as the server sent it; nothing decrypted.
        assertEquals("key-web", index.entries.first().key)
        assertEquals("login-web", index.entries.first().login)
    }

    @Test
    fun aWebsiteGetsTheExtensionsSiteMatch() {
        val index = AutofillIndex.of(vault)
        assertEquals(listOf("sub", "web"), index.candidates(AutofillTarget.Web("login.example.com")).map { it.id })
        assertEquals(listOf("code"), index.candidates(AutofillTarget.Web("example.com"), totp = true).map { it.id })
        assertTrue(index.candidates(AutofillTarget.Web("unrelated.net")).isEmpty())
        // The name fallback of the extension: "Example" mentions the label of example.org.
        assertEquals(listOf("web", "sub"), index.candidates(AutofillTarget.Web("example.org")).map { it.id })
        // An app link is never offered to a website, even one whose name reads like the package.
        assertEquals(listOf("web", "sub"), index.candidates(AutofillTarget.Web("example.bank")).map { it.id })
    }

    @Test
    fun anAppIsMatchedByPackageAndCertificate() {
        val index = AutofillIndex.of(vault)
        assertEquals(listOf("app"), index.candidates(AutofillTarget.App(bank)).map { it.id })
        // Same package, another signing certificate: nothing, not even the bare link.
        assertTrue(index.candidates(AutofillTarget.App(lookAlike)).isEmpty())
    }

    @Test
    fun anAppGetsTheLoginsOfTheWebsitesItIsVerifiedFor() {
        val index = AutofillIndex.of(vault)
        val target = AutofillTarget.App(bank, verifiedHosts = listOf("example.com"))
        assertEquals(listOf("app", "web", "sub"), index.candidates(target).map { it.id })
        assertEquals(listOf("code"), index.candidates(target, totp = true).map { it.id })
    }

    @Test
    fun aUseOnlyItemFillsOnlyOnItsOwnSiteAndWithholdsTheSaveOffer() {
        val index = AutofillIndex.of(state(row("shared", "example.com shared", "https://other.org", useOnly = true), row("own", "Own", "https://example.com", useOnly = true)))
        assertEquals(listOf("own"), index.candidates(AutofillTarget.Web("example.com")).map { it.id })
        assertTrue(index.blocksSave(AutofillTarget.Web("example.com")))
        assertFalse(index.blocksSave(AutofillTarget.Web("example.net")))
    }

    @Test
    fun theIndexSurvivesItsOwnJson() {
        val index = AutofillIndex.of(vault)
        val back = AutofillIndex.fromJson(index.toJson())
        assertEquals(index.entries, back.entries)
        assertTrue(AutofillIndex.fromJson("not json").entries.isEmpty())
    }

    @Test
    fun theHubRebuildsOnEverySyncAndClearsWhenASyncLocks() {
        val sink = RecordingSink()
        AutofillIndexHub.sink = sink
        AutofillIndexHub.refreshed("acc", vault, NoKeys)
        assertEquals(5, sink.indexes["acc"]?.entries?.size)
        assertEquals(true, sink.persisted["acc"])

        // An item deleted in the web app is gone after the next sync.
        AutofillIndexHub.refreshed("acc", state(row("web", "Example", "https://example.com")), NoKeys)
        assertEquals(listOf("web"), sink.indexes["acc"]?.entries?.map { it.id })

        // Offline caching off: kept in memory only.
        AutofillIndexHub.refreshed("acc", state(row("web", "Example", "https://example.com"), onlineOnly = true), NoKeys)
        assertEquals(false, sink.persisted["acc"])

        // Offline without a copy: the last index stays.
        AutofillIndexHub.refreshed("acc", state(needsConnection = true), NoKeys)
        assertEquals(listOf("web"), sink.indexes["acc"]?.entries?.map { it.id })

        // A suite change or a new master password locks and clears.
        for (reason in LockReason.entries) {
            AutofillIndexHub.refreshed("acc", vault, NoKeys)
            AutofillIndexHub.refreshed("acc", state(locked = reason), NoKeys)
            assertNull(sink.indexes["acc"], "cleared on $reason")
        }
    }

    @Test
    fun appLinksNeedAFingerprint() {
        assertEquals(AppLink("com.example.bank", setOf(bankPrint)), AppLink.parse("androidapp://com.example.bank#sha256_cert_fingerprints=" + bankPrint.lowercase()))
        assertEquals(emptySet(), AppLink.parse("androidapp://com.example.bank")?.certFingerprints)
        assertFalse(AppLink.parse("androidapp://com.example.bank")!!.matches(bank))
        assertNull(AppLink.parse("https://example.com"))
        assertNull(AppLink.parse("androidapp://not a package"))
        assertEquals("14:6D:E9", AppIdentity.normalizeFingerprint("146de9"))
        assertNull(AppIdentity.normalizeFingerprint("xyz"))
    }

    @Test
    fun digitalAssetLinksNeedBothSidesAndTheLoginRelation() {
        val statements = """[{"include":"https://example.com/.well-known/assetlinks.json"},
            {"relation":["delegate_permission/common.get_login_creds"],"target":{"namespace":"web","site":"https://Login.Example.org:8443"}},
            {"relation":["x"],"target":{"namespace":"web","site":"http://insecure.example"}}]"""
        assertEquals(listOf("https://example.com", "https://login.example.org:8443"), AssetLinks.declaredSites(statements))
        assertTrue(AssetLinks.declaredSites("{").isEmpty())

        val site = """[{"relation":["delegate_permission/common.get_login_creds"],
            "target":{"namespace":"android_app","package_name":"com.example.bank","sha256_cert_fingerprints":["$bankPrint"]}}]"""
        assertTrue(AssetLinks.allowsLogins(site, bank))
        assertFalse(AssetLinks.allowsLogins(site, lookAlike))
        assertFalse(AssetLinks.allowsLogins(site.replace("get_login_creds", "handle_all_urls"), bank))
        assertFalse(AssetLinks.allowsLogins(site.replace("com.example.bank", "com.example.other"), bank))
        assertEquals("https://example.com/.well-known/assetlinks.json", AssetLinks.statementUrl("https://example.com/"))
    }

    @Test
    fun theNeverSaveListIsExactSortedAndLocal() {
        val list = NeverSaveList(InMemorySecureStorage())
        list.set("login.example.com", true)
        list.set("androidapp://com.example.bank", true)
        list.set("login.example.com", true)
        assertEquals(listOf("androidapp://com.example.bank", "login.example.com"), list.sites())
        assertTrue(list.contains("login.example.com"))
        assertFalse(list.contains("www.example.com"))
        list.set("login.example.com", false)
        assertEquals(listOf("androidapp://com.example.bank"), list.sites())
        assertEquals("example.com", AutofillTarget.Web("https://Example.com/x").saveKey)
        assertEquals("androidapp://com.example.bank", AutofillTarget.App(bank).saveKey)
    }

    @Test
    fun iosSiteFilesHoldOneSiteEachAndNoAppLinks() {
        val files = AutofillSites.split(AutofillIndex.of(vault))
        assertEquals(setOf("example.com"), files.keys)
        assertEquals(listOf("sub", "web"), AutofillSites.matches(files.getValue("example.com"), "https://login.example.com/x").map { it.id })
        assertEquals(listOf("code"), AutofillSites.codeItems(files.getValue("example.com"), "example.com").map { it.id })
        assertEquals(listOf("sub"), AutofillSites.search(files.getValue("example.com"), "LOGIN").map { it.id })
        assertEquals("example.com", AutofillSites.siteKey("https://www.example.com/sign-in"))
        assertEquals("sub", AutofillSites.itemId(AutofillSites.recordIdentifier("acc", "sub"), "acc"))
        assertNull(AutofillSites.itemId("other|sub", "acc"))
    }

    private class RecordingSink : AutofillIndexSink {
        val indexes = HashMap<String, AutofillIndex>()
        val persisted = HashMap<String, Boolean>()

        override fun replace(accountId: String, index: AutofillIndex, persist: Boolean, keys: VaultKeys) {
            indexes[accountId] = index
            persisted[accountId] = persist
        }

        override fun clear(accountId: String) {
            indexes.remove(accountId)
        }
    }

    private object NoKeys : VaultKeys {
        override val suiteId = "suite"
        override val unlockKeyEpoch: Long? = null

        override fun decryptField(ciphertext: String?): String = ""

        override fun encryptField(plaintext: String): String = ""
    }
}
