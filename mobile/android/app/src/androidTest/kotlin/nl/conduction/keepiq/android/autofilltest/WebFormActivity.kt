// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofilltest

import android.annotation.SuppressLint
import android.app.Activity
import android.net.http.SslError
import android.os.Bundle
import android.webkit.SslErrorHandler
import android.webkit.WebView
import android.webkit.WebViewClient
import nl.conduction.keepiq.android.test.R

/**
 * The web-domain path of SystemAutofillTest: the test server's login page
 * in a WebView. The WebView reports the page's domain to the autofill
 * service. The e2e build of Keepiq trusts this test app as a browser
 * (src/e2e/AndroidManifest.xml); no other build does.
 */
class WebFormActivity : Activity() {
    @SuppressLint("SetJavaScriptEnabled", "WebViewClientOnReceivedSslError")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val web = WebView(this).apply {
            id = R.id.web
            settings.javaScriptEnabled = true
            webViewClient = object : WebViewClient() {
                // The test server's certificate is made per run; this test app only.
                override fun onReceivedSslError(view: WebView, handler: SslErrorHandler, error: SslError) = handler.proceed()
            }
        }
        setContentView(web)
        web.loadUrl(intent.getStringExtra(EXTRA_URL) ?: "about:blank")
    }

    companion object {
        const val EXTRA_URL = "url"
    }
}
