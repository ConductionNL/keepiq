// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

import kotlin.test.Test
import kotlin.test.assertEquals
import kotlin.test.assertNull
import kotlin.test.assertTrue

/** Field detection (task 4.1): Android hints, HTML attributes and view ids, with the extension's selectors. */
class FieldDetectorTest {
    private fun html(vararg attrs: Pair<String, String>) = FieldFacts(htmlTag = "input", htmlAttributes = attrs.toMap())

    @Test
    fun androidHintsDecideFirst() {
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(FieldFacts(autofillHints = listOf("username"))))
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(FieldFacts(autofillHints = listOf("emailAddress"))))
        assertEquals(FieldKind.PASSWORD, FieldDetector.classify(FieldFacts(autofillHints = listOf("password"))))
        assertEquals(FieldKind.NEW_PASSWORD, FieldDetector.classify(FieldFacts(autofillHints = listOf("newPassword"))))
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(FieldFacts(autofillHints = listOf("smsOTPCode"))))
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(FieldFacts(autofillHints = listOf("2faAppOTPCode"))))
    }

    @Test
    fun htmlAttributesFollowTheExtensionsSelectors() {
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(html("autocomplete" to "one-time-code")))
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(html("name" to "totp_code")))
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(html("inputmode" to "numeric", "maxlength" to "6")))
        assertEquals(FieldKind.PASSWORD, FieldDetector.classify(html("type" to "password")))
        assertEquals(FieldKind.NEW_PASSWORD, FieldDetector.classify(html("type" to "password", "autocomplete" to "new-password")))
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(html("type" to "email")))
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(html("autocomplete" to "section-x username")))
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(html("type" to "text", "name" to "Login_Name")))
        assertEquals(FieldKind.OTHER, FieldDetector.classify(html("type" to "hidden", "name" to "username")))
        assertEquals(FieldKind.OTHER, FieldDetector.classify(html("type" to "submit")))
        assertEquals(FieldKind.OTHER, FieldDetector.classify(html("type" to "text", "name" to "city")))
    }

    @Test
    fun viewIdsAndInputTypesInApps() {
        assertEquals(FieldKind.USERNAME, FieldDetector.classify(FieldFacts(idEntry = "user_name")))
        assertEquals(FieldKind.PASSWORD, FieldDetector.classify(FieldFacts(idEntry = "pw", passwordInput = true)))
        assertEquals(FieldKind.ONE_TIME_CODE, FieldDetector.classify(FieldFacts(idEntry = "otp_field", numberInput = true)))
        assertEquals(FieldKind.OTHER, FieldDetector.classify(FieldFacts(idEntry = "username", editable = false)))
    }

    @Test
    fun aUserNameIsFoundByItsLabelOrBeforeThePassword() {
        val byLabel = FieldDetector.detect(listOf(FieldFacts(idEntry = "a"), FieldFacts(idEntry = "b", hint = "E-mailadres"), FieldFacts(idEntry = "c", passwordInput = true)))
        assertEquals(1, byLabel.username)
        assertEquals(listOf(2), byLabel.passwords)

        val before = FieldDetector.detect(listOf(FieldFacts(idEntry = "first"), FieldFacts(idEntry = "second"), FieldFacts(idEntry = "c", passwordInput = true)))
        assertEquals(1, before.username)

        val signUp = FieldDetector.detect(listOf(html("type" to "email"), html("type" to "password", "autocomplete" to "new-password")))
        assertEquals(0, signUp.username)
        assertEquals(listOf(1), signUp.newPasswords)
        assertTrue(signUp.isLogin)

        val code = FieldDetector.detect(listOf(html("autocomplete" to "one-time-code")))
        assertNull(code.username)
        assertEquals(0, code.oneTimeCode)
        assertTrue(!code.isLogin && !code.isEmpty)
    }
}
