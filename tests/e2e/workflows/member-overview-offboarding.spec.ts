/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow: offboard a leaver from the member overview
 * (admin-member-overview-and-offboarding 3.4).
 *
 * A leaver who never set up a vault is a direct member of a team folder. The
 * administrator filters the Members list on "Not set up", picks "Offboard" on
 * the leaver's row (which fills the leaving user of Team offboarding), picks a
 * successor, confirms, and reads the removed membership count in the summary.
 * The team folder then has no user row for the leaver.
 *
 * Runs as the stored admin session. The leaver and the team folder are seeded
 * through Nextcloud's and Keepiq's own APIs and removed afterwards.
 *
 * @e2e openspec/specs/admin-member-overview/spec.md#administrator-sees-who-has-not-set-up-a-vault
 * @e2e openspec/specs/admin-member-overview/spec.md#start-offboarding-from-the-list
 * @e2e openspec/specs/team-folder-sharing/spec.md#direct-membership-rows-are-removed
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import { APP_BASE } from './_workflow-helpers.ts'

const ADMIN_USER = process.env.ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || 'admin'
const RUN = Date.now().toString(36)
const LEAVER = `kq-e2e-leaver-${RUN}`
const API = `${APP_BASE}/api/v1`

const ADMIN_HEADERS = {
	Authorization:
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
}

/**
 * Call a Keepiq API from inside the admin's page, with its CSRF token.
 *
 * @param page The admin page.
 * @param method The HTTP method.
 * @param path The path below /apps/keepiq/api/v1.
 * @param body An optional JSON body.
 * @return Status and parsed body.
 */
async function call(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number; data: any }> {
	return page.evaluate(
		async ({ url, method, body }) => {
			const token =
				(window as unknown as { OC?: { requestToken?: string } }).OC
					?.requestToken || ''
			const res = await fetch(url, {
				method,
				credentials: 'include',
				headers: { 'Content-Type': 'application/json', requesttoken: token },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let data: unknown = text
			try {
				data = JSON.parse(text)
			} catch {
				// not JSON
			}
			return { status: res.status, data }
		},
		{ url: `${API}${path}`, method, body },
	)
}

/**
 * Pick an option in an NcSelect found by its input label.
 *
 * @param page The page.
 * @param label The select's input label.
 * @param query What to type into the select.
 * @param option The option text to choose.
 */
async function pick(
	page: Page,
	label: string,
	query: string,
	option: string | RegExp,
): Promise<void> {
	const box = page.getByRole('combobox', { name: label })
	await box.click()
	if (query !== '') {
		await box.fill(query)
	}
	// NcSelect splits a long label over two spans for its ellipsis, so the
	// option's accessible name reads "Not s et up"; match on its text instead.
	await page.getByRole('option').filter({ hasText: option }).first().click()
}

test.describe('Workflow: offboard a leaver from the member overview', () => {
	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext({
			storageState: { cookies: [], origins: [] },
		})
		await request.delete(
			`${BASE_URL}/ocs/v2.php/cloud/users/${LEAVER}?format=json`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		await request.dispose()
	})

	test('filter on "Not set up", offboard from the row, read the removed memberships', async ({
		page,
		playwright,
	}) => {
		test.setTimeout(180_000)

		// --- Fixture: a leaver without a vault, a direct member of a team folder ---
		// A cookie-less context. Inside a test, a new context inherits the
		// config's admin storageState, and with that session cookie attached
		// Nextcloud asks for a fresh password confirmation once the session is
		// older than 30 minutes, so the provisioning call answers 403.
		const request = await playwright.request.newContext({
			storageState: { cookies: [], origins: [] },
		})
		const created = await request.post(
			`${BASE_URL}/ocs/v2.php/cloud/users?format=json`,
			{
				headers: ADMIN_HEADERS,
				form: {
					userid: LEAVER,
					password: 'Kq-e2e-leaver-2026-pass',
					displayName: `Leaver ${RUN}`,
				},
			},
		)
		expect(created.status(), `create the leaver: ${await created.text()}`).toBe(
			200,
		)
		await request.dispose()

		await page.goto('/index.php/settings/admin/keepiq', {
			waitUntil: 'domcontentloaded',
		})
		const folder = await call(page, 'POST', '/folders', {
			name: `Offboarding ${RUN}`,
		})
		expect(folder.status, 'create a folder').toBeLessThan(300)
		const team = await call(page, 'POST', '/team-folders', {
			folderId: folder.data.id,
		})
		expect(team.status, 'share it as a team folder').toBeLessThan(300)
		const member = await call(
			page,
			'POST',
			`/team-folders/${team.data.id}/members`,
			{
				memberType: 'user',
				memberId: LEAVER,
			},
		)
		expect(member.status, 'the leaver is a direct member').toBeLessThan(300)

		// --- Members: filter on "Not set up" and find the leaver ---
		await page.reload({ waitUntil: 'domcontentloaded' })
		const members = page.getByTestId('member-overview-section')
		await expect(members).toBeVisible({ timeout: 30_000 })

		const filtered = page.waitForResponse(
			(r) =>
				r.url().includes('/api/v1/admin/members')
				&& r.url().includes('status=none'),
		)
		await pick(page, 'Vault status', '', 'Not set up')
		const listed = await filtered
		expect(listed.status()).toBe(200)

		await members.getByLabel('Search users').fill(LEAVER)
		await expect(page.getByTestId(`member-status-${LEAVER}`)).toHaveText(
			'Not set up',
			{
				timeout: 15_000,
			},
		)
		// The filter holds: every row on the page reads "Not set up".
		const statuses = await page
			.locator('[data-testid^="member-status-"]')
			.allTextContents()
		expect(statuses.length).toBeGreaterThan(0)
		expect(new Set(statuses.map((s) => s.trim()))).toEqual(
			new Set(['Not set up']),
		)

		// --- Offboard from the row ---
		await page
			.getByTestId(`member-offboard-${LEAVER}`)
			.evaluate((el: HTMLElement) => el.click())
		const offboarding = page.getByTestId('offboarding-section')
		await expect(offboarding.getByTestId('offboarding-leaving')).toContainText(
			`Leaver ${RUN}`,
		)
		await pick(page, 'Successor', ADMIN_USER, new RegExp(`^${ADMIN_USER}`, 'i'))

		await offboarding
			.getByTestId('offboarding-run')
			.evaluate((el: HTMLElement) => el.click())
		const dialog = page.getByTestId('offboarding-confirm-dialog')
		await expect(dialog).toBeVisible()
		const done = page.waitForResponse(
			(r) =>
				r.url().includes('/api/v1/team-folders/offboard')
				&& r.request().method() === 'POST',
		)
		await page
			.getByTestId('offboarding-confirm')
			.evaluate((el: HTMLElement) => el.click())
		const response = await done
		expect(response.status(), 'offboarding succeeds').toBe(200)
		expect(
			(await response.json()).membershipsRemoved,
			'one direct membership removed',
		).toBe(1)

		await expect(offboarding.getByTestId('offboarding-summary')).toContainText(
			'Removed the user from 1 team folders.',
		)

		// --- The leaver has no user row in the team folder any more ---
		const after = await call(
			page,
			'GET',
			`/team-folders/${team.data.id}/members`,
		)
		const rows = Array.isArray(after.data)
			? after.data
			: (after.data.members ?? after.data.items ?? [])
		expect(
			rows.filter(
				(r: { memberType: string; memberId: string }) =>
					r.memberType === 'user' && r.memberId === LEAVER,
			),
			'no direct row for the leaver',
		).toHaveLength(0)
	})
})
