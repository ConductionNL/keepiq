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
	DEV_MASTER_PASSWORD,
	gotoLockSettled,
	lockHeading,
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
			await page.goto(`/index.php/apps/doriath/${route}`, { waitUntil: 'networkidle' })
			await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
			await expect(lockHeading(page)).toHaveText(/Unlock Doriath|Set up your master password/i)
			// No unlocked content leaks through the guard.
			await expect(page.locator('.secret-detail__card')).toHaveCount(0)
		}
	})

	/*
	 * BUG #5 — the seeded PHP-written private-key envelope is JS-incompatible:
	 * decryptPrivateKey() rejects the dev master password `Oj` (and every other)
	 * with OperationError, so the seeded vault is permanently un-unlockable in the
	 * browser. Un-fixme once the PHP EncryptService and the JS envelope/PBKDF2
	 * formats agree (same version header, salt/IV layout, iteration count, and
	 * GCM tag handling) so `Oj` decrypts the seeded suite.
	 */
	test.fixme('correct dev master password unlocks the seeded vault', async ({ page }) => {
		await gotoLockSettled(page)
		await page.locator('.lock-screen input[type="password"]').first().fill(DEV_MASTER_PASSWORD, { force: true })
		await page.locator('.lock-screen button').filter({ hasText: /^\s*Unlock\s*$/i }).first().click({ force: true })
		// Expected: vault unlocks and we leave the lock screen.
		await expect(page).not.toHaveURL(/\/lock(\?|$)/, { timeout: 15_000 })
		await expect(page.locator('.lock-screen')).toHaveCount(0)
	})

	/*
	 * BUG #6 — a failed unlock surfaces no error. Entering a wrong password (which
	 * provably fails to decrypt) leaves the lock screen with no error note even
	 * after >12s, so the user gets no feedback. Un-fixme once handleUnlock()'s
	 * error note renders on a decrypt failure.
	 */
	test.fixme('wrong master password shows an error note and stays on the lock screen', async ({ page }) => {
		await gotoLockSettled(page)
		await page.locator('.lock-screen input[type="password"]').first().fill('definitely-not-the-master-pw', { force: true })
		await page.locator('.lock-screen button').filter({ hasText: /^\s*Unlock\s*$/i }).first().click({ force: true })
		await expect(
			page.locator('.lock-screen').getByText(/Wrong master password|decryption failed/i),
		).toBeVisible({ timeout: 15_000 })
		await expect(lockHeading(page)).toHaveText(/Unlock Doriath/i)
	})

	/*
	 * BUG #7 — even when unlock SUCCEEDS at the crypto layer, LockScreen.handleUnlock's
	 * `this.$router.push(returnUrl)` is a no-op in the manifest-shell: the URL stays
	 * on `/lock?returnUrl=%2F` and the lock screen stays mounted, stranding the user.
	 * (Reproduced by forcing a key into the session — the navigation never fires.)
	 * Un-fixme once a successful unlock navigates into a vault route.
	 */
	test.fixme('successful unlock navigates into the vault (router push fires)', async () => {
		// Intentionally empty — blocked behind bug #5 (cannot reach a successful
		// unlock to observe the navigation); see block comment for the navigation
		// defect itself.
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
