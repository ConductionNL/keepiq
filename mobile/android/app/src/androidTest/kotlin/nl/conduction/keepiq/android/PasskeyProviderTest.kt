// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android

import android.content.Intent
import android.os.Build
import android.util.Log
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiDevice
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import nl.conduction.keepiq.android.vault.VaultSession
import nl.conduction.keepiq.shared.api.KeepiqApi
import nl.conduction.keepiq.shared.crypto.Encoding
import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.crypto.WebAuthn
import nl.conduction.keepiq.shared.sync.SyncTrigger
import nl.conduction.keepiq.shared.vault.ItemCodec
import nl.conduction.keepiq.shared.vault.ItemParts
import nl.conduction.keepiq.shared.vault.RsaVaultKeys
import org.junit.After
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.security.MessageDigest

/**
 * Task 5.1 on the Android 14 emulator against the test server: Keepiq as
 * the Credential Manager provider of another app (the test APK's
 * PasskeyClientActivity), which asks for passkeys for the rpId 10.0.2.2.
 * The test server lists that app in its assetlinks.json (Digital Asset
 * Links), as a real site lists its app.
 *
 * In the order a user meets it: a site that accepts only RS256 gets nothing
 * from Keepiq; a passkey is created, checked byte by byte and found in the
 * vault on the server; signing in with it verifies with its public key; a
 * stored counter of 4 signs as 5 and the item stores 5; a locked vault
 * shows "Unlock Keepiq", then the passkey; and an rpId whose site does not
 * list the app gets nothing.
 *
 * Manual: Chrome and other browsers, which ask as privileged apps with a
 * web origin (the allowlist path), and choosing Keepiq in the system
 * settings, which this test does with `settings put`.
 */
@RunWith(AndroidJUnit4::class)
class PasskeyProviderTest {
    private val instrumentation = InstrumentationRegistry.getInstrumentation()
    private val device = UiDevice.getInstance(instrumentation)
    private val testPackage = instrumentation.context.packageName
    private val provider = "nl.conduction.keepiq/nl.conduction.keepiq.android.passkey.KeepiqCredentialProviderService"
    private val rpId = "10.0.2.2"
    private val userName = "passkey.demo@example.test"
    private val userId = Encoding.utf8("e2e-user-1")

    @Before
    fun setUp() {
        check(Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) { "passkeys need Android 14; run this class on API 34" }
        E2e.requireServer()
        check(E2e.appPassword.isNotEmpty()) { "keepiqAppPassword was not passed" }
        E2e.shell("settings put secure credential_service $provider")
        E2e.shell("settings put secure credential_service_primary $provider")
        assertEquals(provider, E2e.shell("settings get secure credential_service").trim())
        Log.i(TAG, E2e.shell("dumpsys credential").take(4000))
    }

    @After
    fun tearDown() {
        E2e.shell("settings delete secure credential_service_primary")
        E2e.shell("settings delete secure credential_service")
    }

