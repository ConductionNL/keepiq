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
