/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 spec-coverage — Doriath app navigation (manifest-driven CnAppRoot
 * shell).
 *
 * Asserts the left-hand app navigation renders the manifest menu entries and
 * that clicking a gated nav entry routes through the lock guard. Nav clicks are
 * scoped to the app navigation (never the global NC header) per the nav trap.
 *
 * @e2e openspec/specs/dashboard/spec.md#app-navigation-renders
 */
import { test, expect } from '@playwright/test'
import {
	APP_BASE,
	lockHeading,
	collectDoriathErrors,
	assertNoDoriathErrors,
} from './_helpers'

// The app's left navigation is the `.app-navigation` container. We scope ALL
// nav queries to it (never the global NC header / apps menu) and additionally
// match doriath-owned hrefs, so we can never click a global app link.
function appNav(page: import('@playwright/test').Page) {
	return page.locator('.app-navigation').first()
}

// Settings-section menu entries (Lock vault, Settings) render inside the
// NcAppNavigationSettings foldout, which starts collapsed. Expand it so those
// entries become visible. The toggle is clicked via a real DOM `.click()` so
// the full-page lock-screen layout cannot swallow the synthetic pointer event.
async function expandSettingsFoldout(page: import('@playwright/test').Page) {
	const toggle = page.locator(
		'#app-settings button.settings-button, [data-testid="cn-nav-settings"] button',
	).first()
	await expect(toggle).toBeVisible({ timeout: 5_000 })
	await toggle.evaluate((el: HTMLElement) => el.click())
}

test.describe('App navigation — manifest menu', () => {
	test('left navigation renders the manifest menu entries', async ({ page }) => {
		const errors = collectDoriathErrors(page)
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })

		const nav = appNav(page)
		await expect(nav).toBeVisible({ timeout: 15_000 })

		// The Dashboard entry points at the doriath app root (not /apps/dashboard).
		// Matched on the hash SUFFIX, not the whole href: under vue-router 4 the
		// hash-history links render relative (`#/`) rather than carrying the
		// absolute app base (`/apps/doriath/#/`). Both resolve to the same route
		// from any doriath page; asserting the literal absolute form would pin a
		// router implementation detail rather than the requirement.
		await expect(nav.locator('a[href$="#/"]').first()).toBeVisible()
		// Footer entry: Documentation (pinned, always visible).
		await expect(nav.getByText(/Documentation/i).first()).toBeVisible()
		// Lock vault lives in the settings foldout (section: "settings"); expand
		// it so the doriath-owned lock route becomes visible.
		await expandSettingsFoldout(page)
		await expect(nav.locator('a[href$="#/lock"]').first()).toBeVisible()

		assertNoDoriathErrors(errors)
	})

	test('clicking the Lock vault nav entry keeps the locked user on the lock gate', async ({ page }) => {
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })

		// "Lock vault" is a doriath-owned route (/apps/doriath/lock) in the
		// settings foldout — expand it, then clicking the entry is a safe in-app
		// navigation that stays on the lock gate.
		await expandSettingsFoldout(page)
		const lockEntry = appNav(page).locator('a[href$="#/lock"]').first()
		await expect(lockEntry).toBeVisible()
		await lockEntry.evaluate((el: HTMLElement) => el.click())

		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		// Hash-mode router: the lock route is `#/lock` (the path may retain the
		// `/lock` prefix from the initial load, so assert the hash, not the path).
		await expect(page).toHaveURL(/#\/lock$/)
	})
})
