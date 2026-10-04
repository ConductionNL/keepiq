/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow: vault policies scoped to a group (admin-vault-policies 4.5).
 *
 * Two members of a fresh group, each with a vault made in the browser:
 *   - the administrator switches on "Block personal vault export" for the group
 *     in the Vault policies section; the member then finds the export modes
 *     gone and the server refuses an export report;
 *   - with "Keep work logins in team folders" on for the group, the member is
 *     refused a login in a personal folder and saves it into their own team
 *     folder, where it decrypts.
 * Policies, users and the group are put back afterwards, so the rest of the
 * suite (which runs as admin, outside the group) is unaffected.
 *
 * @e2e openspec/changes/admin-vault-policies/specs/vault-policies/spec.md#administrator-scopes-the-export-ban-to-a-group
 * @e2e openspec/changes/admin-vault-policies/specs/vault-policies/spec.md#blocked-user-gets-no-backup-file
 * @e2e openspec/changes/admin-vault-policies/specs/vault-policies/spec.md#personal-login-refused
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import { APP_BASE, gotoVaultRoute, openActionsMenu } from './_workflow-helpers.ts'

const ADMIN_USER = process.env.ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || 'admin'
const RUN = Date.now().toString(36)
const GROUP = `kq-e2e-staff-${RUN}`
const ERIN = `kq-e2e-erin-${RUN}`
const GINA = `kq-e2e-gina-${RUN}`
const NC_PASS = 'Kq-e2e-member-2026-pass'
const MASTER = 'Kq-e2e-master-2026-correct-horse'
const API = `${APP_BASE}/api/v1`
const POLICIES = `${BASE_URL}/index.php/apps/keepiq/api/settings/admin/policies`

const ADMIN_HEADERS = {
	Authorization:
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
}

const POLICY_OFF = {
	vault_export_disabled: false,
	vault_export_disabled_groups: [],
	vault_org_ownership: false,
	vault_org_ownership_groups: [],
}

/**
 * Write vault policy keys as the instance administrator.
 *
 * @param request An API context.
 * @param data The keys to write.
 */
async function putPolicies(
	request: APIRequestContext,
	data: Record<string, unknown>,
): Promise<void> {
	const res = await request.put(POLICIES, {
		headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
		data,
	})
	expect(res.status(), `policies stored: ${await res.text()}`).toBe(200)
}

/**
 * A fresh signed-in context for one fixture user, with a vault set up in the browser.
 *
 * @param browser The browser.
 * @param user The user id.
 * @return The page, unlocked.
 */
async function memberWithVault(browser: Browser, user: string): Promise<Page> {
	const context = await browser.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(NC_PASS)
	await page.locator('form[name="login"] button[type="submit"]').click()
	await expect(page).not.toHaveURL(/\/login(\?|$|\/)/, { timeout: 40_000 })

	await page.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
	const fields = page.locator('.lock-screen input[type="password"]')
	await expect(fields).toHaveCount(2, { timeout: 30_000 })
	await fields.nth(0).fill(MASTER, { force: true })
	await fields.nth(1).fill(MASTER, { force: true })
	await page.waitForTimeout(400)
	await page.evaluate(() => {
		const btn = Array.from(
			document.querySelectorAll('.lock-screen button'),
		).find((b) => /Set up vault/i.test(b.textContent || ''))
		;(btn as HTMLButtonElement | undefined)?.click()
	})
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 30_000 })
	return page
}

/**
 * Call a Keepiq API from inside a signed-in page, with its CSRF token.
 *
 * @param page The page.
 * @param method The HTTP method.
 * @param path The path below /apps/keepiq/api/v1, or a full /index.php path.
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
		{
			url: path.startsWith('/index.php') ? path : `${API}${path}`,
			method,
			body,
		},
	)
}

/**
 * Run a Pinia store action in the unlocked SPA, reporting an HTTP refusal
 * instead of throwing, so the caller can assert on it.
 *
 * @param page An unlocked page.
 * @param store The store id.
 * @param action The action.
 * @param args Its arguments.
 * @return The result, or the refusal's status and body.
 */
