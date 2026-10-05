// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.autofill

/**
 * What the platform tells about one input field: the autofill hints an app
 * set, the HTML tag and attributes a browser or WebView reports, the view id,
 * the hint text and the input type. Android's ViewNode maps onto it.
 */
data class FieldFacts(
    val autofillHints: List<String> = emptyList(),
    val htmlTag: String? = null,
    val htmlAttributes: Map<String, String> = emptyMap(),
    val idEntry: String? = null,
    val hint: String? = null,
    /** The input type marks a password (text, web, visible or number password). */
    val passwordInput: Boolean = false,
    /** The input type is a number or a phone number. */
    val numberInput: Boolean = false,
    /** A text field the user can type in: not a button, label or hidden input. */
    val editable: Boolean = true,
    val visible: Boolean = true,
)

enum class FieldKind { USERNAME, PASSWORD, NEW_PASSWORD, ONE_TIME_CODE, OTHER }

/** The fields of one form, as indexes into the list given to [FieldDetector.detect]. */
data class DetectedFields(
    val username: Int?,
    val passwords: List<Int>,
    val newPasswords: List<Int>,
    val oneTimeCode: Int?,
) {
    val isLogin: Boolean get() = passwords.isNotEmpty() || newPasswords.isNotEmpty() || username != null
    val isEmpty: Boolean get() = !isLogin && oneTimeCode == null
}

/**
 * Finds user name, password and one-time-code fields, with the browser
 * extension's rules (browser-extension/src/lib/field-detect.js and the
 * OTP_SELECTORS of content-script.js) applied to what Android reports, and
 * Android's own autofill hints first.
 */
object FieldDetector {
    /** USERNAME_WORDS in field-detect.js: a leading word boundary only. */
    private val USERNAME_WORDS = Regex("\\b(user ?name|user|e-?mail|login|account|gebruiker)", RegexOption.IGNORE_CASE)

    private val USERNAME_HINTS = setOf("username", "emailaddress", "email", "newusername")
    private val PASSWORD_HINTS = setOf("password", "current-password", "currentpassword")
    private val NEW_PASSWORD_HINTS = setOf("newpassword", "new-password")
    private val OTP_HINTS = setOf("smsotpcode", "2faappotpcode", "emailotpcode", "otpcode", "one-time-code", "onetimecode")
    private val TEXT_TYPES = setOf("text", "email", "tel", "")

    fun classify(field: FieldFacts): FieldKind {
        if (!field.editable || !field.visible) return FieldKind.OTHER
        val type = field.htmlAttributes["type"]?.lowercase()
        if (type == "hidden" || type == "submit" || type == "button" || type == "checkbox" || type == "radio") return FieldKind.OTHER
        val autocomplete = field.htmlAttributes["autocomplete"]?.lowercase()?.split(' ')?.filter { it.isNotEmpty() } ?: emptyList()
        val hints = field.autofillHints.map { it.lowercase().replace("_", "") }

        // An explicit autocomplete token or autofill hint decides.
        if ("one-time-code" in autocomplete || hints.any { it in OTP_HINTS || it.contains("otp") }) return FieldKind.ONE_TIME_CODE
        if ("new-password" in autocomplete || hints.any { it in NEW_PASSWORD_HINTS }) return FieldKind.NEW_PASSWORD
        if ("current-password" in autocomplete || hints.any { it in PASSWORD_HINTS }) return FieldKind.PASSWORD
        if ("username" in autocomplete || "email" in autocomplete || hints.any { it in USERNAME_HINTS }) return FieldKind.USERNAME

        // OTP_SELECTORS: name or id with "otp" or "totp", or a six-digit numeric input.
        val name = field.htmlAttributes["name"]?.lowercase() ?: ""
        val id = (field.htmlAttributes["id"] ?: field.idEntry ?: "").lowercase()
        if (name.contains("otp") || id.contains("otp")) return FieldKind.ONE_TIME_CODE
        if (field.htmlAttributes["inputmode"]?.lowercase() == "numeric" && field.htmlAttributes["maxlength"] == "6") return FieldKind.ONE_TIME_CODE

        // PASSWORD_SELECTORS, and Android's password input types.
        if (type == "password" || field.passwordInput) return FieldKind.PASSWORD

        // USERNAME_SELECTORS.
        val textual = type == null || type in TEXT_TYPES
        if (!textual) return FieldKind.OTHER
        if (type == "email") return FieldKind.USERNAME
        if (listOf("user", "email", "login").any { name.contains(it) || id.contains(it) }) return FieldKind.USERNAME
        return FieldKind.OTHER
    }

    /**
     * The login fields of a form, in screen order. findLoginFields: the
     * user name by its type and name, else by its label or hint text, else
     * the text field right before the first password field.
     */
    fun detect(fields: List<FieldFacts>): DetectedFields {
        val kinds = fields.map { classify(it) }
        val passwords = kinds.indices.filter { kinds[it] == FieldKind.PASSWORD }
        val newPasswords = kinds.indices.filter { kinds[it] == FieldKind.NEW_PASSWORD }
        val oneTimeCode = kinds.indices.firstOrNull { kinds[it] == FieldKind.ONE_TIME_CODE }
        var username = kinds.indices.firstOrNull { kinds[it] == FieldKind.USERNAME }
        val textInputs = fields.indices.filter { i ->
            val f = fields[i]
            val type = f.htmlAttributes["type"]?.lowercase()
            f.editable && f.visible && kinds[i] == FieldKind.OTHER && !f.passwordInput && (type == null || type in TEXT_TYPES)
        }
        if (username == null) {
            username = textInputs.firstOrNull { i -> USERNAME_WORDS.containsMatchIn(describedAs(fields[i])) }
        }
        val firstPassword = (passwords + newPasswords).minOrNull()
        if (username == null && firstPassword != null) {
            username = textInputs.lastOrNull { it < firstPassword }
        }
        return DetectedFields(username, passwords, newPasswords, oneTimeCode)
    }

    /** describedAs: the label-like text of a field. */
    private fun describedAs(field: FieldFacts): String = listOfNotNull(
        field.hint,
        field.htmlAttributes["aria-label"],
        field.htmlAttributes["placeholder"],
        field.htmlAttributes["label"],
    ).joinToString(" ")
}
