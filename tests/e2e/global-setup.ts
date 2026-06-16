/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Playwright globalSetup — logs into Nextcloud once and persists the
 * resulting cookie jar to `tests/e2e/.auth/admin.json`.
 *
 * Every spec reuses the storage state via playwright.config.ts
 * `use.storageState`, so specs start from an already-authenticated
 * session without repeating the login flow.
 */

import { chromium, request, type FullConfig } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'

const AUTH_DIR = path.resolve(__dirname, '.auth')
const STORAGE_STATE = path.join(AUTH_DIR, 'admin.json')

async function ensureNextcloudReachable(baseURL: string): Promise<void> {
	const ctx = await request.newContext()
	try {
		const res = await ctx.get(`${baseURL}/status.php`, { failOnStatusCode: false })
		if (!res.ok()) {
			throw new Error(
				`Nextcloud status.php returned ${res.status()} at ${baseURL}. `
				+ 'Make sure the docker container is running.',
			)
		}
		const body = await res.json().catch(() => ({}))
		if (!body || body.installed !== true) {
			throw new Error(
				`Nextcloud at ${baseURL} is not installed (status.php = ${JSON.stringify(body)}).`,
			)
		}
	} finally {
		await ctx.dispose()
	}
}

/**
 * Returns true when the cached storageState still authenticates against NC,
 * letting us skip a fresh (throttle-prone) login.
 */
async function cachedSessionValid(baseURL: string): Promise<boolean> {
	if (!fs.existsSync(STORAGE_STATE)) {
		return false
	}
	try {
		const state = JSON.parse(fs.readFileSync(STORAGE_STATE, 'utf8'))
		const ctx = await request.newContext({ baseURL, storageState: state })
		try {
			const res = await ctx.get('/ocs/v2.php/cloud/user?format=json', {
				headers: { 'OCS-APIRequest': 'true' },
				failOnStatusCode: false,
			})
			return res.status() === 200
		} finally {
			await ctx.dispose()
		}
	} catch {
		return false
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = (config.projects[0]?.use?.baseURL as string | undefined)
		?? process.env.NEXTCLOUD_URL
		?? 'http://localhost:8080'
	const username = process.env.NC_ADMIN_USER ?? 'admin'
	const password = process.env.NC_ADMIN_PASS ?? 'admin'

	await ensureNextcloudReachable(baseURL)
	fs.mkdirSync(AUTH_DIR, { recursive: true })

	// Reuse a still-valid cached session when possible. NC brute-force protection
	// throttles repeated logins on a busy shared instance, so avoid re-logging in
	// on every run: if the stored cookies still authenticate, skip the login flow.
	if (await cachedSessionValid(baseURL)) {
		return
	}

	const browser = await chromium.launch()
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()

	// The NC login form hydrates client-side and this shared dev instance can be
	// sluggish (asset loads, brute-force throttling). Retry the whole login a few
	// times with generous waits so a single slow hydrate doesn't fail every spec.
	const MAX_ATTEMPTS = 4
	let loggedIn = false
	let lastErr: unknown
	for (let attempt = 1; attempt <= MAX_ATTEMPTS && !loggedIn; attempt++) {
		try {
			await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
			const userField = page.locator('input[name="user"]')
			await userField.waitFor({ state: 'visible', timeout: 40_000 })
			await userField.fill(username)
			await page.locator('input[name="password"]').fill(password)
			await page.locator('button[type="submit"]').first().click()
			await page.waitForSelector('#header, header.header', { state: 'attached', timeout: 40_000 })
			// Give the redirect a moment to settle, then confirm we left /login.
			await page.waitForLoadState('domcontentloaded').catch(() => {})
			if (!/\/login(\?|$|\/)/.test(page.url())) {
				loggedIn = true
			} else {
				lastErr = new Error(`still on ${page.url()} after submit`)
			}
		} catch (e) {
			lastErr = e
		}
		if (!loggedIn && attempt < MAX_ATTEMPTS) {
			await page.waitForTimeout(5_000 * attempt)
		}
	}

	if (!loggedIn) {
		throw new Error(
			`Login failed after ${MAX_ATTEMPTS} attempts (last: ${String(lastErr)}). `
			+ 'Check NC_ADMIN_USER / NC_ADMIN_PASS (defaults admin/admin) and that the '
			+ 'instance is reachable / not brute-force throttled.',
		)
	}

	await context.storageState({ path: STORAGE_STATE })
	await browser.close()
}
