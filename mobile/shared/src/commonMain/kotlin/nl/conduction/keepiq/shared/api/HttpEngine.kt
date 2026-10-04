// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.shared.api

import io.ktor.client.engine.HttpClientEngine

/**
 * The platform HTTP engine, set up so it never stores or sends a cookie and
 * never follows a redirect (design D3): OkHttp on Android and jvm, the
 * NSURLSession engine on iOS.
 */
expect fun platformHttpEngine(): HttpClientEngine
