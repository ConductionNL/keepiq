// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.passkey

import android.content.ActivityNotFoundException
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import nl.conduction.keepiq.android.R

/** Whether Keepiq is an enabled Credential Manager provider (Android 14 and later). */
object PasskeyProvider {
    fun isEnabled(context: Context): Boolean {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.UPSIDE_DOWN_CAKE) return false
        return runCatching {
            context.getSystemService(android.credentials.CredentialManager::class.java)
                ?.isEnabledCredentialProviderService(ComponentName(context, KeepiqCredentialProviderService::class.java)) == true
        }.getOrDefault(false)
    }

    /** Android's own screen for choosing password and passkey providers. */
    fun openSettings(context: Context) {
        try {
            context.startActivity(Intent("android.settings.CREDENTIAL_PROVIDER").setData(Uri.parse("package:${context.packageName}")))
        } catch (e: ActivityNotFoundException) {
            context.startActivity(Intent(android.provider.Settings.ACTION_SETTINGS))
        }
    }
}

/**
 * The passkeys part of Keepiq's autofill settings (mobile-passkey-provider,
 * "Android before version 14"): on 14 and later whether Keepiq is the
 * provider and the way to choose it; before 14 that passkeys need Android
 * 14 while passwords and codes still fill.
 */
@Composable
fun PasskeySettings(sdk: Int = Build.VERSION.SDK_INT, enabled: Boolean) {
    val context = LocalContext.current
    Text(stringResource(R.string.passkeys_title), style = MaterialTheme.typography.titleMedium, modifier = Modifier.semantics { heading() })
    when {
        sdk < Build.VERSION_CODES.UPSIDE_DOWN_CAKE ->
            Text(stringResource(R.string.passkeys_needs_android_14), modifier = Modifier.testTag("passkeyStatus"))
        enabled -> Text(stringResource(R.string.passkeys_status_on), modifier = Modifier.testTag("passkeyStatus"))
        else -> {
            Text(stringResource(R.string.passkeys_status_off), modifier = Modifier.testTag("passkeyStatus"))
            Button(onClick = { PasskeyProvider.openSettings(context) }, modifier = Modifier.fillMaxWidth().testTag("passkeyChoose")) {
                Text(stringResource(R.string.passkeys_choose))
            }
        }
    }
}