    @Test
    fun createSignInCounterLockedAndRefused() {
        val state = E2e.app.state
        val account = runBlocking { state.client.pairManually(E2e.server, E2e.user, E2e.appPassword) }
        main { state.switchAccount(account.id) }
        unlock(account.id)
        val vault = state.openVault()!!
        val keys = RsaVaultKeys.fromPem(vault.privateKeyPem, vault.certificate!!, vault.suiteId, vault.unlockKeyEpoch)
        val api = state.client.api(account)
        val session = VaultSession(account, api, keys, null, null)
        runBlocking { session.repository.refresh(SyncTrigger.MANUAL) }

        // The site lists the test app, by package and certificate.
        val identity = E2e.app.autofill.identities.identity(testPackage)!!
        val print = identity.certFingerprints.first()
        E2e.helper(
            "/assetlinks",
            """[{"relation":["delegate_permission/common.get_login_creds"],"target":{"namespace":"android_app","package_name":"$testPackage","sha256_cert_fingerprints":["$print"]}}]""",
        )
        val origin = "android:apk-key-hash:" + Encoding.toBase64Url(print.split(':').map { it.toInt(16).toByte() }.toByteArray())

        // A site that accepts only RS256: Keepiq declines, and with no other provider the app hears so.
        client("create", creation(challenge(1), listOf(-257)))
        val refused = waitForAnswer("passkey-00-rs256", listOf("Create", "Continue", "Save"))
        assertTrue("RS256 only: $refused", refused.startsWith("error:"))
        assertTrue("nothing was saved", passkeyRows(api).isEmpty())

        // Create.
        val createChallenge = challenge(2)
        client("create", creation(createChallenge, listOf(-7, -257)))
        val accountLabel = "${account.loginName} · ${account.server.removePrefix("https://").trimEnd('/')}"
        val created = waitForAnswer("passkey-01-create", listOf("Create", "Continue", "Save", accountLabel, "Keepiq"))
        assertTrue("created: $created", created.startsWith("created:"))
        val registration = Json.parseToJsonElement(created.removePrefix("created:")).jsonObject
        val credentialId = registration.text("id")
        val response = registration["response"]!!.jsonObject
        val clientData = Json.parseToJsonElement(Encoding.fromUtf8(Encoding.fromBase64Url(response.text("clientDataJSON")))).jsonObject
        assertEquals("webauthn.create", clientData.text("type"))
        assertEquals(Encoding.toBase64Url(createChallenge), clientData.text("challenge"))
        assertEquals(origin, clientData.text("origin"))
        val attestation = Encoding.fromBase64Url(response.text("attestationObject"))
        val authData = attestation.copyOfRange(30, attestation.size)
        assertArrayEquals(sha256(Encoding.utf8(rpId)), authData.copyOfRange(0, 32))
        assertEquals("flags UP, UV and AT", 0x45, authData[32].toInt() and 0xFF)
        assertArrayEquals("all-zero AAGUID", ByteArray(16), authData.copyOfRange(37, 53))
        assertArrayEquals(Encoding.fromBase64Url(credentialId), authData.copyOfRange(55, 71))
        val spki = spkiOf(authData.copyOfRange(71, authData.size))
        E2e.shot("passkey-02-created")

        // The vault on the server holds it, as a passkey item for the rpId.
        val stored = waitForPasskey(api, keys) { it.credentialId == credentialId }
        assertEquals(rpId, stored.second.rpId)
        assertEquals(userName, stored.second.userName)
        assertEquals(Encoding.toBase64Url(userId), stored.second.userHandle)

        // Sign in with it: the signature verifies with the public key from the attestation.
        signIn(credentialId, spki, origin, expectCounter = 0, name = "passkey-03-sign-in")

        // A counter of 4 signs as 5, and the item stores 5.
        val (itemId, record) = stored
        runBlocking {
            api.updateSecret(itemId, ItemCodec.updateBody(ItemParts(rpId, rpId, null, "", record.copy(counter = 4).toJson(), null), setOf("key"), keys))
            session.repository.refresh(SyncTrigger.MANUAL)
        }
        signIn(credentialId, spki, origin, expectCounter = 5, name = "passkey-04-counter")
        waitForPasskey(api, keys) { it.credentialId == credentialId && it.counter == 5L }

        // Locked: "Unlock Keepiq", the app's unlock screen, then the passkey.
        main { state.lock() }
        assertEquals(null, state.openVault())
        signIn(credentialId, spki, origin, expectCounter = 6, name = "passkey-05-locked") { unlock(account.id) }

        // An rpId whose site does not list the app: Keepiq offers nothing.
        client("get", request(challenge(9), "localhost", emptyList()))
        val other = waitForAnswer("passkey-06-unlisted", listOf(userName, "Unlock Keepiq"))
        assertTrue("unlisted rpId: $other", other.startsWith("error:"))

        runBlocking { api.trashSecret(itemId) }
        main { state.lock() }
        state.client.wipe(account.id)
    }

