// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofilltest;

import android.app.Activity;
import android.os.Bundle;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.View;
import android.view.autofill.AutofillManager;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;
import nl.conduction.keepiq.android.test.R;

/**
 * The "other app" of SystemAutofillTest: a two-step login form (user name
 * and password, then a one-time code) or a sign-up form, in plain views
 * with Android autofill hints. The status line shows what was filled in,
 * so the test can read it: the user name, the password's length and the
 * code.
 *
 * Java, not Kotlin: this runs in the test APK's own process, which carries
 * no Kotlin runtime (the app under test does).
 */
public class AutofillFormActivity extends Activity {
    private EditText username;
    private EditText password;
    private EditText otp;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        final boolean signUp = "signup".equals(getIntent().getStringExtra("mode"));
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 96, 48, 48);
        status = new TextView(this);
        status.setId(R.id.status);
        status.setTextSize(18f);
        // The button sits above the fields, so the autofill dropdown never covers it.
        Button submit = new Button(this);
        submit.setId(R.id.submit);
        submit.setText(signUp ? "Create account" : "Next");
        username = new EditText(this);
        username.setId(R.id.username);
        username.setHint(signUp ? "Email" : "User name");
        username.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS);
        username.setAutofillHints(signUp ? "newUsername" : View.AUTOFILL_HINT_USERNAME);
        password = new EditText(this);
        password.setId(R.id.password);
        password.setHint(signUp ? "New password" : "Password");
        password.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        password.setAutofillHints(signUp ? "newPassword" : View.AUTOFILL_HINT_PASSWORD);
        otp = new EditText(this);
        otp.setId(R.id.otp);
        otp.setHint("Code from your authenticator");
        otp.setInputType(InputType.TYPE_CLASS_NUMBER);
        otp.setAutofillHints("2faAppOTPCode");
        otp.setVisibility(View.GONE);
        root.addView(status);
        root.addView(submit);
        root.addView(username);
        root.addView(password);
        root.addView(otp);
        setContentView(root);
        TextWatcher watcher = new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence s, int start, int before, int count) { }
            @Override public void afterTextChanged(Editable s) { show(); }
        };
        username.addTextChangedListener(watcher);
        password.addTextChangedListener(watcher);
        otp.addTextChangedListener(watcher);
        show();
        final AutofillManager autofill = getSystemService(AutofillManager.class);
        submit.setOnClickListener(v -> {
            if (signUp) {
                autofill.commit();
                finish();
            } else {
                username.setVisibility(View.GONE);
                password.setVisibility(View.GONE);
                otp.setVisibility(View.VISIBLE);
                otp.requestFocus();
                otp.post(() -> autofill.requestAutofill(otp));
            }
        });
        username.requestFocus();
        username.postDelayed(() -> autofill.requestAutofill(username), 500);
    }

    private void show() {
        status.setText("user=" + username.getText() + " password=" + password.getText().length() + " code=" + otp.getText());
    }
}
