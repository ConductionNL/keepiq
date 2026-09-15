/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Integrations page over integriq's connection registry
 * (adopt-connection-registry, hydra connection-registry D8 and D9).
 *
 * WHERE THE ROWS COME FROM. The rows are integriq's `app_connection` objects,
 * synced from Keepiq's `lib/Settings/connections.json`, with `app` equal to
 * `keepiq`. Keepiq writes no row: an admin save asks integriq to resolve
 * again, a range lookup or a SIEM drain reports what it met, and integriq
 * decides the status. So this spec needs integriq installed and synced, and
 * reads the rows from
 * `/apps/openregister/api/objects/integriq/app_connection?app=keepiq`.
 *
 * `app` is a BARE filter key. The objects endpoint reads `filter[app]` as a
 * filter on nothing and answers the empty set without an error.
 *
 * THE VAULT LOCK. Every routed Keepiq page sits behind the master password, so
 * the page is reached with `unlockVault()` and an in-place router push. A
 * `page.goto` to the route would drop the in-memory key and land on the lock
 * screen, which reads like a broken page.
 *
 * WHAT A RED HERE USUALLY MEANS. An empty list in the first test means
 * integriq has not synced the declaration, or refused it whole. A Breach check
 * row stuck on Configured after the switch goes off means the refresh did not
 * reach integriq, so an older observation still counts.
 *
 * Locale: nothing forces the E2E language, so statuses are read from the API
 * and rows are found by their declared titles, which are not translated.
 *
 * It needs an instance with both apps (tasks.md 5.1). First run in CI on
 * 2026-09-15 (keepiq run 34955589876), where the row lookup below was fixed.
 *
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#the-page-lists-only-the-rows-of-keepiq
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#add-integration-goes-to-integriq
 * @e2e openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#a-switched-off-breach-check-reads-not-configured
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	APP_BASE,
	gotoVaultRoute,
	READ_REQUEST_TOKEN,
	unlockVault,
} from './_workflow-helpers.ts'

/** Integriq's objects endpoint for Keepiq's connection rows. */
const CONNECTIONS_API =
	'/index.php/apps/openregister/api/objects/integriq/app_connection?app=keepiq&_limit=50'

/** Keepiq's admin settings endpoint. */
const ADMIN_SETTINGS_API = `${APP_BASE}/api/settings/admin`

/** The declared keys and titles, in declared order. */
const DECLARED = [
	{ key: 'hibp', title: 'Breach check' },
	{ key: 'siem', title: 'SIEM audit export' },
]

/** Headers for the JSON API reads. */
const JSON_HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

/**
 * Keepiq's connection rows, keyed by connection key.
 *
 * @param request An admin request context.
 * @return The rows by key.
 */
async function rowsByKey(
	request: APIRequestContext,
): Promise<Record<string, Record<string, unknown>>> {
	const res = await request.get(CONNECTIONS_API, { headers: JSON_HEADERS })
	expect(res.ok(), `list integriq/app_connection -> ${res.status()}`).toBeTruthy()
	const body = await res.json()
	const byKey: Record<string, Record<string, unknown>> = {}
	for (const row of (body.results ?? []) as Record<string, unknown>[]) {
		// A row from another app here means the bare filter was dropped.
		expect(String(row.app), 'a connection row from another app').toBe('keepiq')
		byKey[String(row.key)] = row
	}
	return byKey
}

/**
 * Open the Integrations page the way its menu entry does, with the preset.
 *
 * @param page The Playwright page.
 */
async function openIntegrations(page: Page): Promise<void> {
	await page.goto(`${APP_BASE}/`, { timeout: 60_000 })
	await unlockVault(page)
	await gotoVaultRoute(page, 'settings/integrations?app=keepiq')
	await expect(page.locator('.lock-screen')).toHaveCount(0)
	await expect(page.locator('.cn-index-page')).toBeVisible({ timeout: 30_000 })
}

/**
 * Save the breach check switch through the admin settings API.
 *
 * @param page    The Playwright page, on a Keepiq document.
 * @param enabled The value to save.
 */
