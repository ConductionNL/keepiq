// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.api

import io.ktor.client.engine.HttpClientEngine
import io.ktor.client.engine.okhttp.OkHttp
import okhttp3.CookieJar

actual fun platformHttpEngine(): HttpClientEngine = OkHttp.create {
    config {
        cookieJar(CookieJar.NO_COOKIES)
        followRedirects(false)
        followSslRedirects(false)
    }
}
