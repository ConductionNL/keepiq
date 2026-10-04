// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.api

import io.ktor.client.engine.HttpClientEngine
import io.ktor.client.engine.darwin.Darwin
import platform.Foundation.NSHTTPCookieAcceptPolicy

// NSURLSession shares HTTPCookieStorage by default; switch it off so a
// Nextcloud session cookie never rides along.
actual fun platformHttpEngine(): HttpClientEngine = Darwin.create {
    configureSession {
        setHTTPCookieStorage(null)
        setHTTPShouldSetCookies(false)
        setHTTPCookieAcceptPolicy(NSHTTPCookieAcceptPolicy.NSHTTPCookieAcceptPolicyNever)
    }
}