async function storeAction(
	page: Page,
	store: string,
	action: string,
	...args: unknown[]
): Promise<{ ok: boolean; result?: any; status?: number; data?: any }> {
	return page.evaluate(
		async ({ store, action, args }) => {
			const host = document.querySelector('#keepiq-app') as any
			const s =
				host?.__vue_app__?.config?.globalProperties?.$pinia?._s?.get(store)
			if (!s) {
				throw new Error(`store ${store} is not active`)
			}
			try {
				return {
					ok: true,
					result: JSON.parse(
						JSON.stringify((await s[action](...args)) ?? null),
					),
				}
			} catch (e: any) {
				return {
					ok: false,
					status: e?.response?.status,
					data: e?.response?.data,
				}
			}
		},
		{ store, action, args },
	)
}

test.describe.serial('Workflow: vault policies for one group', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.beforeAll(async ({ playwright }) => {
		const request = await playwright.request.newContext()
		const group = await request.post(
			`${BASE_URL}/ocs/v2.php/cloud/groups?format=json`,
			{
				headers: ADMIN_HEADERS,
				form: { groupid: GROUP },
			},
		)
		expect(group.status(), 'create the group').toBe(200)
		for (const user of [ERIN, GINA]) {
			const res = await request.post(
				`${BASE_URL}/ocs/v2.php/cloud/users?format=json`,
				{
					headers: ADMIN_HEADERS,
					form: { userid: user, password: NC_PASS, 'groups[]': GROUP },
				},
			)
			expect(res.status(), `create ${user}`).toBe(200)
		}
		await request.dispose()
	})

	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext()
		await putPolicies(request, POLICY_OFF)
		for (const user of [ERIN, GINA]) {
			await request.delete(
				`${BASE_URL}/ocs/v2.php/cloud/users/${user}?format=json`,
				{
					headers: ADMIN_HEADERS,
				},
			)
		}
		await request.delete(
			`${BASE_URL}/ocs/v2.php/cloud/groups/${GROUP}?format=json`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		await request.dispose()
	})

	test('the administrator bans export for the group and the member finds no export file', async ({
		browser,
		request,
	}) => {
		test.setTimeout(240_000)
		const erin = await memberWithVault(browser, ERIN)

		// --- The administrator scopes the ban to the group in the admin settings ---
		const admin = await browser.newContext({
			storageState: { cookies: [], origins: [] },
		})
		const adminPage = await admin.newPage()
		await adminPage.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await adminPage.locator('input[name="user"]').fill(ADMIN_USER)
		await adminPage.locator('input[name="password"]').fill(ADMIN_PASS)
		await adminPage.locator('form[name="login"] button[type="submit"]').click()
		await expect(adminPage).not.toHaveURL(/\/login(\?|$|\/)/, {
			timeout: 40_000,
		})
		await adminPage.goto('/index.php/settings/admin/keepiq', {
			waitUntil: 'domcontentloaded',
		})
		const section = adminPage.getByTestId('vault-policy-section')
		await expect(section).toBeVisible({ timeout: 30_000 })

		const saved = () =>
			adminPage.waitForResponse(
				(r) =>
					r.url().includes('/api/settings/admin/policies')
					&& r.request().method() === 'PUT',
			)
		let put = saved()
		const groups = section.getByTestId(
			'vault-policy-vault_export_disabled-groups',
		)
		await groups.getByRole('combobox').click()
		await groups.getByRole('combobox').fill(GROUP)
		await adminPage
			.getByRole('option')
			.filter({ hasText: GROUP })
			.first()
			.click()
		expect((await put).status(), 'the group scope is saved').toBe(200)

		put = saved()
		await section.getByTestId('vault-policy-vault_export_disabled').check()
		expect((await put).status(), 'the ban is saved').toBe(200)
		await admin.close()

		const stored = await (
			await request.get(POLICIES, { headers: ADMIN_HEADERS })
		).json()
		expect(stored.vault_export_disabled).toBe(true)
		expect(stored.vault_export_disabled_groups).toEqual([GROUP])

		// The ban applies to the member and not to the administrator outside the group.
		expect(
			(await call(erin, 'GET', `${APP_BASE}/api/settings/policy`)).data
				.vault_export_disabled,
		).toBe(true)
		const adminPolicy = await request.get(
			`${BASE_URL}/index.php/apps/keepiq/api/settings/policy`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		expect(
			(await adminPolicy.json()).vault_export_disabled,
			'outside the group',
		).toBe(false)

		// --- The member opens the export dialog: no file modes, only the notice ---
		await gotoVaultRoute(erin, 'secrets')
		await expect(erin.locator('.secret-list-view')).toBeVisible({
			timeout: 20_000,
		})
		await openActionsMenu(erin)
		await erin
			.getByRole('menuitem', { name: /Export data/i })
			.evaluate((el: HTMLElement) => el.click())
		await expect(erin.getByTestId('export-blocked-by-policy')).toBeVisible({
			timeout: 20_000,
		})
		await expect(erin.getByText(/Encrypted backup \(recommended\)/)).toHaveCount(
			0,
		)
		await expect(erin.getByText(/Plaintext CSV/)).toHaveCount(0)
		await expect(erin.getByTestId('export-mode-cxf')).toHaveCount(0)

		// ... and the server refuses the report a download would make.
		const refused = await call(erin, 'POST', '/export/events', {
			mode: 'encrypted-backup',
		})
		expect(refused.status).toBe(403)
		expect(refused.data.code).toBe('export_disabled_by_policy')
	})

	test('a member in scope is refused a personal login and saves it into a team folder', async ({
		browser,
		request,
	}) => {
		test.setTimeout(240_000)
		await putPolicies(request, {
			vault_org_ownership: true,
			vault_org_ownership_groups: [GROUP],
		})
		const gina = await memberWithVault(browser, GINA)
		await gotoVaultRoute(gina, 'secrets')
		await expect(gina.locator('.secret-list-view')).toBeVisible({
			timeout: 20_000,
		})

		const types = await call(gina, 'GET', '/secret-types')
		const list = Array.isArray(types.data)
			? types.data
			: (types.data.items ?? types.data.types ?? [])
		const login = list.find((t: { name: string }) => t.name === 'login')
		expect(login, 'the login type exists').toBeTruthy()

		const personal = await call(gina, 'POST', '/folders', {
			name: `Private ${RUN}`,
		})
		expect(personal.status).toBeLessThan(300)
		const refused = await storeAction(gina, 'secret', 'createSecret', {
			name: `Work login ${RUN}`,
			typeId: login.id,
			folderId: personal.data.id,
			key: 'work-login-value',
		})
		expect(refused.ok, 'a personal login is refused').toBe(false)
		expect(refused.status).toBe(403)
		expect(refused.data?.code).toBe('org_ownership_required')

		const ops = await call(gina, 'POST', '/folders', { name: `Ops ${RUN}` })
		const team = await call(gina, 'POST', '/team-folders', {
			folderId: ops.data.id,
		})
		expect(team.status, 'Ops becomes her team folder').toBeLessThan(300)
		const saved = await storeAction(gina, 'secret', 'createSecret', {
			name: `Work login ${RUN}`,
			typeId: login.id,
			folderId: ops.data.id,
			key: 'work-login-value',
		})
		expect(
			saved.ok,
			`the login is saved in the team folder: ${JSON.stringify(saved.data)}`,
		).toBe(true)

		const read = await storeAction(
			gina,
			'secret',
			'fetchSecret',
			saved.result.id,
		)
		expect(read.result.key, 'and it decrypts').toBe('work-login-value')
	})
})
