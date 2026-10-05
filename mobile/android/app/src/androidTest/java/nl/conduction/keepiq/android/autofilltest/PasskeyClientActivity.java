// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofilltest;

import android.app.Activity;
import android.credentials.CreateCredentialException;
import android.credentials.CreateCredentialRequest;
import android.credentials.CreateCredentialResponse;
import android.credentials.CredentialManager;
import android.credentials.CredentialOption;
import android.credentials.GetCredentialException;
import android.credentials.GetCredentialRequest;
import android.credentials.GetCredentialResponse;
import android.graphics.drawable.Icon;
import android.os.Bundle;
import android.os.CancellationSignal;
import android.os.OutcomeReceiver;
import android.widget.LinearLayout;
import android.widget.TextView;
import nl.conduction.keepiq.android.test.R;
import org.json.JSONObject;

/**
 * The "other app" of PasskeyProviderTest: an app that asks Android's
 * Credential Manager to create a passkey or to sign in with one, as any app
 * with a WebAuthn login does. The status line shows the answer, so the test
 * reads it: the run id the test passed, then "created:" or "got:" with the
 * response JSON, or "error:" with the exception type. The run id keeps the
 * test from reading the previous call's answer while this one starts.
 *
 * Java and the framework API (android.credentials, Android 14), not the
 * androidx library: this runs in the test APK's own process, which carries
 * neither the Kotlin runtime nor the app's libraries. The bundles are the
 * ones androidx.credentials 1.3.0 writes (CreatePublicKeyCredentialRequest,
 * GetPublicKeyCredentialOption), which the provider reads back with androidx.
 */
public class PasskeyClientActivity extends Activity {
    private static final String TYPE = "androidx.credentials.TYPE_PUBLIC_KEY_CREDENTIAL";
    private static final String SUBTYPE = "androidx.credentials.BUNDLE_KEY_SUBTYPE";
    private static final String REQUEST_JSON = "androidx.credentials.BUNDLE_KEY_REQUEST_JSON";
    private static final String CLIENT_DATA_HASH = "androidx.credentials.BUNDLE_KEY_CLIENT_DATA_HASH";
    private static final String AUTO_SELECT = "androidx.credentials.BUNDLE_KEY_IS_AUTO_SELECT_ALLOWED";
    private static final String PREFER_IMMEDIATELY = "androidx.credentials.BUNDLE_KEY_PREFER_IMMEDIATELY_AVAILABLE_CREDENTIALS";
    private static final String DISPLAY_INFO = "androidx.credentials.BUNDLE_KEY_REQUEST_DISPLAY_INFO";
    private static final String USER_ID = "androidx.credentials.BUNDLE_KEY_USER_ID";
    private static final String USER_DISPLAY_NAME = "androidx.credentials.BUNDLE_KEY_USER_DISPLAY_NAME";
    private static final String TYPE_ICON = "androidx.credentials.BUNDLE_KEY_CREDENTIAL_TYPE_ICON";
    private static final String REGISTRATION_JSON = "androidx.credentials.BUNDLE_KEY_REGISTRATION_RESPONSE_JSON";
    private static final String AUTHENTICATION_JSON = "androidx.credentials.BUNDLE_KEY_AUTHENTICATION_RESPONSE_JSON";

    private TextView status;
    private String run = "";

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        LinearLayout root = new LinearLayout(this);
        root.setOrientation(LinearLayout.VERTICAL);
        root.setPadding(48, 96, 48, 48);
        TextView title = new TextView(this);
        title.setTextSize(20f);
        title.setText("Passkey test app");
        status = new TextView(this);
        status.setId(R.id.status);
        status.setTextSize(12f);
        root.addView(title);
        root.addView(status);
        setContentView(root);

        run = String.valueOf(getIntent().getStringExtra("run"));
        status.setText(run + "|waiting");
        String mode = getIntent().getStringExtra("mode");
        String json = getIntent().getStringExtra("request");
        try {
            if ("create".equals(mode)) {
                create(json);
            } else {
                get(json);
            }
        } catch (Exception e) {
            status.setText(run + "|error:" + e.getClass().getSimpleName() + ":" + e.getMessage());
        }
    }

    private Bundle requestBundle(String subtype, String json) {
        Bundle bundle = new Bundle();
        bundle.putString(SUBTYPE, subtype);
        bundle.putString(REQUEST_JSON, json);
        bundle.putByteArray(CLIENT_DATA_HASH, null);
        bundle.putBoolean(AUTO_SELECT, false);
        return bundle;
    }

    private void create(String json) throws Exception {
        JSONObject user = new JSONObject(json).getJSONObject("user");
        Bundle credentialData = requestBundle("androidx.credentials.BUNDLE_VALUE_SUBTYPE_CREATE_PUBLIC_KEY_CREDENTIAL_REQUEST", json);
        credentialData.putBoolean(PREFER_IMMEDIATELY, false);
        Bundle displayInfo = new Bundle();
        displayInfo.putCharSequence(USER_ID, user.getString("name"));
        displayInfo.putCharSequence(USER_DISPLAY_NAME, user.optString("displayName", ""));
        displayInfo.putParcelable(TYPE_ICON, Icon.createWithResource(this, android.R.drawable.ic_lock_lock));
        credentialData.putBundle(DISPLAY_INFO, displayInfo);
        Bundle candidateQueryData = requestBundle("androidx.credentials.BUNDLE_VALUE_SUBTYPE_CREATE_PUBLIC_KEY_CREDENTIAL_REQUEST", json);
        CreateCredentialRequest request = new CreateCredentialRequest.Builder(TYPE, credentialData, candidateQueryData)
                .setAlwaysSendAppInfoToProvider(true)
                .build();
        CredentialManager manager = getSystemService(CredentialManager.class);
        manager.createCredential(this, request, new CancellationSignal(), getMainExecutor(),
                new OutcomeReceiver<CreateCredentialResponse, CreateCredentialException>() {
                    @Override
                    public void onResult(CreateCredentialResponse response) {
                        status.setText(run + "|created:" + response.getData().getString(REGISTRATION_JSON));
                    }

                    @Override
                    public void onError(CreateCredentialException e) {
                        status.setText(run + "|error:" + e.getType() + ":" + e.getMessage());
                    }
                });
    }

    private void get(String json) {
        Bundle data = new Bundle();
        data.putBoolean(PREFER_IMMEDIATELY, false);
        Bundle option = requestBundle("androidx.credentials.BUNDLE_VALUE_SUBTYPE_GET_PUBLIC_KEY_CREDENTIAL_OPTION", json);
        GetCredentialRequest request = new GetCredentialRequest.Builder(data)
                .addCredentialOption(new CredentialOption.Builder(TYPE, option, option).build())
                .build();
        CredentialManager manager = getSystemService(CredentialManager.class);
        manager.getCredential(this, request, new CancellationSignal(), getMainExecutor(),
                new OutcomeReceiver<GetCredentialResponse, GetCredentialException>() {
                    @Override
                    public void onResult(GetCredentialResponse response) {
                        status.setText(run + "|got:" + response.getCredential().getData().getString(AUTHENTICATION_JSON));
                    }

                    @Override
                    public void onError(GetCredentialException e) {
                        status.setText(run + "|error:" + e.getType() + ":" + e.getMessage());
                    }
                });
    }
}
