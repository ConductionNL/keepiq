/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keepiq runs without any other app (standalone-app-shell, ADR-006).
 *
 * ONE SPEC, TWO INSTANCE SHAPES. Which half runs depends on what the instance
 * has enabled, read from `OC.appswebroots` (one key per app enabled for the
 * user, the same source the shell gates on):
 *
 * - without OpenRegister: the shell renders, Flows is hidden, a Flows deep
 *   link shows the missing-dependency screen, the admin section works, and no
 *   request reaches `/apps/openregister/`;
 * - with OpenRegister: Flows is offered and renders.
 *
 * The other half is skipped with a reason, so a run on either instance shape
 * is green for what that shape can prove. CI runs the standalone half on its
 * no-OpenRegister leg (tasks.md 5.1); the dev instance proves the other half.
 *
 * THE VAULT LOCK. Routed pages sit behind the master password, so they are
 * reached with `unlockVault()` and an in-place router push.
 *
 * @e2e openspec/specs/app-shell/spec.md#keepiq-is-usable-on-an-instance-without-openregister-or-integriq
 * @e2e openspec/specs/app-shell/spec.md#disabling-openregister-changes-nothing-in-keepiq
 * @e2e openspec/specs/app-shell/spec.md#the-admin-section-renders-without-openregister
 * @e2e openspec/specs/app-shell/spec.md#the-version-card-is-up-to-date-after-install
 * @e2e openspec/specs/app-shell/spec.md#flows-are-hidden-without-openregister
 * @e2e openspec/specs/app-shell/spec.md#a-deep-link-to-flows-without-openregister-explains-the-missing-app
 * @e2e openspec/specs/app-shell/spec.md#flows-work-when-openregister-is-present
 * @e2e openspec/specs/app-shell/spec.md#the-ai-companion-is-absent-without-hermiq
 * @e2e openspec/specs/app-shell/spec.md#integrations-are-hidden-without-integriq
 * @e2e openspec/specs/app-shell/spec.md#dashboard-wasm-csp-survives-the-change
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	gotoVaultRoute,
	openVault,
	unlockVault,
} from './_workflow-helpers.ts'

/**
 * The apps enabled for the logged-in user.
 *
 * @param page A page on any Nextcloud URL.
 * @return The app ids in `OC.appswebroots`.
 */
async function enabledApps(page: Page): Promise<string[]> {
	return page.evaluate(() => {
		const w = window as unknown as {
			OC?: { appswebroots?: Record<string, string> }
		}
		return Object.keys(w.OC?.appswebroots ?? {})
	})
}

/**
 * Record every request whose URL reaches one of the given apps.
 *
 * @param page The page to watch.
 * @param apps App ids, e.g. `openregister`.
 * @return The live list of matching URLs.
 */
function watchForeignRequests(page: Page, apps: string[]): string[] {
	const seen: string[] = []
	page.on('request', (request) => {
		const url = request.url()
		if (apps.some((app) => url.includes(`/apps/${app}/`))) {
			seen.push(url)
		}
	})
	return seen
}

/** The Flows navigation entry, in Keepiq's left rail. */
function flowsEntry(page: Page) {
	return page.locator('.app-navigation').getByText('Flows', { exact: true })
}

test.describe('Keepiq without OpenRegister', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`)
		test.skip(
			(await enabledApps(page)).includes('openregister'),
			'OpenRegister is enabled on this instance; this half needs an instance without it',
		)
	})

	test('the shell renders and the vault works, with no foreign request', async ({
		page,
	}) => {
		const foreign = watchForeignRequests(page, ['openregister'])

		await unlockVault(page)
		await openVault(page)

		await expect(page.getByText(/OpenRegister (is )?missing/i)).toHaveCount(0)
		expect(foreign, 'requests to OpenRegister').toEqual([])
	})

	test('Flows is hidden, and a deep link explains the missing app', async ({
		page,
	}) => {
		await unlockVault(page)

		await expect(flowsEntry(page)).toHaveCount(0)

		await gotoVaultRoute(page, 'flows')
		await expect(page.getByText(/OpenRegister/).first()).toBeVisible({
			timeout: 20_000,
		})
		// The rest of the shell stays usable.
		await openVault(page)
	})

	test('the admin section and its up-to-date version card render', async ({
		page,
	}) => {
		await page.goto('/index.php/settings/admin/keepiq')

		await expect(
			page.locator('#keepiq-admin-settings, .cn-admin-settings-shell').first(),
		).toBeVisible({ timeout: 20_000 })
		await expect(page.getByRole('button', { name: /Re-import/i })).toHaveCount(0)
		await expect(page.getByText(/up to date/i).first()).toBeVisible()
	})
})

test.describe('Keepiq with OpenRegister', () => {
	test.beforeEach(async ({ page }) => {
		await page.goto(`${APP_BASE}/`)
		test.skip(
			(await enabledApps(page)).includes('openregister') === false,
			'OpenRegister is not enabled on this instance; this half needs it',
		)
	})

	test('Flows is offered and renders', async ({ page }) => {
		await unlockVault(page)

		await expect(flowsEntry(page)).toHaveCount(1)
		await gotoVaultRoute(page, 'flows')
		await expect(
			page.getByText(/OpenRegister is not installed|missing/i),
		).toHaveCount(0)
	})
})

test.describe('Keepiq without Hermiq or integriq', () => {
	test('no AI companion and no Hermiq request without Hermiq', async ({
		page,
	}) => {
		const hermiq = watchForeignRequests(page, ['hermiq'])
		await page.goto(`${APP_BASE}/`)
		test.skip(
			(await enabledApps(page)).includes('hermiq'),
			'Hermiq is enabled on this instance',
		)

		await unlockVault(page)

		await expect(page.locator('.cn-ai-companion')).toHaveCount(0)
		expect(hermiq, 'requests to Hermiq').toEqual([])
	})

	test('no Integrations entry without integriq', async ({ page }) => {
		await page.goto(`${APP_BASE}/`)
		test.skip(
			(await enabledApps(page)).includes('integriq'),
			'integriq is enabled on this instance',
		)

		await unlockVault(page)

		await expect(
			page
				.locator('.app-navigation')
				.getByText('Integrations', { exact: true }),
		).toHaveCount(0)
	})
})

test('the app page still sends the WASM CSP opt-in', async ({ page }) => {
	const response = await page.goto(`${APP_BASE}/`)

	expect(response?.headers()['content-security-policy'] ?? '').toContain(
		'wasm-unsafe-eval',
	)
})
