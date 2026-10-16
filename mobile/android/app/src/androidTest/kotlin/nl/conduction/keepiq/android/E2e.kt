// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.graphics.Bitmap
import android.os.ParcelFileDescriptor
import androidx.compose.ui.test.SemanticsNodeInteractionsProvider
import androidx.compose.ui.test.junit4.ComposeTestRule
import androidx.compose.ui.test.onAllNodesWithTag
import androidx.compose.ui.test.onAllNodesWithText
import androidx.test.platform.app.InstrumentationRegistry
import java.io.File
import java.net.URL
import javax.net.ssl.HttpsURLConnection

/**
 * What the end-to-end tests share (.github/workflows/mobile-e2e.yml): the
 * test server's address and the demo account from the instrumentation
 * arguments, the screenshots, and the test helper on the server.
 */
object E2e {
    private val args = InstrumentationRegistry.getArguments()

    /** The test server as the emulator reaches it, https://10.0.2.2:8443. */
    val server: String get() = args.getString("keepiqServer") ?: ""
    val user: String get() = args.getString("keepiqUser") ?: "admin"
    val masterPassword: String get() = args.getString("keepiqMasterPassword") ?: ""
    val appPassword: String get() = args.getString("keepiqAppPassword") ?: ""

    /** Fails when the workflow did not pass a server: these tests only make sense against one. */
    fun requireServer() {
        check(server.startsWith("https://")) { "keepiqServer was not passed; run through mobile/e2e/android-run.sh" }
    }

    val app: KeepiqApp get() = InstrumentationRegistry.getInstrumentation().targetContext.applicationContext as KeepiqApp

    /** Saves a screenshot under files/e2e-shots; android-run.sh pulls them with run-as. */
    fun shot(name: String) {
        val instrumentation = InstrumentationRegistry.getInstrumentation()
        instrumentation.waitForIdleSync()
        Thread.sleep(400)
        val bitmap = instrumentation.uiAutomation.takeScreenshot() ?: return
        val dir = File(instrumentation.targetContext.filesDir, "e2e-shots").apply { mkdirs() }
        File(dir, "$name.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG, 100, it) }
    }

    /** Runs a shell command as the shell user and returns its output. */
    fun shell(command: String): String {
        val fd = InstrumentationRegistry.getInstrumentation().uiAutomation.executeShellCommand(command)
        return ParcelFileDescriptor.AutoCloseInputStream(fd).use { it.readBytes().toString(Charsets.UTF_8) }
    }

    /** Calls the e2e helper of mobile/e2e/server.mjs, which stands in for the user's browser and for occ. */
    fun helper(path: String, body: String? = null): String {
        val connection = URL("$server/__e2e$path").openConnection() as HttpsURLConnection
        connection.connectTimeout = 30_000
        connection.readTimeout = 120_000
        if (body != null) {
            connection.requestMethod = "POST"
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.outputStream.use { it.write(body.toByteArray()) }
        }
        val status = connection.responseCode
        val text = (if (status in 200..299) connection.inputStream else connection.errorStream)?.use { it.readBytes().toString(Charsets.UTF_8) } ?: ""
        check(status in 200..299) { "e2e helper $path answered $status: $text" }
        return text
    }
}

/** Waits until a node with [tag] exists. */
fun ComposeTestRule.waitForTag(tag: String, timeoutMillis: Long = 30_000) {
    waitUntil(timeoutMillis) { onAllNodesWithTag(tag, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty() }
}

/** Waits until some node shows [text]. */
fun ComposeTestRule.waitForText(text: String, timeoutMillis: Long = 30_000) {
    waitUntil(timeoutMillis) {
        (this as SemanticsNodeInteractionsProvider).onAllNodesWithText(text, substring = true, useUnmergedTree = true)
            .fetchSemanticsNodes().isNotEmpty()
    }
}

/** Waits until no node shows [text] any more. */
fun ComposeTestRule.waitUntilGone(text: String, timeoutMillis: Long = 30_000) {
    waitUntil(timeoutMillis) {
        (this as SemanticsNodeInteractionsProvider).onAllNodesWithText(text, substring = true, useUnmergedTree = true)
            .fetchSemanticsNodes().isEmpty()
    }
}

/**
 * The emulator's network, for the offline test (task 3.4). The shell turns
 * off Wi-Fi and mobile data, and airplane mode where `cmd connectivity`
 * has it (Android 11 and later). The test then checks from the app's own
 * process that the server is out of reach, so a network that stayed up
 * fails the test instead of passing it.
 */
object Network {
    fun cut() {
        E2e.shell("cmd connectivity airplane-mode enable")
        E2e.shell("svc wifi disable")
        E2e.shell("svc data disable")
    }

    fun restore() {
        E2e.shell("cmd connectivity airplane-mode disable")
        E2e.shell("svc wifi enable")
        E2e.shell("svc data enable")
    }

    /** Whether status.php answers, with short timeouts. */
    fun reachable(): Boolean = try {
        val connection = URL("${E2e.server}/status.php").openConnection() as HttpsURLConnection
        connection.connectTimeout = 5_000
        connection.readTimeout = 5_000
        connection.useCaches = false
        try {
            connection.responseCode in 200..499
        } finally {
            connection.disconnect()
        }
    } catch (e: java.io.IOException) {
        false
    }

    fun awaitUnreachable(timeoutMillis: Long = 60_000) = await(timeoutMillis, false)

    fun awaitReachable(timeoutMillis: Long = 120_000) = await(timeoutMillis, true)

    private fun await(timeoutMillis: Long, want: Boolean) {
        val until = System.currentTimeMillis() + timeoutMillis
        while (System.currentTimeMillis() < until) {
            if (reachable() == want) return
            Thread.sleep(1_000)
        }
        // executeShellCommand runs no shell, so no pipes: filter here.
        val state = E2e.shell("dumpsys connectivity").lineSequence()
            .filter { it.contains("NetworkAgentInfo") }.take(5).joinToString(" | ")
        throw AssertionError(
            if (want) "the server is still out of reach ${timeoutMillis / 1000} s after the network came back; networks: $state"
            else "the server still answers ${timeoutMillis / 1000} s after the network was cut; networks: $state",
        )
    }
}
