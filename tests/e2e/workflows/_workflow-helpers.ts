/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the Doriath DEEP, data-dependent workflow e2e layer.
 *
 * Unlike the spec-coverage suite (which only asserts the lock screen and the
 * router-guard redirect), these workflow specs DRIVE the vault: they unlock
 * with the development master password, and exercise the secret read / delete
 * surfaces and the encryption round-trip through the app's real REST API and
 * WebCrypto primitives.
 *
 * Environment assumptions (verified live, 2026-06-10):
 *   - The doriath app is enabled and serving at /index.php/apps/doriath/.
 *   - The admin user owns ONE active EncryptionSuite, seeded by the
 *     `SeedDevelopmentData` install repair step with the known development
 *     master password `Oj` (a 2-char password — valid for UNLOCK, which has no
 *     strength gate; it would NOT pass the 12-char setup strength floor).
 *   - The suite's private key (PKCS#8) is AES-GCM-wrapped with that password
 *     and decrypts in-browser; the suite's `certificate` field is a full
 *     CA-signed X.509 certificate.
 */
import { type Page, expect } from '@playwright/test'

export const APP_BASE = '/index.php/apps/doriath'

/**
 * The known development master password. Seeded by
 * lib/Repair/SeedDevelopmentData.php (DEV_MASTER_PASSWORD = 'Oj').
 */
export const DEV_MASTER_PASSWORD = 'Oj'

/** The lock-screen heading locator (covers both setup + unlock copy). */
export function lockHeading(page: Page) {
	return page.locator('h1.lock-screen__title, .lock-screen h1').first()
}

/**
 * Open the Doriath lock screen and wait for the suite-state fetch to settle.
 *
 * LockScreen.created() runs fetchSuite() asynchronously; until it resolves,
 * `currentSuite` is null and the heading renders the SETUP copy, then flips to
 * the UNLOCK copy once the active suite arrives. Waiting only for "either"
 * heading races that flip, so we wait for the /api/v1/suites response and then
 * for the heading to STABILISE (two consecutive identical reads) before
 * returning. When admin owns an active suite this lands on "Unlock Doriath".
 */
export async function gotoLockSettled(page: Page): Promise<string> {
	const suitesResp = page.waitForResponse(
		(r) => /\/api\/v1\/suites(\?|$)/.test(r.url()),
		{ timeout: 20_000 },
	).catch(() => null)
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'networkidle' })
	await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
	await suitesResp
	// Stabilise: poll the heading until two consecutive reads agree, so we never
	// observe the transient setup→unlock flip mid-fetch.
	let prev = ''
	for (let i = 0; i < 20; i++) {
		const cur = (await lockHeading(page).textContent())?.trim() ?? ''
		if (cur && cur === prev) break
		prev = cur
		await page.waitForTimeout(250)
	}
	return (await lockHeading(page).textContent())?.trim() ?? ''
}

/**
 * Fill the unlock password and click Unlock. The NcPasswordField wraps the
 * input; `fill({ force: true })` reliably triggers the v-model update so the
 * Unlock button enables.
 *
 * Returns the lock-screen error text (empty string when none) after a settle.
 */
export async function attemptUnlock(page: Page, password: string): Promise<string> {
	const input = page.locator('.lock-screen input[type="password"]').first()
	await input.fill(password, { force: true })
	const btn = page.locator('.lock-screen button')
		.filter({ hasText: /^\s*Unlock\s*$/i }).first()
	await expect(btn).toBeEnabled()
	await btn.click({ force: true })
	// Allow the WebCrypto decrypt + (attempted) router push to run.
	await page.waitForTimeout(4_000)
	const errCount = await page.locator('.lock-screen')
		.getByText(/Wrong master password|decryption failed/i).count().catch(() => 0)
	if (errCount > 0) {
		return (await page.locator('.lock-screen')
			.getByText(/Wrong master password|decryption failed/i).first()
			.textContent())?.trim() ?? 'error'
	}
	return ''
}

/**
 * Drive the lock screen all the way into an UNLOCKED vault session and wait for
 * a vault route to render. Returns when the lock screen is gone.
 *
 * Two harness facts make this reliable:
 *   - The themed `NcButton` swallows Playwright's synthetic `.click()`, so we
 *     dispatch a native `HTMLButtonElement.click()` via `page.evaluate` to fire
 *     the Vue `@click="handleUnlock"` handler (the same trick documented for the
 *     themed-submit swallow elsewhere in the fleet).
 *   - `session.unlock()` decrypts the seeded AES-GCM private-key envelope with
 *     the dev master password in-browser (PBKDF2 600k → AES-256-GCM), imports
 *     the RSA-OAEP key, and `handleUnlock` then pushes to the return URL.
 *
 * @param page The Playwright page.
 * @param password The master password (defaults to the dev password).
 * @return Resolves once the vault is unlocked and off the lock screen.
 */
export async function unlockVault(page: Page, password: string = DEV_MASTER_PASSWORD): Promise<void> {
	await gotoLockSettled(page)
	await page.locator('.lock-screen input[type="password"]').first().fill(password, { force: true })
	// Give the v-model a tick so the Unlock button enables.
	await page.waitForTimeout(300)
	// Native click — the themed NcButton swallows Playwright's synthetic click.
	await page.evaluate(() => {
		const btns = Array.from(document.querySelectorAll('.lock-screen button')) as HTMLButtonElement[]
		const unlock = btns.find((b) => /Unlock/i.test(b.textContent || ''))
		if (unlock) {
			unlock.click()
		}
	})
	// Wait for the unlock + router push to settle off the lock screen.
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 20_000 })
}

/**
 * Open the vault list WITHIN the unlocked SPA by clicking the "Vault" nav link
 * (a vue-router-link). A full `page.goto` reload would wipe the in-memory
 * CryptoKey and bounce to the lock screen; a router-link click navigates the
 * SPA in place and keeps the session unlocked. The click is dispatched natively
 * because the themed nav entry can swallow Playwright's synthetic click.
 *
 * @param page The Playwright page (must already be unlocked).
 */
export async function openVault(page: Page): Promise<void> {
	await page.evaluate(() => {
		const a = Array.from(document.querySelectorAll('a')).find(
			(x) => /\/apps\/doriath\/secrets$/.test(x.getAttribute('href') || ''),
		)
		if (a) {
			(a as HTMLElement).click()
		}
	})
	await expect(page.locator('.secret-list-view')).toBeVisible({ timeout: 20_000 })
}

/**
 * The Nextcloud request token, read from the document. Required for
 * state-changing API calls made from within page.evaluate.
 */
export const READ_REQUEST_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`
