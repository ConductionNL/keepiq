// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import nl.conduction.keepiq.android.KeepiqApp

/** "Never" in the system's save offer: the site or app goes on the never-save list (task 4.2). */
class NeverSaveReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val site = intent.getStringExtra(EXTRA_SITE) ?: return
        (context.applicationContext as KeepiqApp).autofill.neverSave.set(site, true)
    }

    companion object {
        const val EXTRA_SITE = "nl.conduction.keepiq.autofill.SITE"
    }
}
