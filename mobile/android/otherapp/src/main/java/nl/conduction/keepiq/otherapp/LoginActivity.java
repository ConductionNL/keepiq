// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.otherapp;

import android.app.Activity;
import android.os.Bundle;
import android.text.Editable;
import android.text.InputType;
import android.text.TextWatcher;
import android.view.View;
import android.view.autofill.AutofillManager;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.TextView;

/**
 * A user name and password form with Android autofill hints. The status
 * line shows what was filled in (the user name and the password's length),
 * so PackageVisibilityTest can read it.
 */
public class LoginActivity extends Activity {
    private EditText username;
    private EditText password;
    private TextView status;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 96, 48, 48);
        status = new TextView(this);
        status.setId(R.id.status);
        status.setTextSize(18f);
        username = new EditText(this);
        username.setId(R.id.username);
        username.setHint("User name");
        username.setInputType(InputType.TYPE_CLASS_TEXT);
        username.setAutofillHints(View.AUTOFILL_HINT_USERNAME);
        password = new EditText(this);
        password.setId(R.id.password);
        password.setHint("Password");
        password.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        password.setAutofillHints(View.AUTOFILL_HINT_PASSWORD);
        root.addView(status);
        root.addView(username);
        root.addView(password);
        setContentView(root);
        TextWatcher watcher = new TextWatcher() {
            @Override public void beforeTextChanged(CharSequence s, int start, int count, int after) { }
            @Override public void onTextChanged(CharSequence s, int start, int before, int count) { }
            @Override public void afterTextChanged(Editable s) { show(); }
        };
        username.addTextChangedListener(watcher);
        password.addTextChangedListener(watcher);
        show();
        final AutofillManager autofill = getSystemService(AutofillManager.class);
        username.requestFocus();
        username.postDelayed(() -> autofill.requestAutofill(username), 500);
    }

    private void show() {
        status.setText("user=" + username.getText() + " password=" + password.getText().length());
    }
}
