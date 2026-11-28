// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

package nl.conduction.keepiq.android.autofill

import nl.conduction.keepiq.shared.autofill.SaveResult
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.shadows.ShadowToast

/** A password the save did not keep is said, never dropped silently (keepiq#1300). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [35])
class AutofillToastTest {
    private val context = RuntimeEnvironment.getApplication()

    private fun toastOf(result: SaveResult?): String? {
        ShadowToast.reset()
        KeepiqAutofillService.toast(context, result)
        return ShadowToast.getTextOfLatestToast()
    }

    @Test
    fun aSharedLoginsPasswordThatWasNotUpdatedIsSaid() {
        assertEquals(
            "Password not updated: this login is shared with a partner organisation. Change it in the Keepiq app or the web app so everyone gets it.",
            toastOf(SaveResult.NOT_UPDATED),
        )
        assertEquals("Password not updated: this login is shared with you to view only.", toastOf(SaveResult.VIEW_ONLY))
        // Any other reason is not said to be a share; the app's edit form names it.
        assertEquals("Password not updated: Keepiq could not save it. Try again or change it in the Keepiq app.", toastOf(SaveResult.FAILED))
    }

    @Test
    fun aSiteThatSavesNothingStaysQuiet() {
        assertNull(toastOf(SaveResult.REFUSED))
        assertNull(toastOf(SaveResult.UNCHANGED))
        assertEquals("Password updated in Keepiq.", toastOf(SaveResult.UPDATED))
    }
}
