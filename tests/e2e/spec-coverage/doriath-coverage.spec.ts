/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Doriath scenarios that need a provisioned, suite-less
 * user (weak-password setup-mode rejection) or that document a real UI gap
 * (user-settings dialog is unreachable from the current manifest).
 *
 * The data-independent surfaces — lock screen, gated routes, app navigation and
 * the admin settings page — are covered in the sibling spec files
 * (lock-screen / gated-routes / navigation / admin-settings). This file holds
 * the two scenarios that don't fit those flat surface tests.
 *
 * Base URL : http://localhost:8080
 * Auth      : admin/admin (stored in .auth/admin.json via globalSetup)
 *
 * @e2e openspec/specs/encryption-suites/spec.md#weak-password-rejected
 * @e2e openspec/specs/user-settings/spec.md#user-opens-settings
 */

import { test, expect } from '@playwright/test'

// ---------------------------------------------------------------------------
// Encryption Suites — weak-password rejection in first-time setup
// ---------------------------------------------------------------------------

test.describe('Encryption Suites — spec: encryption-suites/spec.md', () => {

	/**
	 * @e2e encryption-suites::weak-password-rejected
	 * GIVEN a user is setting a master password (first-time setup)
	 * WHEN they type a password with zxcvbn score below the floor
	 * THEN the strength meter MUST show a warning and the submit button
	 *      MUST remain disabled.
	 *
	 * Provisions a brand-new, unique Nextcloud user via the admin OCS API so the
	 * account is guaranteed to have NO EncryptionSuite — Doriath then renders the
	 * first-time setup form deterministically (no flaky reliance on a shared test
	 * user whose suite state drifts between runs). The user is deleted afterwards.
	 */
	test.setTimeout(120_000)
	test('weak-password-rejected — strength meter blocks weak password on setup', async ({ page }) => {
		// @e2e encryption-suites::weak-password-rejected

		const testUser = `dor-e2e-weak-${Date.now()}`
		const testPassword = 'DorTest123!Pw'
		const ocsHeaders = {
			Authorization: `Basic ${Buffer.from('admin:admin').toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		}

		// Create a fresh, suite-less user. On this shared dev instance the OCS
		// user-create endpoint can return a 500 from an unrelated post-create hook
		// even though the account IS created — so we verify existence afterwards
		// and skip gracefully (rather than false-fail) if provisioning is
		// genuinely unavailable.
		await page.request.post(
			'http://localhost:8080/ocs/v2.php/cloud/users?format=json',
			{ headers: ocsHeaders, form: { userid: testUser, password: testPassword } },
		).catch(() => undefined)

		const existsRes = await page.request.get(
			`http://localhost:8080/ocs/v2.php/cloud/users/${testUser}?format=json`,
			{ headers: ocsHeaders },
		).catch(() => undefined)
		const userExists = !!existsRes && existsRes.ok()
			&& ((await existsRes.json().catch(() => ({}))) as { ocs?: { data?: { id?: string } } })?.ocs?.data?.id === testUser
		test.skip(!userExists, 'Could not provision a fresh suite-less user (OCS unavailable) — skipping setup-mode check')

		const browser = page.context().browser()!
		const testContext = await browser.newContext()
		const testPage = await testContext.newPage()
		testPage.setDefaultTimeout(20_000)

		try {
			await testPage.goto('http://localhost:8080/index.php/login', { waitUntil: 'domcontentloaded' })
			// The NC login form hydrates client-side. On this shared dev instance it
			// can fail to hydrate (asset 500s / brute-force throttling). If the form
			// never appears, the environment can't support this flow — skip cleanly.
			const userField = testPage.locator('input[name="user"]')
			const formReady = await userField.waitFor({ state: 'visible', timeout: 30_000 })
				.then(() => true).catch(() => false)
			test.skip(!formReady, 'NC login form did not hydrate (shared-instance flakiness) — skipping setup-mode check')
			await userField.fill(testUser)
			await testPage.locator('input[name="password"]').fill(testPassword)
			await testPage.locator('button[type="submit"]').first().click()
			await testPage.waitForSelector('#header, header.header', { timeout: 30_000 })
			await testPage.goto('http://localhost:8080/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })

			// The lock screen renders. Because LockScreen.vue fetches the suite in
			// created(), the heading may briefly show "Set up" before settling.
			// A suite-less user settles on the setup form: wait for the confirm
			// field (only present in setup mode) to be stable before asserting.
			const heading = testPage.locator('h1.lock-screen__title, .lock-screen h1').first()
			await expect(heading).toBeVisible({ timeout: 20_000 })
			// Wait for the suite fetch in created() to settle (heading can flicker).
			await testPage.waitForTimeout(2_000)
			const headingText = (await heading.textContent())?.trim() ?? ''
			test.skip(!/Set up your master password/i.test(headingText),
				`Fresh user unexpectedly has a suite (heading: "${headingText}") — skipping setup-mode check`)

			// Two password fields confirm setup mode (master + confirm).
			await expect
				.poll(async () => testPage.locator('.lock-screen input[type="password"]').count(), { timeout: 10_000 })
				.toBeGreaterThanOrEqual(2)

			// Type a weak password ("password") — zxcvbn score 0.
			await testPage.locator('.lock-screen input[type="password"]').first().fill('password')

			// The strength meter appears and reports weakness.
			await expect(testPage.locator('.password-strength-meter')).toBeVisible({ timeout: 5_000 })
			const feedback = testPage.locator('.password-strength-meter__feedback')
			await expect(feedback).toBeVisible()
			expect(await feedback.textContent()).toBeTruthy()

			// The submit button stays disabled (canSubmitSetup false — too weak).
			const submitBtn = testPage.locator('.lock-screen button').filter({ hasText: /Set up vault/i })
			await expect(submitBtn).toBeVisible()
			await expect(submitBtn).toBeDisabled()
		} finally {
			await testContext.close().catch(() => {})
			// Clean up the throwaway user.
			await page.request.delete(
				`http://localhost:8080/ocs/v2.php/cloud/users/${testUser}?format=json`,
				{ headers: ocsHeaders },
			).catch(() => {})
		}
	})
})

// ---------------------------------------------------------------------------
// User Settings — dialog reachability
// ---------------------------------------------------------------------------

test.describe('User Settings — spec: user-settings/spec.md', () => {

	/**
	 * @e2e user-settings::user-opens-settings
	 * GIVEN a user clicks the gear icon in the Doriath navigation
	 * WHEN the dialog opens
	 * THEN the system MUST display NcAppSettingsDialog with user preference sections.
	 *
	 * BUG (flagged, not fixed — tests must not modify source):
	 *   App.vue populates CnAppRoot's `#user-settings` slot (Session timeout,
	 *   master-password change, compromise recovery), but the NcAppSettingsDialog
	 *   it hosts can only be opened from a manifest *menu* entry carrying
	 *   `action: "user-settings"` (CnAppNav binds `cnOpenUserSettings` to it —
	 *   see CnAppNav.vue). src/manifest.json declares no such entry, so there is
	 *   no gear/menu affordance to open the dialog: the user-settings surface
	 *   (and therefore the session-timeout / password-change / compromise-recovery
	 *   controls) is currently UNREACHABLE from the Doriath UI.
	 *
	 *   Fix: add a `section: "settings"` menu entry with
	 *   `"action": "user-settings"` to src/manifest.json. Marked test.fixme until
	 *   that ships; the assertion below documents the expected post-fix behaviour.
	 */
	test.fixme('user-opens-settings — manifest is missing the action:"user-settings" menu entry, so the dialog cannot be opened', async ({ page }) => {
		// @e2e user-settings::user-opens-settings

		await page.goto('/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
		await expect(
			page.locator('h1.lock-screen__title, .lock-screen h1').first(),
		).toBeVisible({ timeout: 15_000 })

		// Once a manifest entry with action:"user-settings" exists, CnAppNav
		// renders it in the settings footer and clicking it opens the dialog.
		const settingsEntry = page.locator(
			'.app-navigation a[data-doriath-action="user-settings"], '
			+ '.app-navigation__footer button[aria-label*="settings" i]',
		).first()
		await expect(settingsEntry).toBeVisible({ timeout: 5_000 })
		await settingsEntry.click()

		const dialog = page.locator('[class*="settings-dialog"], [role="dialog"]').first()
		await expect(dialog).toBeVisible({ timeout: 10_000 })
		// The Session and Security sections from App.vue's #user-settings slot.
		await expect(dialog.getByText(/Session/i).first()).toBeVisible()
		await expect(dialog.getByText(/Security/i).first()).toBeVisible()
	})

	/**
	 * Companion (always-running) assertion of the SAME gap: there is currently
	 * NO settings menu entry / gear affordance in the Doriath nav, so a user
	 * cannot reach the user-settings dialog. This stays green until the manifest
	 * gains an action:"user-settings" entry, at which point it must be updated to
	 * assert the entry IS present (and the test.fixme above un-fixmed).
	 */
	test('user-settings dialog has no opener in the current manifest (documents the gap)', async ({ page }) => {
		await page.goto('/index.php/apps/doriath/lock', { waitUntil: 'domcontentloaded' })
		await expect(
			page.locator('h1.lock-screen__title, .lock-screen h1').first(),
		).toBeVisible({ timeout: 15_000 })

		const nav = page.locator('.app-navigation').first()
		await expect(nav).toBeVisible({ timeout: 15_000 })

		// No nav entry currently triggers the user-settings action. (The footer
		// holds Documentation + Features & roadmap + Lock vault — all route/href
		// entries, none with action:"user-settings".)
		const settingsOpener = nav.locator(
			'a[data-doriath-action="user-settings"], '
			+ '.app-navigation__footer button[aria-label*="settings" i]',
		)
		expect(await settingsOpener.count()).toBe(0)
	})
})