async function saveBreachCheck(page: Page, enabled: boolean): Promise<void> {
	const token = (await page.evaluate(READ_REQUEST_TOKEN)) as string
	const res = await page.request.put(ADMIN_SETTINGS_API, {
		headers: { ...JSON_HEADERS, requesttoken: token },
		data: { breach_check_enabled: enabled },
	})
	expect(res.ok(), `admin settings save -> ${res.status()}`).toBeTruthy()
}

test.describe('Integrations over the connection registry', () => {
	test("lists the two declared connections, both of them Keepiq's", async ({
		page,
	}) => {
		const byKey = await rowsByKey(page.request)
		expect(Object.keys(byKey).sort()).toEqual(DECLARED.map((d) => d.key).sort())

		// Every row links to a section of Keepiq's own admin page.
		for (const { key } of DECLARED) {
			expect(String(byKey[key]?.settingsUrl ?? ''), key).toMatch(
				/^\/settings\/admin\/keepiq#section-/,
			)
		}

		await openIntegrations(page)
		// Match the row by its Connection cell, not by the row's accessible name:
		// that name starts with the "Select row" checkbox, so a `^title` pattern
		// on it never matches.
		for (const { title } of DECLARED) {
			await expect(
				page.getByRole('row').filter({
					has: page.getByRole('cell', { name: title, exact: true }),
				}),
			).toHaveCount(1)
		}
	})

	test('reads Not configured while the breach check is off, and Configured once it is on', async ({
		page,
	}) => {
		await page.goto(`${APP_BASE}/`, { timeout: 60_000 })

		const before = await page.request.get(ADMIN_SETTINGS_API, {
			headers: JSON_HEADERS,
		})
		expect(before.ok(), `admin settings read -> ${before.status()}`).toBeTruthy()
		const previous = Boolean((await before.json())?.breach_check_enabled)

		/**
		 * The Breach check row's status and message, read without asserting: a
		 * throw inside `expect.poll` ends the poll instead of retrying it.
		 *
		 * @return The status and message, or empty strings when the row is missing.
		 */
		const hibpRow = async (): Promise<string> => {
			const list = await page.request.get(CONNECTIONS_API, {
				headers: JSON_HEADERS,
			})
			const rows = list.ok() ? ((await list.json()).results ?? []) : []
			const row = rows.find(
				(r: Record<string, unknown>) =>
					r.key === 'hibp' && r.app === 'keepiq',
			)
			return `${String(row?.status ?? '')}|${String(row?.statusMessage ?? '')}`
		}

		try {
			// The save sends ConnectionRefreshRequestedEvent, which retires any
			// older lookup report, and integriq's rule 6 reads `false` as empty.
			await saveBreachCheck(page, false)
			await expect
				.poll(hibpRow, { timeout: 15_000 })
				.toBe(
					'unconfigured|Breach checking is switched off. Switch it on under Breach checking in the Keepiq admin settings.',
				)

			// Rule 5: the required switch is filled.
			await saveBreachCheck(page, true)
			await expect
				.poll(hibpRow, { timeout: 15_000 })
				.toBe('configured|Required settings are filled.')
		} finally {
			// Put the VALUE back. The restore is a save too, so it refreshes the row again.
			await saveBreachCheck(page, previous)
		}
	})

	test('sends Add integration to integriq instead of offering a form', async ({
		page,
	}) => {
		await openIntegrations(page)

		// No generic Add button: a row nothing declared has nothing to check.
		await expect(page.locator('[data-testid="cn-cta-primary"]')).toHaveCount(0)

		// The action lives in the overflow menu. English and Dutch are the two
		// catalogues this change ships, and nothing forces the E2E locale. The
		// themed trigger swallows a synthetic click, so it is clicked natively.
		await page
			.getByTestId('cn-actions')
			.locator('button')
			.first()
			.evaluate((el: HTMLElement) => el.click())
		await Promise.all([
			page.waitForURL(/\/apps\/integriq\/connections\?app=keepiq&link=1$/, {
				timeout: 30_000,
			}),
			page
				.getByRole('menuitem', {
					name: /Add integration|Integratie toevoegen/i,
				})
				.click(),
		])
	})
})
