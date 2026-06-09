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

test.describe('App navigation — manifest menu', () => {
	test('left navigation renders the manifest menu entries', async ({ page }) => {
		const errors = collectDoriathErrors(page)
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })

		const nav = appNav(page)
		await expect(nav).toBeVisible({ timeout: 15_000 })

		// The Dashboard entry points at the doriath app root (not /apps/dashboard).
		await expect(nav.locator('a[href="/apps/doriath/"]').first()).toBeVisible()
		// Footer entries: Documentation + Lock vault (-> doriath lock route).
		await expect(nav.getByText(/Documentation/i).first()).toBeVisible()
		await expect(nav.locator('a[href="/apps/doriath/lock"]').first()).toBeVisible()

		assertNoDoriathErrors(errors)
	})

	test('clicking the Lock vault nav entry keeps the locked user on the lock gate', async ({ page }) => {
		await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })

		// "Lock vault" is a doriath-owned route (/apps/doriath/lock) — clicking it
		// is a safe in-app navigation that stays on the lock gate.
		const lockEntry = appNav(page).locator('a[href="/apps/doriath/lock"]').first()
		await expect(lockEntry).toBeVisible()
		await lockEntry.click()

		await expect(lockHeading(page)).toBeVisible({ timeout: 15_000 })
		await expect(page).toHaveURL(/\/apps\/doriath\/lock/)
	})
})
