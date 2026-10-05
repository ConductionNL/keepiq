// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofilltest;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.net.http.SslError;
import android.os.Bundle;
import android.webkit.SslErrorHandler;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import nl.conduction.keepiq.android.test.R;

/**
 * The web-domain path of SystemAutofillTest: the test server's login page
 * in a WebView, which reports the page's domain to the autofill service.
 * The e2e build of Keepiq trusts this test app as a browser
 * (src/e2e/AndroidManifest.xml); no other build does. Java, for the same
 * reason as AutofillFormActivity.
 */
public class WebFormActivity extends Activity {
    @SuppressLint({"SetJavaScriptEnabled", "WebViewClientOnReceivedSslError"})
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        WebView web = new WebView(this);
        web.setId(R.id.web);
        web.getSettings().setJavaScriptEnabled(true);
        web.setWebViewClient(new WebViewClient() {
            // The test server's certificate is made per run; this test app only.
            @Override
            public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
                handler.proceed();
            }
        });
        setContentView(web);
        String url = getIntent().getStringExtra("url");
        web.loadUrl(url != null ? url : "about:blank");
    }
}
