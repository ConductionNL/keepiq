// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import nl.conduction.keepiq.shared.crypto.PasskeyCredential
import nl.conduction.keepiq.shared.passkey.PasskeyChoice
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config

/**
 * The passkey a Credential Manager entry carries back to
 * CredentialProviderActivity (mobile-login-totp-passkeys D11): two passkeys
 * on one login are two entries, and each entry signs with its own passkey,
 * never the first of its login.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35])
class PasskeyEntryIdsTest {
    private fun credential(id: String, userName: String) = PasskeyCredential(
        credentialId = id, rpId = "mail.example", rpName = "Mail", userName = userName, userDisplayName = "",
        userHandle = "dTE", privateKey = "pk-$id", algorithm = -7L, counter = 0L, transports = emptyList(),
        createdAt = "",
    )

    @Test
    fun eachEntryOfOneLoginSignsWithItsOwnPasskey() {
        val choices = listOf(
            PasskeyChoice("l1", "Mail", credential("a", "alice"), onLogin = true),
            PasskeyChoice("l1", "Mail", credential("b", "alice-work"), onLogin = true),
        )
        val intents = CredentialEntries.passkeyIntents(RuntimeEnvironment.getApplication(), choices)
        assertEquals(choices, intents.map { CredentialProviderActivity.chosen(choices, it) })
    }

    @Test
    fun anEntryWhosePasskeyIsGoneSignsWithNone() {
        val gone = listOf(PasskeyChoice("l1", "Mail", credential("a", "alice"), onLogin = true))
        val intent = CredentialEntries.passkeyIntents(RuntimeEnvironment.getApplication(), gone).single()
        assertNull(CredentialProviderActivity.chosen(listOf(PasskeyChoice("l1", "Mail", credential("b", "alice"), onLogin = true)), intent))
    }
}
