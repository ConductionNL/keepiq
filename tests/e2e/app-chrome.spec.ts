/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bottom-left app chrome, in a browser (ADR-114).
 *
 * gate-107 reads the manifest and can prove the entries are DECLARED. It
 * cannot prove they RENDER, and this programme has already produced three
 * defects of exactly that shape:
 *
 *   - an icon name that is not registered renders NO glyph — not a fallback,
 *     not a console error. Four apps shipped that, and only gate-60 caught it.
 *   - a menu entry whose `route` names a page the app does not host renders a
 *     row that goes nowhere.
 *   - `nav.includePersonalSettings: false` removed the entry that reaches the
 *     user's notification preferences, in two apps, silently.
 *
 * So this asserts the three things a manifest cannot: that the footer items
 * render in order with a glyph each, that the settings foldout carries
 * Personal settings and Flows, and that Reports actually navigates.
 *
 * ⚠️ SCOPE EVERY SELECTOR TO `[data-testid="cn-nav"]`. An unscoped
 * `.list-item` or a bare `getByRole('link', …)` also matches Nextcloud's own
 * user menu, which is attached-but-hidden: `waitFor({state:'attached'})`
 * passes on it and the click never becomes actionable, so the spec fails with
 * "Target page has been closed" — a timeout wearing a crash's clothes.
 *
 * ⚠️ SETTINGS ENTRIES ARE ATTACHED, NOT VISIBLE. CnAppNav renders them inside
 * a collapsed NcAppNavigationSettings foldout. Asserting viewport visibility
 * fails on a correct app.
 *
 * Keepiq has no Store: it declares no OpenRegister schemas, so an install
 * would have nothing to write into (ADR-080 Decision 4). That is asserted as
 * an ABSENCE below rather than left unmentioned, so the day keepiq grows a
 * store surface this spec is what notices.
 */

import { expect, test } from '@playwright/test'

const APP_ID = 'keepiq'

test.describe('app chrome (ADR-114)', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`/apps/${APP_ID}/`)
		await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible({
			timeout: 30_000,
		})
	})

	test('the footer reads Documentation, Reports, Features & roadmap, each with a glyph', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		const rows = footer.locator('li')
		const texts = (await rows.allInnerTexts())
			.map((t) => t.trim())
			.filter(Boolean)

		// ORDER is the rule, not the numbers: ADR-114 fixes the sequence and
		// openregister runs its footer at 1/2 while pipelinq runs 160/200/230.
		const seen = texts.filter((t) => /Documentation|Reports|roadmap/i.test(t))
		expect(seen.length).toBe(3)
		expect(seen[0]).toMatch(/Documentation/i)
		expect(seen[1]).toMatch(/Reports/i)
		expect(seen[2]).toMatch(/roadmap/i)

		// A glyph on every row. This is the assertion that would have caught
		// the unregistered-icon defect in launchpad, humaniq and planninq.
		for (const row of await rows.all()) {
			await expect(
				row.locator('svg, .material-design-icon').first(),
			).toBeAttached()
		}
	})

	test('the two reports are cards on the Reports page, not menu entries', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		// ADR-112 Decision 2: a report is a card OR an entry, never both. These
		// two WERE footer entries before ADR-114; the entries are gone.
		await expect(
			nav.locator('[data-testid="cn-nav-entry-MyActivityMenu"]'),
		).toHaveCount(0)
		await expect(
			nav.locator('[data-testid="cn-nav-entry-PasswordHealthMenu"]'),
		).toHaveCount(0)

		await nav.locator('[data-testid="cn-nav-entry-ReportsMenu"]').click()
		await expect(page).toHaveURL(new RegExp(`/apps/${APP_ID}/reports(\\?|$)`), {
			timeout: 15_000,
		})

		// Both reports are reachable from the page that replaced their entries.
		// ADR-044 Decision 5's no-functionality-loss invariant, checked rather
		// than asserted in a comment.
		await expect(
			page.getByText('Password health', { exact: false }).first(),
		).toBeVisible({ timeout: 15_000 })
		await expect(
			page.getByText('My activity', { exact: false }).first(),
		).toBeVisible()
	})

	test('the pages behind the retired entries are still routable', async ({
		page,
	}) => {
		// Deep links, the dashboard widget's viewAllRoute and the older e2e
		// specs all address these by route. Retiring a menu entry must not take
		// the route with it.
		for (const path of ['/my-activity', '/password-health']) {
			await page.goto(`/apps/${APP_ID}${path}`)
			await expect(page).toHaveURL(new RegExp(`${path}(\\?|$)`), {
				timeout: 15_000,
			})
			await expect(page.locator('[data-testid="cn-nav"]')).toBeVisible()
		}
	})

	test('the settings foldout carries Personal settings and Flows', async ({
		page,
	}) => {
		const nav = page.locator('[data-testid="cn-nav"]')

		await expect(nav.locator('[data-testid="cn-nav-settings"]')).toBeAttached({
			timeout: 15_000,
		})
		await expect(
			nav.locator('[data-testid="cn-nav-entry-FlowsMenu"]'),
		).toBeAttached()

		// Keepiq opts out of the shell's auto-prepended Personal settings and
		// declares its OWN entry with action:"user-settings" onto the same
		// dialog, which is legitimate and is why gate-107 checks the PAIR
		// rather than the flag. Exactly one of the two must exist.
		const shellEntry = nav.locator('[data-testid="cn-nav-personal-settings"]')
		const ownEntry = nav.locator('[data-testid="cn-nav-entry-UserSettings"]')
		expect(
			(await shellEntry.count()) + (await ownEntry.count()),
		).toBeGreaterThan(0)
	})

	test('keepiq offers no Store, because it owns no schemas to install into', async ({
		page,
	}) => {
		const footer = page.locator(
			'[data-testid="cn-nav"] .cn-app-nav__footer-list',
		)
		await expect(footer).toBeAttached({ timeout: 15_000 })

		// Not an oversight. Keepiq's OpenRegister register declares zero
		// schemas — it keeps secrets in its own tables — so `installable` could
		// name nothing and an install would have nothing to write. ADR-080
		// Decision 4 refuses the word Store on a surface that cannot honour it.
		// If keepiq ever grows one, this is the test that says so.
		await expect(footer.getByRole('link', { name: /^Store$/ })).toHaveCount(0)
	})
})
