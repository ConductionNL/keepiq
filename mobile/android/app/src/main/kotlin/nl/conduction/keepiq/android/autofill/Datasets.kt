// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

@file:Suppress("DEPRECATION")

package nl.conduction.keepiq.android.autofill

import android.annotation.SuppressLint
import android.app.PendingIntent
import android.app.slice.Slice
import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.graphics.drawable.Icon
import android.os.Build
import android.service.autofill.Dataset
import android.service.autofill.InlinePresentation
import android.view.autofill.AutofillId
import android.view.autofill.AutofillValue
import android.view.inputmethod.InlineSuggestionsRequest
import android.widget.RemoteViews
import androidx.autofill.inline.v1.InlineSuggestionUi
import nl.conduction.keepiq.android.R

/**
 * The entries Keepiq shows in a form: the dropdown under the field, and on
 * Android 11+ the inline suggestions in the keyboard when the keyboard
 * supports them (task 4.1).
 */
class Datasets(private val context: Context, private val inline: InlineSuggestionsRequest?) {
    private var inlineUsed = 0

    /** A dropdown row: a title and an optional second line. */
    fun dropdown(title: String, subtitle: String?): RemoteViews =
        RemoteViews(context.packageName, R.layout.autofill_item).apply {
            setTextViewText(R.id.autofill_title, title)
            if (subtitle.isNullOrEmpty()) {
                setViewVisibility(R.id.autofill_subtitle, android.view.View.GONE)
            } else {
                setTextViewText(R.id.autofill_subtitle, subtitle)
            }
        }

    /** The inline chip for the next suggestion, while the keyboard has room for one. */
    @SuppressLint("NewApi")
    fun inline(title: String, subtitle: String?, attribution: PendingIntent): InlinePresentation? {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) return null
        val request = inline ?: return null
        if (inlineUsed >= request.maxSuggestionCount) return null
        val specs = request.inlinePresentationSpecs
        if (specs.isEmpty()) return null
        val spec = specs[minOf(inlineUsed, specs.size - 1)]
        inlineUsed++
        val content = InlineSuggestionUi.newContentBuilder(attribution)
            .setTitle(title)
            .setStartIcon(Icon.createWithResource(context, R.drawable.ic_autofill_key))
            .setContentDescription(listOfNotNull(title, subtitle).joinToString(", "))
        if (!subtitle.isNullOrEmpty()) content.setSubtitle(subtitle)
        val slice: Slice = content.build().slice
        return InlinePresentation(slice, spec, false)
    }

    /** What a long press on an inline chip opens: Keepiq's autofill settings. */
    fun attribution(): PendingIntent = PendingIntent.getActivity(
        context, 0, Intent(context, AutofillSettingsActivity::class.java),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
    )

    /** A dataset that fills [values] at once. */
    fun filled(title: String, subtitle: String?, values: List<Pair<AutofillId, String>>): Dataset? {
        if (values.isEmpty()) return null
        val presentation = dropdown(title, subtitle)
        val inlineChip = inline(title, subtitle, attribution())
        val builder = Dataset.Builder(presentation)
        for ((id, value) in values) {
            if (inlineChip != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                builder.setValue(id, AutofillValue.forText(value), presentation, inlineChip)
            } else {
                builder.setValue(id, AutofillValue.forText(value), presentation)
            }
        }
        return builder.build()
    }

    /** A dataset that opens [authentication] first: the locked "Unlock Keepiq" entry, without account names. */
    fun locked(ids: List<AutofillId>, authentication: IntentSender): Dataset? {
        if (ids.isEmpty()) return null
        val title = context.getString(R.string.autofill_unlock)
        val presentation = dropdown(title, context.getString(R.string.autofill_unlock_hint))
        val inlineChip = inline(title, null, attribution())
        val builder = Dataset.Builder(presentation)
        for (id in ids) {
            if (inlineChip != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                builder.setValue(id, null, presentation, inlineChip)
            } else {
                builder.setValue(id, null, presentation)
            }
        }
        builder.setAuthentication(authentication)
        return builder.build()
    }
}