    private fun signIn(credentialId: String, spki: ByteArray, origin: String, expectCounter: Long, name: String, onKeepiq: () -> Unit = {}) {
        val challenge = challenge(expectCounter.toInt() + 20)
        client("get", request(challenge, rpId, listOf(credentialId)))
        val answer = waitForAnswer(name, listOf(userName, "Unlock Keepiq", "Continue", "Sign in", "Use passkey"), onKeepiq)
        assertTrue("$name: $answer", answer.startsWith("got:"))
        val json = Json.parseToJsonElement(answer.removePrefix("got:")).jsonObject
        assertEquals(credentialId, json.text("id"))
        val response = json["response"]!!.jsonObject
        val clientDataJson = Encoding.fromBase64Url(response.text("clientDataJSON"))
        val clientData = Json.parseToJsonElement(Encoding.fromUtf8(clientDataJson)).jsonObject
        assertEquals("webauthn.get", clientData.text("type"))
        assertEquals(Encoding.toBase64Url(challenge), clientData.text("challenge"))
        assertEquals(origin, clientData.text("origin"))
        val authData = Encoding.fromBase64Url(response.text("authenticatorData"))
        assertArrayEquals(sha256(Encoding.utf8(rpId)), authData.copyOfRange(0, 32))
        assertEquals("flags UP and UV", 0x05, authData[32].toInt() and 0xFF)
        val counter = authData.copyOfRange(33, 37).fold(0L) { acc, b -> (acc shl 8) or (b.toLong() and 0xFF) }
        assertEquals(expectCounter, counter)
        assertArrayEquals(userId, Encoding.fromBase64Url(response.text("userHandle")))
        assertTrue("$name: the signature verifies", WebAuthn.verify(spki, authData, clientDataJson, Encoding.fromBase64Url(response.text("signature"))))
        E2e.shot("$name-done")
    }

    private fun creation(challenge: ByteArray, algorithms: List<Int>): String =
        """{"challenge":"${Encoding.toBase64Url(challenge)}","rp":{"id":"$rpId","name":"Keepiq e2e"},""" +
            """"user":{"id":"${Encoding.toBase64Url(userId)}","name":"$userName","displayName":"Passkey Demo"},""" +
            """"pubKeyCredParams":[${algorithms.joinToString(",") { """{"type":"public-key","alg":$it}""" }}],""" +
            """"timeout":60000,"attestation":"none","authenticatorSelection":{"residentKey":"required","userVerification":"preferred"}}"""

    private fun request(challenge: ByteArray, rp: String, allow: List<String>): String =
        """{"challenge":"${Encoding.toBase64Url(challenge)}","rpId":"$rp","timeout":60000,"userVerification":"preferred",""" +
            """"allowCredentials":[${allow.joinToString(",") { """{"type":"public-key","id":"$it"}""" }}]}"""

    private fun challenge(seed: Int) = ByteArray(32) { (it * 7 + seed).toByte() }

