// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
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
import nl.conduction.keepiq.shared.autofill.AppLink
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Whether Keepiq can fill an ordinary app (task 4.1) with the package
 * visibility it declares, on this Android version.
 *
 * Keepiq names an app by its package and signing certificate, read from
 * the package manager. From Android 11 the package manager hides other
 * apps unless the manifest asks for them. SystemAutofillTest cannot answer
 * this: its forms live in the test APK, which Android always shows to the
 * app it instruments. So the form here is a separate APK,
 * :android:otherapp, installed by mobile/e2e/android-run.sh.
 *
 * The control: when Keepiq declares no QUERY_ALL_PACKAGES, the other app
 * must be hidden from Keepiq before the fill (Android 11+). Without that
 * the fill would prove nothing about visibility.
 */
@RunWith(AndroidJUnit4::class)
class PackageVisibilityTest {
    private val instrumentation = InstrumentationRegistry.getInstrumentation()
    private val device = UiDevice.getInstance(instrumentation)
    private val service = "nl.conduction.keepiq/nl.conduction.keepiq.android.autofill.KeepiqAutofillService"

    @Before
    fun setUp() {
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
        E2e.shell("settings put secure autofill_service $service")
        assertTrue("Keepiq is the autofill service", E2e.shell("settings get secure autofill_service").trim() == service)
        assertTrue("$OTHER is installed (android-run.sh)", E2e.shell("pm path $OTHER").contains("package:"))
    }

    @After
    fun tearDown() {
        E2e.shell("settings delete secure autofill_service")
        E2e.shell("am force-stop $OTHER")
    }

    @Test
    fun fillsAnAppThatIsNotInstrumented() {
        val context = instrumentation.targetContext
        val requested = context.packageManager.getPackageInfo(context.packageName, PackageManager.GET_PERMISSIONS)
            .requestedPermissions.orEmpty()
        val queriesAll = "android.permission.QUERY_ALL_PACKAGES" in requested
        val filtered = Build.VERSION.SDK_INT >= Build.VERSION_CODES.R && !queriesAll
        Log.i(TAG, "API ${Build.VERSION.SDK_INT}, QUERY_ALL_PACKAGES declared: $queriesAll")
        val core = E2e.app.autofill
        if (filtered) {
            assertEquals("the control: $OTHER is hidden from Keepiq before any fill", null, core.identities.identity(OTHER))
        }

        val state = E2e.app.state
        val account = runBlocking { state.client.pairManually(E2e.server, E2e.user, E2e.appPassword) }
        instrumentation.runOnMainSync { state.switchAccount(account.id) }
        instrumentation.runOnMainSync { state.unlockWithMasterPassword(account.id, E2e.masterPassword) }
        waitUntil(180_000) { state.openVault() != null }
        val vault = state.openVault()!!
        val keys = RsaVaultKeys.fromPem(vault.privateKeyPem, vault.certificate!!, vault.suiteId, vault.unlockKeyEpoch)
        val api = state.client.api(account)

        // The other app is signed with the same debug key as the e2e build
        // (Gradle's debug keystore on the runner), so Keepiq's own
        // certificate is its certificate too. A wrong guess fails the fill,
        // it never passes it.
        val print = core.identities.identity(context.packageName)!!.certFingerprints.first()
        val typeId = runBlocking { api.listTypes() }.first { (it["name"] as? JsonPrimitive)?.contentOrNull == "login" }["id"]!!
            .let { (it as JsonPrimitive).content }
        val id = runBlocking {
            val body = ItemCodec.createBody(ItemParts("Other app", AppLink(OTHER, setOf(print)).toUrl(), null, "otheruser", "other-secret-1", null), typeId, keys)
            (api.createSecret(body)!!["id"] as JsonPrimitive).content
        }
        try {
            runBlocking { VaultSession(account, api, keys, null, null).repository.refresh(SyncTrigger.MANUAL) }
            assertTrue(core.indexOf(account.id).entries.any { it.id == id })

            // Started by the shell, not by Keepiq, so the start grants Keepiq nothing.
            E2e.shell("am start -W -n $OTHER/nl.conduction.keepiq.otherapp.LoginActivity")
            waitFor(By.res(OTHER, "status"))
            val entry = device.wait(Until.findObject(By.text("otheruser")), 30_000)
            E2e.shot("visibility-01-other-app-${Build.VERSION.SDK_INT}")
            assertTrue(
                "Keepiq offered the other app's login (API ${Build.VERSION.SDK_INT}, QUERY_ALL_PACKAGES declared: $queriesAll)",
                entry != null,
            )
            entry!!.click()
            waitFor(By.textContains("user=otheruser password=14"))
            E2e.shot("visibility-02-other-app-filled-${Build.VERSION.SDK_INT}")
        } finally {
            runBlocking { api.trashSecret(id) }
            instrumentation.runOnMainSync { state.lock() }
            state.client.wipe(account.id)
        }
    }

    private fun waitFor(selector: BySelector, timeout: Long = 30_000): UiObject2 =
        device.wait(Until.findObject(selector), timeout) ?: error("not on screen: $selector")

    private fun waitUntil(timeout: Long, condition: () -> Boolean) {
        val end = System.currentTimeMillis() + timeout
        while (!condition()) {
            check(System.currentTimeMillis() < end) { "timed out" }
            Thread.sleep(250)
        }
    }

    companion object {
        private const val TAG = "KeepiqE2e"
        const val OTHER = "nl.conduction.keepiq.e2e.otherapp"
    }
}
