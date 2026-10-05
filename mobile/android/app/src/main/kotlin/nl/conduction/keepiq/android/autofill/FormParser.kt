// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.app.assist.AssistStructure
import android.text.InputType
import android.view.View
import android.view.autofill.AutofillId
import nl.conduction.keepiq.shared.autofill.DetectedFields
import nl.conduction.keepiq.shared.autofill.FieldDetector
import nl.conduction.keepiq.shared.autofill.FieldFacts

/** One input field of a form, with what the user typed in it (for a save request). */
class FormField(val id: AutofillId, val facts: FieldFacts, val text: String?, val webDomain: String?, val webScheme: String?)

/** A form as the system hands it to the service: who asks, its fields and which are which. */
class ParsedForm(
    val packageName: String,
    val fields: List<FormField>,
    val detected: DetectedFields,
) {
    /** The domain a browser or WebView reported for the form, the one nearest the login fields. */
    val webDomain: String? get() = loginFields().firstNotNullOfOrNull { it.webDomain } ?: fields.firstNotNullOfOrNull { it.webDomain }

    val webScheme: String? get() = fields.firstNotNullOfOrNull { it.webScheme }

    val username: FormField? get() = detected.username?.let { fields[it] }
    val passwords: List<FormField> get() = (detected.passwords + detected.newPasswords).sorted().map { fields[it] }
    val oneTimeCode: FormField? get() = detected.oneTimeCode?.let { fields[it] }

    /** The password the user typed: a new password before the current one, as on a change form. */
    val typedPassword: String?
        get() = (detected.newPasswords + detected.passwords).map { fields[it].text }.firstOrNull { !it.isNullOrEmpty() }

    private fun loginFields() = listOfNotNull(username, oneTimeCode) + passwords
}

/**
 * Reads an [AssistStructure] (task 4.1): every editable view, with its
 * autofill hints, the HTML attributes a browser or WebView reports in
 * htmlInfo, its view id, hint text and input type, and the web domain on
 * the way down.
 */
object FormParser {
    fun parse(structure: AssistStructure): ParsedForm {
        val fields = ArrayList<FormField>()
        for (i in 0 until structure.windowNodeCount) {
            walk(structure.getWindowNodeAt(i).rootViewNode, null, null, fields)
        }
        return ParsedForm(structure.activityComponent.packageName, fields, FieldDetector.detect(fields.map { it.facts }))
    }

    private fun walk(node: AssistStructure.ViewNode, domain: String?, scheme: String?, out: MutableList<FormField>) {
        val webDomain = node.webDomain?.takeIf { it.isNotEmpty() } ?: domain
        val webScheme = node.webScheme?.takeIf { it.isNotEmpty() } ?: scheme
        val id = node.autofillId
        val html = node.htmlInfo
        val isInput = html?.tag?.equals("input", ignoreCase = true) == true
        if (id != null && (node.autofillType == View.AUTOFILL_TYPE_TEXT || isInput)) {
            val attributes = html?.attributes?.associate { it.first.lowercase() to (it.second ?: "") } ?: emptyMap()
            val inputType = node.inputType
            val variation = inputType and InputType.TYPE_MASK_VARIATION
            val klass = inputType and InputType.TYPE_MASK_CLASS
            val password = (klass == InputType.TYPE_CLASS_TEXT && variation in PASSWORD_TEXT_VARIATIONS) ||
                (klass == InputType.TYPE_CLASS_NUMBER && variation == InputType.TYPE_NUMBER_VARIATION_PASSWORD)
            val facts = FieldFacts(
                autofillHints = node.autofillHints?.toList() ?: emptyList(),
                htmlTag = html?.tag,
                htmlAttributes = attributes,
                idEntry = node.idEntry,
                hint = node.hint,
                passwordInput = password,
                numberInput = klass == InputType.TYPE_CLASS_NUMBER || klass == InputType.TYPE_CLASS_PHONE,
                editable = node.isEnabled && (node.autofillType == View.AUTOFILL_TYPE_TEXT),
                visible = node.visibility == View.VISIBLE,
            )
            val text = node.autofillValue?.takeIf { it.isText }?.textValue?.toString()
            out += FormField(id, facts, text, webDomain, webScheme)
        }
        for (i in 0 until node.childCount) walk(node.getChildAt(i), webDomain, webScheme, out)
    }

    private val PASSWORD_TEXT_VARIATIONS = setOf(
        InputType.TYPE_TEXT_VARIATION_PASSWORD,
        InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD,
        InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD,
    )
}