    private fun client(mode: String, json: String) {
        val intent = Intent().setClassName(testPackage, "nl.conduction.keepiq.android.autofilltest.PasskeyClientActivity")
            .putExtra("mode", mode)
            .putExtra("request", json)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK)
        instrumentation.targetContext.startActivity(intent)
    }

    private fun status(): String? = runCatching { device.findObject(By.res(testPackage, "status"))?.text }.getOrNull()

    /**
     * Answers the system sheet until the test app shows an answer: taps the
     * first of [labels] on screen, and runs [onKeepiq] once when Keepiq's
     * own screen comes up (the unlock). Saves a screenshot of each new
     * screen, and the window tree when no answer comes.
     */
    private fun waitForAnswer(name: String, labels: List<String>, onKeepiq: () -> Unit = {}): String {
        val end = System.currentTimeMillis() + 120_000
        var shots = 0
        var keepiqSeen = false
        while (System.currentTimeMillis() < end) {
            val text = status()
            if (text != null && (text.startsWith("created:") || text.startsWith("got:") || text.startsWith("error:"))) {
                E2e.shot("$name-answer")
                return text
            }
            if (!keepiqSeen && device.hasObject(By.pkg("nl.conduction.keepiq"))) {
                keepiqSeen = true
                E2e.shot("$name-keepiq")
                onKeepiq()
                continue
            }
            val target = labels.firstNotNullOfOrNull { label -> device.findObject(By.text(label)) }
            if (target != null) {
                if (shots < 4) E2e.shot("$name-sheet-${shots++}")
                runCatching { target.click() }
                Thread.sleep(1_500)
            } else {
                Thread.sleep(500)
            }
        }
        val dir = File(instrumentation.targetContext.filesDir, "e2e-shots").apply { mkdirs() }
        File(dir, "$name-windows.xml").outputStream().use { device.dumpWindowHierarchy(it) }
        E2e.shot("$name-timeout")
        error("$name: no answer from Credential Manager, status ${status()}")
    }

    private fun passkeyRows(api: KeepiqApi): List<JsonObject> = runBlocking {
        val typeId = api.listTypes().first { (it["name"] as? JsonPrimitive)?.contentOrNull == PasskeyCredential.TYPE_NAME }.text("id")
        api.listSecrets().filter { (it["typeId"] as? JsonPrimitive)?.contentOrNull == typeId && (it["url"] as? JsonPrimitive)?.contentOrNull == rpId }
    }

    /** The passkey item on the server whose decrypted record satisfies [match]: its id and record. */
    private fun waitForPasskey(api: KeepiqApi, keys: RsaVaultKeys, match: (PasskeyCredential) -> Boolean): Pair<String, PasskeyCredential> {
        val end = System.currentTimeMillis() + 30_000
        while (true) {
            for (row in passkeyRows(api)) {
                val record = runCatching { PasskeyCredential.parse(keys.decryptField(row.text("key"))) { "" } }.getOrNull() ?: continue
                if (match(record)) return row.text("id") to record
            }
            check(System.currentTimeMillis() < end) { "no passkey item on the server matches" }
            Thread.sleep(500)
        }
    }

    /** DER SubjectPublicKeyInfo of the COSE EC2 key in the attested credential data. */
    private fun spkiOf(cose: ByteArray): ByteArray {
        val head = byteArrayOf(0xA5.toByte(), 0x01, 0x02, 0x03, 0x26, 0x20, 0x01, 0x21, 0x58, 0x20)
        assertArrayEquals("COSE key {1: 2, 3: -7, -1: 1, -2: x, -3: y}", head, cose.copyOfRange(0, 10))
        val x = cose.copyOfRange(10, 42)
        val y = cose.copyOfRange(45, 77)
        val prefix = byteArrayOf(
            0x30, 0x59, 0x30, 0x13, 0x06, 0x07, 0x2A, 0x86.toByte(), 0x48, 0xCE.toByte(), 0x3D, 0x02, 0x01,
            0x06, 0x08, 0x2A, 0x86.toByte(), 0x48, 0xCE.toByte(), 0x3D, 0x03, 0x01, 0x07, 0x03, 0x42, 0x00, 0x04,
        )
        return prefix + x + y
    }

    private fun sha256(bytes: ByteArray): ByteArray = MessageDigest.getInstance("SHA-256").digest(bytes)

    private fun JsonObject.text(name: String): String = (this[name] as? JsonPrimitive)?.contentOrNull ?: error("no $name")

    private fun main(block: () -> Unit) = instrumentation.runOnMainSync(block)

    private fun unlock(accountId: String) {
        main { E2e.app.state.unlockWithMasterPassword(accountId, E2e.masterPassword) }
        val end = System.currentTimeMillis() + 180_000
        while (E2e.app.state.openVault() == null) {
            check(System.currentTimeMillis() < end) {
                "the vault did not open: screen ${E2e.app.state.screen.value::class.simpleName}, message ${E2e.app.state.message.value}"
            }
            Thread.sleep(250)
        }
    }

    private companion object {
        const val TAG = "PasskeyProviderTest"
    }
}
