/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for Doriath spec-coverage Playwright tests.
 *
 * Doriath is a zero-knowledge encrypted vault: every in-app view (Dashboard,
 * Vault, Features & roadmap, Secret detail) sits behind a master-password lock
 * screen and a router guard. The admin account already owns an EncryptionSuite,
 * but its master password is — by design — not recoverable (never stored server
 * side). So the lock screen and the router-guard redirect ARE the canonical,
 * data-independent UI surfaces for the gated routes, and the admin settings page
 * (a Nextcloud settings section, ungated) is fully reachable.
 *
 * These helpers therefore assert real DOM behaviour without ever needing to
 * unlock the vault.
 */
import { type Page, expect } from '@playwright/test'

export const APP_BASE = '/index.php/apps/doriath'

/**
 * Attach a doriath-origin error/5xx collector to a page. Returns the array that
 * accumulates offending messages. We deliberately ignore errors from other apps
 * (photos, core user-status, etc.) which are noisy on this shared dev instance.
 */
export function collectDoriathErrors(page: Page): string[] {
	const errors: string[] = []
	page.on('console', (msg) => {
		if (msg.type() !== 'error') return
		const text = msg.text()
		// Only flag errors that name doriath or come from a doriath bundle.
		const loc = msg.location()?.url ?? ''
		if (/doriath/i.test(text) || /doriath/i.test(loc)) {
			errors.push(`console.error: ${text}`)
		}
	})
	page.on('response', (res) => {
		if (res.status() >= 500 && /\/doriath\//.test(res.url())) {
			errors.push(`HTTP ${res.status()}: ${res.url()}`)
		}
	})
	return errors
}

/** Open the Doriath lock screen directly. */
export async function gotoLock(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
}

/**
 * Open a hash-routed Doriath view (e.g. '#/secrets'). Because the vault is
 * locked, the router guard redirects to the lock screen — which is exactly the
 * behaviour the per-route specs assert.
 */
export async function gotoRoute(page: Page, hash: string): Promise<void> {
	await page.goto(`${APP_BASE}/${hash}`, { waitUntil: 'domcontentloaded' })
}

/** The lock-screen heading locator (covers both setup + unlock copy). */
export function lockHeading(page: Page) {
	return page.locator('h1.lock-screen__title, .lock-screen h1').first()
}

/** Wait for the lock screen to be mounted and visible. */
export async function expectLockScreen(page: Page): Promise<void> {
	await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
	await expect(lockHeading(page)).toHaveText(/Unlock Doriath|Set up your master password/i)
}

/** Assert no doriath-origin console errors / 5xx were collected. */
export function assertNoDoriathErrors(errors: string[]): void {
	expect(errors, `doriath-origin errors:\n${errors.join('\n')}`).toEqual([])
}
