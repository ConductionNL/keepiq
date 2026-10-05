// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofilltest

import android.app.Activity
import android.os.Bundle
import android.text.Editable
import android.text.InputType
import android.text.TextWatcher
import android.view.View
import android.view.autofill.AutofillManager
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import nl.conduction.keepiq.android.test.R

/**
 * The "other app" of SystemAutofillTest: a two-step login form (user name
 * and password, then a one-time code) or a sign-up form, in plain views
 * with Android autofill hints. The status line shows what was filled in,
 * so the test can read it: the user name, the password's length and the
 * code.
 */
class AutofillFormActivity : Activity() {
    private lateinit var username: EditText
    private lateinit var password: EditText
    private lateinit var otp: EditText
    private lateinit var status: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val signUp = intent.getStringExtra(EXTRA_MODE) == MODE_SIGN_UP
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(48, 96, 48, 48)
        }
        status = TextView(this).apply { id = R.id.status; textSize = 18f }
        // The button sits above the fields, so the autofill dropdown never covers it.
        val submit = Button(this).apply {
            id = R.id.submit
            text = if (signUp) "Create account" else "Next"
        }
        username = EditText(this).apply {
            id = R.id.username
            hint = if (signUp) "Email" else "User name"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS
            setAutofillHints(if (signUp) "newUsername" else View.AUTOFILL_HINT_USERNAME)
        }
        password = EditText(this).apply {
            id = R.id.password
            hint = if (signUp) "New password" else "Password"
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            setAutofillHints(if (signUp) "newPassword" else View.AUTOFILL_HINT_PASSWORD)
        }
        otp = EditText(this).apply {
            id = R.id.otp
            hint = "Code from your authenticator"
            inputType = InputType.TYPE_CLASS_NUMBER
            setAutofillHints("2faAppOTPCode")
            visibility = View.GONE
        }
        listOf(status, submit, username, password, otp).forEach { root.addView(it) }
        setContentView(root)
        val watcher = object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) = Unit
            override fun afterTextChanged(s: Editable?) = show()
        }
        listOf(username, password, otp).forEach { it.addTextChangedListener(watcher) }
        show()
        submit.setOnClickListener {
            val autofill = getSystemService(AutofillManager::class.java)
            if (signUp) {
                autofill.commit()
                finish()
            } else {
                username.visibility = View.GONE
                password.visibility = View.GONE
                otp.visibility = View.VISIBLE
                otp.requestFocus()
                otp.post { autofill.requestAutofill(otp) }
            }
        }
        username.requestFocus()
        username.postDelayed({ getSystemService(AutofillManager::class.java).requestAutofill(username) }, 500)
    }

    private fun show() {
        status.text = "user=${username.text} password=${password.text.length} code=${otp.text}"
    }

    companion object {
        const val EXTRA_MODE = "mode"
        const val MODE_LOGIN = "login"
        const val MODE_SIGN_UP = "signup"
    }
}
