/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Vault unlock (high value).
 *
 * Drives the master-password lock screen and asserts the parts of the unlock
 * surface that genuinely work, while honestly flagging the parts that don't.
 *
 * LIVE FINDINGS (verified 2026-06-10 — these are the high-value coverage facts):
 *
 *   1. The instance is NOT in setup mode. The `SeedDevelopmentData` install
 *      repair step provisioned an active EncryptionSuite for admin, so the lock
 *      screen renders the UNLOCK form (one password field, "Unlock Doriath"),
 *      never the first-time setup form.
 *
 *   2. The seeded suite CANNOT be unlocked headlessly — and, in fact, not at
 *      all. The suite's AES-GCM private-key envelope was written by the PHP
 *      EncryptService with the dev master password `Oj`, but the browser's
 *      decryptPrivateKey() (PBKDF2-SHA256 600k → AES-256-GCM, envelope v1)
 *      rejects it with OperationError for `Oj` AND for any other password.
 *      i.e. the PHP-written envelope is INCOMPATIBLE with the JS envelope
 *      format, so no master password unlocks the seeded vault. Captured as
 *      test.fixme (bug #5 in the report).
 *
 *   3. A failed unlock does NOT surface an error to the UI. Entering a wrong
 *      (or the dev) password — which provably fails to decrypt — leaves the
 *      lock screen with NO "Wrong master password or decryption failed" note,
 *      even after >12s. handleUnlock()'s catch block sets `this.error`, but the
 *      note never renders. Captured as test.fixme (bug #6).
 *
 * The data-INDEPENDENT lock-screen surface (render + route gating + button
 * enable/disable) IS solid and is asserted as the real green coverage here.
 *
 * @e2e openspec/specs/encryption-suites/spec.md#user-views-lock-screen
 */
import { test, expect } from '@playwright/test'
import {
	gotoLockSettled,
	lockHeading,
	unlockVault,
} from './_workflow-helpers'

test.describe('Workflow: vault unlock — encryption-suites/spec.md', () => {
	test('lock screen renders in UNLOCK mode (admin owns a seeded active suite)', async ({ page }) => {
		const heading = await gotoLockSettled(page)
		expect(heading).toMatch(/Unlock Doriath/i)
		// Unlock mode has exactly one password field (setup mode has two).
		expect(await page.locator('.lock-screen input[type="password"]').count()).toBe(1)
		await expect(page.locator('.lock-screen__card')).toBeVisible()
	})

	test('the Unlock button is gated on a non-empty password', async ({ page }) => {
		await gotoLockSettled(page)
		const btn = page.locator('.lock-screen button')
			.filter({ hasText: /^\s*Unlock\s*$/i }).first()
		// Disabled with an empty field…
		await expect(btn).toBeDisabled()
		// …enabled once the master-password field has a value.
		await page.locator('.lock-screen input[type="password"]').first().fill('anything', { force: true })
		await expect(btn).toBeEnabled()
	})

	test('all secret routes redirect to the lock screen while locked (zero-knowledge gate)', async ({ page }) => {
		for (const route of ['secrets', 'secrets/some-id', 'folders/some-folder']) {
			// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud, and this
			// loop pays the cost three times. The lock heading is the readiness
			// signal.
			await page.goto(`/index.php/apps/doriath/${route}`, { waitUntil: 'domcontentloaded' })
			await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
			await expect(lockHeading(page)).toHaveText(/Unlock Doriath|Set up your master password/i)
			// No unlocked content leaks through the guard.
			await expect(page.locator('.secret-detail__card')).toHaveCount(0)
		}
	})

	/*
	 * FIXED — the seeded private-key envelope is JS-compatible and the suite
	 * certificate carries the matching public key, so decryptPrivateKey() +
	 * importPrivateKey() unlock the seeded suite with `Oj`. The Unlock button is
	 * clicked natively because the themed NcButton swallows Playwright's synthetic
	 * click (the earlier "router push is a no-op" diagnosis was actually the
	 * swallowed click — the navigation fires correctly).
	 */
	test('correct dev master password unlocks the seeded vault', async ({ page }) => {
		await unlockVault(page)
		await expect(page).not.toHaveURL(/\/lock(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('.lock-screen')).toHaveCount(0)
	})

	/*
	 * FIXED (was BUG #6) — a failed unlock surfaces an error note. The prior
	 * "no error renders" was the swallowed synthetic click (handleUnlock never
	 * ran). With a native click, handleUnlock's catch sets `this.error` and the
	 * note renders, and the user stays on the lock screen.
	 */
	test('wrong master password shows an error note and stays on the lock screen', async ({ page }) => {
		await gotoLockSettled(page)
		await page.locator('.lock-screen input[type="password"]').first().fill('definitely-not-the-master-pw', { force: true })
		await page.waitForTimeout(300)
		await page.evaluate(() => {
			const b = Array.from(document.querySelectorAll('.lock-screen button'))
				.find((x) => /Unlock/i.test(x.textContent || ''))
			if (b) {
				(b as HTMLElement).click()
			}
		})
		await expect(
			page.locator('.lock-screen').getByText(/Wrong master password|decryption failed/i),
		).toBeVisible({ timeout: 15_000 })
		await expect(lockHeading(page)).toHaveText(/Unlock Doriath/i)
	})

	/*
	 * FIXED (was BUG #7) — a successful unlock navigates into the vault. The prior
	 * "router push is a no-op" was a test artifact: Playwright's synthetic click on
	 * the themed Unlock button was swallowed, so handleUnlock never ran. With a
	 * native click the unlock fires, `$router.push(returnUrl)` navigates to the
	 * Dashboard, and the lock screen unmounts.
	 */
	test('successful unlock navigates into the vault (router push fires)', async ({ page }) => {
		await unlockVault(page)
		// Hash-mode router: after a successful unlock the router pushes off the
		// `#/lock` gate to the return route (default `#/` dashboard). Assert the
		// hash left the lock gate rather than the (path-form) URL prefix.
		await expect(page).toHaveURL(/#\/(?!lock)/, { timeout: 15_000 })
		await expect(page.locator('.lock-screen')).toHaveCount(0)
	})

	/*
	 * SETUP MODE not drivable — an active suite already exists for admin, so the
	 * first-time setup form never renders. Driving it would require a suite-less
	 * account (no UI to revoke/delete the admin suite, and doing it out-of-band
	 * would break the other specs). The setup form's structure + 12-char strength
	 * gating is already covered by the spec-coverage lock-screen spec.
	 */
	test.fixme('first-time setup creates a suite and unlocks (needs a suite-less account)', async () => {
		// Intentionally empty — see block comment for the precise blocker.
	})
})
