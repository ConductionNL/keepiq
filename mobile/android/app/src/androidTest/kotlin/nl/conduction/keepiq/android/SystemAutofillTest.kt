// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.Intent
import android.os.Build
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.BySelector
import androidx.test.uiautomator.UiDevice
import androidx.test.uiautomator.UiObject2
import androidx.test.uiautomator.Until
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.autofill.AppLink
import nl.conduction.keepiq.shared.crypto.Totp
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Task group 4 on the emulator against the test server: Keepiq as the
 * autofill service of another app (the test APK's forms) and of a WebView
 * page, in the order a user meets it.
 *
 * 4.1 the locked "Unlock Keepiq" entry without names, the unlock, the pick
 * between the app's own login and the one Digital Asset Links verified, a
 * look-alike login (same package, another certificate) never offered,
 * then the unlocked dropdown, and the web domain of the WebView page.
 * 4.3 the one-time code on the step after the login. 4.2 saving a sign-up,
 * "Never" and no offer after it. 4.6 a login trashed on the server is gone
 * after the next sync, and unpairing clears the index.
 *
 * Inline suggestions need a keyboard that shows them; the emulator's
 * keyboard does not, so this test sees the dropdown (manual check in the
 * PR covers Gboard).
 */
@RunWith(AndroidJUnit4::class)
class SystemAutofillTest {
    private val instrumentation = InstrumentationRegistry.getInstrumentation()
    private val device = UiDevice.getInstance(instrumentation)
    private val testPackage = instrumentation.context.packageName
    private val service = "nl.conduction.keepiq/nl.conduction.keepiq.android.autofill.KeepiqAutofillService"
    private val seed = "otpauth://totp/Keepiq:e2e?secret=JBSWY3DPEHPK3PXP&issuer=Keepiq"

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
        E2e.shell("settings put secure autofill_service $service")
        assertTrue("Keepiq is the autofill service", E2e.shell("settings get secure autofill_service").trim() == service)
    }

    @After
    fun tearDown() {
        E2e.shell("settings delete secure autofill_service")
    }

    @Test
    fun fillSaveCodesAndTheIndex() {
        val state = E2e.app.state
        val core = E2e.app.autofill
        val account = runBlocking { state.client.pairManually(E2e.server, E2e.user, E2e.appPassword) }
        main { state.switchAccount(account.id) }
        unlock(account.id)
        val vault = state.openVault()!!
        val keys = RsaVaultKeys.fromPem(vault.privateKeyPem, vault.certificate!!, vault.suiteId, vault.unlockKeyEpoch)
        val api = state.client.api(account)

        // The test app as Keepiq sees it, and the website that lists it back.
        val identity = core.identities.identity(testPackage)!!
        val print = identity.certFingerprints.first()
        E2e.helper(
            "/assetlinks",
            """[{"relation":["delegate_permission/common.get_login_creds"],"target":{"namespace":"android_app","package_name":"$testPackage","sha256_cert_fingerprints":["$print"]}}]""",
        )
        val appUrl = AppLink(testPackage, setOf(print)).toUrl()
        val lookAlikeUrl = AppLink(testPackage, setOf("00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF:00:11:22:33:44:55:66:77:88:99:AA:BB:CC:DD:EE:FF")).toUrl()
        val webId = create(api, keys, "login", "Test server", "https://10.0.2.2:8443", "webuser", "web-secret-1")
        val appId = create(api, keys, "login", "Test app", appUrl, "appuser", "app-secret-1")
        val lookAlikeId = create(api, keys, "login", "Look-alike", lookAlikeUrl, "wrongcert", "look-alike-1")
        val codeId = create(api, keys, "totp", "Test code", appUrl, "", seed)

        // 4.6: a sync builds the index (VaultSession reports every refresh).
        val session = VaultSession(account, api, keys, null, null)
        runBlocking { session.repository.refresh(SyncTrigger.MANUAL) }
        val indexed = core.indexOf(account.id).entries.map { it.id }
        assertTrue("indexed: $indexed", indexed.containsAll(listOf(webId, appId, lookAlikeId, codeId)))

        // 4.1 locked: one entry, no account names.
        main { state.lock() }
        assertEquals(null, state.openVault())
        form(login = true)
        val unlockEntry = waitFor(By.text("Unlock Keepiq"))
        assertFalse(device.hasObject(By.textContains("appuser")))
        assertFalse(device.hasObject(By.textContains("Test app")))
        E2e.shot("autofill-01-locked-entry")
        unlockEntry.click()
        waitFor(By.pkg("nl.conduction.keepiq"))
        E2e.shot("autofill-02-unlock")
        unlock(account.id)

        // Two logins for the app: its own, and the website's through Digital Asset Links. Never the look-alike.
        waitFor(By.text("Choose a login"), 60_000)
        assertNotNull(device.findObject(By.text("appuser")))
        assertNotNull(device.findObject(By.text("webuser")))
        assertFalse(device.hasObject(By.text("wrongcert")))
        E2e.shot("autofill-03-choose")
        device.findObject(By.text("appuser")).click()
        waitFor(By.textContains("user=appuser password=12"))
        E2e.shot("autofill-04-filled-after-unlock")

        // 4.3: the code on the next step.
        device.findObject(By.res(testPackage, "submit")).click()
        waitFor(By.textStartsWith("Code from Test code")).click()
        val statusText = waitForStatus { it.substringAfter("code=").length == 6 }
        val code = statusText.substringAfter("code=")
        val now = System.currentTimeMillis()
        val valid = (-1..1).map { Totp.generate(Totp.parse(seed), now + it * 30_000L) }
        assertTrue("code $code is one of $valid", code in valid)
        E2e.shot("autofill-05-one-time-code")

        // 4.1 unlocked: the dropdown names the logins; the look-alike stays out.
        form(login = true)
        waitFor(By.text("appuser"))
        assertNotNull(device.findObject(By.text("webuser")))
        assertFalse(device.hasObject(By.text("wrongcert")))
        E2e.shot("autofill-06-unlocked-dropdown")
        device.findObject(By.text("webuser")).click()
        waitFor(By.textContains("user=webuser password=12"))

        // The web domain of a page: the site's login, never the app's.
        web()
        waitFor(By.clazz("android.widget.EditText")).click()
        waitFor(By.text("webuser"))
        assertFalse(device.hasObject(By.text("appuser")))
        E2e.shot("autofill-07-webview")
        device.findObject(By.text("webuser")).click()
        waitFor(By.textContains("filled: webuser / 12"))
        E2e.shot("autofill-08-webview-filled")

        // 4.2: saving a sign-up.
        form(login = false)
        type("username", "newuser@example.test")
        type("password", "Brand-new-pass-1")
        device.findObject(By.res(testPackage, "submit")).click()
        val save = waitFor(By.res("android", "autofill_save_yes"))
        E2e.shot("autofill-09-save-offer")
        save.click()
        val saved = waitForServer(api, keys) { it == "newuser@example.test" }
        assertTrue("the saved login belongs to the test app", saved.startsWith("androidapp://$testPackage#sha256_cert_fingerprints="))

        // "Never" (Android 11+): the app goes on the never-save list, and is not asked again.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            form(login = false)
            type("username", "other@example.test")
            type("password", "Other-pass-2")
            device.findObject(By.res(testPackage, "submit")).click()
            waitFor(By.res("android", "autofill_save_no")).click()
            waitUntil { core.neverSave.contains("androidapp://$testPackage") }
        } else {
            // Android 9 and 10 have no "Never" button; the list is honoured all the same.
            core.neverSave.set("androidapp://$testPackage", true)
        }
        form(login = false)
        type("username", "third@example.test")
        type("password", "Third-pass-3")
        device.findObject(By.res(testPackage, "submit")).click()
        assertFalse("no save offer after Never", device.wait(Until.hasObject(By.res("android", "autofill_save_yes")), 5_000))
        E2e.shot("autofill-10-never")

        // 4.6: a login trashed on the server is not offered after the next sync.
        runBlocking {
            api.trashSecret(webId)
            session.repository.refresh(SyncTrigger.MANUAL)
        }
        assertFalse(core.indexOf(account.id).entries.any { it.id == webId })
        web()
        waitFor(By.clazz("android.widget.EditText")).click()
        assertFalse("a trashed login is not offered", device.wait(Until.hasObject(By.text("webuser")), 5_000))
        E2e.shot("autofill-11-trashed-not-offered")

        // Unpair (here the local wipe, so the shared app password stays): the index goes.
        runBlocking { listOf(appId, lookAlikeId, codeId).forEach { api.trashSecret(it) } }
        main { state.lock() }
        state.client.wipe(account.id)
        assertTrue(core.indexOf(account.id).entries.isEmpty())
        core.neverSave.set("androidapp://$testPackage", false)
    }

    private fun main(block: () -> Unit) = instrumentation.runOnMainSync(block)

    private fun unlock(accountId: String) {
        main { E2e.app.state.unlockWithMasterPassword(accountId, E2e.masterPassword) }
        // The unlock opens the encrypted store and syncs before the vault
        // counts as open; on the API 28 emulator that is slow.
        try {
            waitUntil(180_000) { E2e.app.state.openVault() != null }
        } catch (e: IllegalStateException) {
            error("the vault did not open: screen ${E2e.app.state.screen.value::class.simpleName}, message ${E2e.app.state.message.value}")
        }
    }

    private fun create(api: KeepiqApi, keys: RsaVaultKeys, type: String, name: String, url: String, login: String, secret: String): String = runBlocking {
        val typeId = api.listTypes().first { (it["name"] as? JsonPrimitive)?.contentOrNull == type }["id"]!!.let { (it as JsonPrimitive).content }
        val saved = api.createSecret(ItemCodec.createBody(ItemParts(name, url, null, login, secret, null), typeId, keys))
        (saved!!["id"] as JsonPrimitive).content
    }

    private fun form(login: Boolean) {
        val intent = Intent().setClassName(testPackage, "nl.conduction.keepiq.android.autofilltest.AutofillFormActivity")
            .putExtra("mode", if (login) "login" else "signup")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        instrumentation.targetContext.startActivity(intent)
        waitFor(By.res(testPackage, "status"))
    }

    private fun web() {
        val intent = Intent().setClassName(testPackage, "nl.conduction.keepiq.android.autofilltest.WebFormActivity")
            .putExtra("url", E2e.server + "/__e2e/login.html")
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        instrumentation.targetContext.startActivity(intent)
        waitFor(By.textContains("filled:"), 60_000)
    }

    private fun type(field: String, text: String) {
        waitFor(By.res(testPackage, field)).text = text
    }

    private fun waitFor(selector: BySelector, timeout: Long = 30_000): UiObject2 =
        device.wait(Until.findObject(selector), timeout) ?: error("not on screen: $selector")

    private fun waitForStatus(done: (String) -> Boolean): String {
        var text = ""
        waitUntil { text = device.findObject(By.res(testPackage, "status"))?.text ?: ""; done(text) }
        return text
    }

    private fun waitUntil(timeout: Long = 30_000, condition: () -> Boolean) {
        val end = System.currentTimeMillis() + timeout
        while (!condition()) {
            check(System.currentTimeMillis() < end) { "timed out" }
            Thread.sleep(250)
        }
    }

    /** The url of the login on the server whose decrypted login name satisfies [match]. */
    private fun waitForServer(api: KeepiqApi, keys: RsaVaultKeys, match: (String) -> Boolean): String {
        var url: String? = null
        waitUntil {
            url = runBlocking { api.listSecrets() }.firstOrNull { row ->
                val login = (row["login"] as? JsonPrimitive)?.contentOrNull
                login != null && runCatching { match(keys.decryptField(login)) }.getOrDefault(false)
            }?.let { (it["url"] as? JsonPrimitive)?.contentOrNull }
            url != null
        }
        return url!!
    }
}
