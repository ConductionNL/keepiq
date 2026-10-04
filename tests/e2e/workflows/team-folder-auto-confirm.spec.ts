/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow: a new team folder member gets access without the owner
 * (admin-auto-confirm-members 3.4).
 *
 * Three people, three browser contexts:
 *   - the OWNER (admin, the seeded vault) shares a folder holding one secret
 *     with a `write` member and with a group, then leaves;
 *   - a NEWCOMER joins that group afterwards, so their copy is missing;
 *   - the WRITE MEMBER signs in and unlocks. Their browser's background
 *     confirmation (`teamFolder.autoConfirm()`, started after unlock) encrypts
 *     the secret for the newcomer from the member's own copy.
 * The newcomer then unlocks and decrypts the secret. The owner does nothing
 * after the newcomer joins.
 *
 * Both non-admin vaults are created in the browser, so their envelopes are the
 * JS format. The fixture users, the group and the switch are seeded through
 * Nextcloud's and Keepiq's own APIs and put back afterwards.
 *
 * @e2e openspec/specs/team-folder-auto-confirm/spec.md#new-member-gets-access-without-the-owner
 * @e2e openspec/specs/team-folder-auto-confirm/spec.md#write-member-sees-a-waiting-colleague
 */
import type { APIRequestContext, Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BASE_URL } from '../base-url.ts'
import { APP_BASE, unlockVault } from './_workflow-helpers.ts'

const ADMIN_USER = process.env.ADMIN_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || 'admin'
const RUN = Date.now().toString(36)
const GROUP = `kq-e2e-team-${RUN}`
const WRITER = `kq-e2e-writer-${RUN}`
const NEWCOMER = `kq-e2e-newcomer-${RUN}`
const NC_PASS = 'Kq-e2e-member-2026-pass'
const MASTER = 'Kq-e2e-master-2026-correct-horse'
const VALUE = `auto-confirm-value-${RUN}`
const API = `${APP_BASE}/api/v1`

const ADMIN_HEADERS = {
	Authorization:
		'Basic ' + Buffer.from(`${ADMIN_USER}:${ADMIN_PASS}`).toString('base64'),
	'OCS-APIRequest': 'true',
	Accept: 'application/json',
}

/**
 * Create a Nextcloud user (optionally in a group) through the provisioning API.
 *
 * @param request An API context.
 * @param user The user id.
 */
async function createUser(request: APIRequestContext, user: string): Promise<void> {
	const res = await request.post(
		`${BASE_URL}/ocs/v2.php/cloud/users?format=json`,
		{
			headers: ADMIN_HEADERS,
			form: { userid: user, password: NC_PASS },
		},
	)
	expect(res.status(), `create ${user}`).toBe(200)
}

/**
 * Turn the automatic confirmation switch on or off as the instance administrator.
 *
 * @param request An API context.
 * @param on The wanted value.
 */
async function setAutoConfirm(
	request: APIRequestContext,
	on: boolean,
): Promise<void> {
	const res = await request.put(
		`${BASE_URL}/index.php/apps/keepiq/api/settings/admin/policies`,
		{
			headers: { ...ADMIN_HEADERS, 'Content-Type': 'application/json' },
			data: { team_folder_auto_confirm: on },
		},
	)
	expect(res.status(), 'the Policies area stores the switch').toBe(200)
}

/**
 * A fresh, signed-in context for one fixture user.
 *
 * @param browser The browser.
 * @param user The user id.
 * @return The page.
 */
async function signedIn(browser: Browser, user: string): Promise<Page> {
	const context = await browser.newContext({
		storageState: { cookies: [], origins: [] },
	})
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill(user)
	await page.locator('input[name="password"]').fill(NC_PASS)
	await page.locator('form[name="login"] button[type="submit"]').click()
	await expect(page).not.toHaveURL(/\/login(\?|$|\/)/, { timeout: 40_000 })
	return page
}

/**
 * Create this user's vault in the browser (setup mode has two password fields).
 *
 * @param page A signed-in page whose user owns no suite yet.
 */
async function setUpVault(page: Page): Promise<void> {
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
}

/**
 * Call one of the page's Keepiq APIs with the session and its CSRF token.
 *
 * @param page A signed-in page.
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
 * Run an action of one of the app's Pinia stores inside the unlocked SPA.
 *
 * The stores carry the session CryptoKey, so decrypting and encrypting go
 * through exactly the code a user's click would run.
 *
 * @param page An unlocked page.
 * @param store The store id.
 * @param action The action name.
 * @param args The arguments.
 * @return The action's result.
 */
async function storeAction(
	page: Page,
	store: string,
	action: string,
	...args: unknown[]
): Promise<any> {
	return page.evaluate(
		async ({ store, action, args }) => {
			const host = document.querySelector('#keepiq-app') as any
			const pinia = host?.__vue_app__?.config?.globalProperties?.$pinia
			const s = pinia?._s?.get(store)
			if (!s) {
				throw new Error(`store ${store} is not active`)
			}
			return JSON.parse(JSON.stringify(await s[action](...args)))
		},
		{ store, action, args },
	)
}

/**
 * Create a secret in a folder, encrypted in the browser to the owner's suite.
 *
 * @param page The owner's unlocked page.
 * @param folderId The folder.
 * @return The secret id.
 */
async function createFolderSecret(page: Page, folderId: string): Promise<string> {
	const created = await storeAction(page, 'secret', 'createSecret', {
		name: `Auto confirm ${RUN}`,
		key: VALUE,
		folderId,
	})
	expect(created?.id, 'the owner created the folder secret').toBeTruthy()
	return created.id
}

test.describe('Workflow: automatic confirmation of new team folder members', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	test.afterAll(async ({ playwright }) => {
		const request = await playwright.request.newContext()
		await setAutoConfirm(request, false)
		for (const user of [WRITER, NEWCOMER]) {
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

	test('a write member unlocks and the newcomer reads the folder secret', async ({
		browser,
		request,
	}) => {
		test.setTimeout(300_000)

		// --- Fixture: switch on, a group, two users with browser-made vaults ---
		await setAutoConfirm(request, true)
		const group = await request.post(
			`${BASE_URL}/ocs/v2.php/cloud/groups?format=json`,
			{
				headers: ADMIN_HEADERS,
				form: { groupid: GROUP },
			},
		)
		expect(group.status(), 'create the member group').toBe(200)
		await createUser(request, WRITER)
		await createUser(request, NEWCOMER)

		const writer = await signedIn(browser, WRITER)
		await setUpVault(writer)
		const newcomer = await signedIn(browser, NEWCOMER)
		await setUpVault(newcomer)

		// --- The owner shares a folder with the writer and the group, then leaves ---
		const owner = await browser.newContext({
			storageState: { cookies: [], origins: [] },
		})
		const ownerPage = await owner.newPage()
		await ownerPage.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
		await ownerPage.locator('input[name="user"]').fill(ADMIN_USER)
		await ownerPage.locator('input[name="password"]').fill(ADMIN_PASS)
		await ownerPage.locator('form[name="login"] button[type="submit"]').click()
		await expect(ownerPage).not.toHaveURL(/\/login(\?|$|\/)/, {
			timeout: 40_000,
		})
		await unlockVault(ownerPage)

		const folder = await call(ownerPage, 'POST', '/folders', {
			name: `Team ${RUN}`,
		})
		expect(folder.status, 'the owner creates a folder').toBeLessThan(300)
		const secretId = await createFolderSecret(ownerPage, folder.data.id)

		const team = await call(ownerPage, 'POST', '/team-folders', {
			folderId: folder.data.id,
		})
		expect(team.status, 'the folder becomes a team folder').toBeLessThan(300)
		const teamId = team.data.id

		const writerRow = await call(
			ownerPage,
			'POST',
			`/team-folders/${teamId}/members`,
			{
				memberType: 'user',
				memberId: WRITER,
			},
		)
		expect(writerRow.status, 'the writer is added').toBeLessThan(300)
		const grade = await call(
			ownerPage,
			'PATCH',
			`/team-folders/${teamId}/members/${writerRow.data.member.id}`,
			{
				grade: 'write',
			},
		)
		expect(
			grade.status,
			`the writer gets the write grade: ${JSON.stringify(grade.data)}`,
		).toBe(200)
		const groupRow = await call(
			ownerPage,
			'POST',
			`/team-folders/${teamId}/members`,
			{
				memberType: 'group',
				memberId: GROUP,
			},
		)
		expect(groupRow.status, 'the group is added').toBeLessThan(300)

		// The owner's own fan-out gives the writer a copy (one click in the dialog).
		const fanOut = await storeAction(
			ownerPage,
			'teamFolder',
			'runFanOut',
			teamId,
		)
		expect(fanOut.created, 'the owner fans out to the writer').toBe(1)
		await owner.close()

		// --- The newcomer joins the group: their copy is now missing ---
		const join = await request.post(
			`${BASE_URL}/ocs/v2.php/cloud/users/${NEWCOMER}/groups?format=json`,
			{ headers: ADMIN_HEADERS, form: { groupid: GROUP } },
		)
		expect(join.status(), 'the newcomer joins the group').toBe(200)

		const before = await request.get(
			`${BASE_URL}${API}/team-folders/${teamId}/reconcile`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		const waiting = ((await before.json()).missing ?? []).map(
			(m: { userId: string }) => m.userId,
		)
		expect(waiting, 'the newcomer waits for a copy').toContain(NEWCOMER)

		// The writer is offered the waiting colleague ...
		await writer.goto(`${APP_BASE}/lock`, { waitUntil: 'domcontentloaded' })
		const pending = await call(
			writer,
			'GET',
			'/team-folders/pending-confirmations',
		)
		expect(pending.data.enabled, 'the switch is on').toBe(true)
		const offered = (pending.data.folders ?? []).flatMap(
			(f: { missing?: Array<{ userId: string }> }) =>
				(f.missing ?? []).map((m) => m.userId),
		)
		expect(offered, 'the writer may confirm the newcomer').toContain(NEWCOMER)

		// ... and unlocking is all it takes: the background run posts the copy.
		const confirmed = writer.waitForResponse(
			(r) =>
				r.url().includes(`/team-folders/${teamId}/shares`)
				&& r.request().method() === 'POST',
			{ timeout: 60_000 },
		)
		await unlockVault(writer, MASTER)
		const posted = await confirmed
		expect(
			posted.status(),
			'the server accepts the writer as confirmer',
		).toBeLessThan(300)
		expect((await posted.json()).created, 'one copy for the newcomer').toBe(1)

		const after = await request.get(
			`${BASE_URL}${API}/team-folders/${teamId}/reconcile`,
			{
				headers: ADMIN_HEADERS,
			},
		)
		expect(
			((await after.json()).missing ?? []).length,
			'nobody waits any more',
		).toBe(0)

		// --- The newcomer opens the folder secret ---
		await unlockVault(newcomer, MASTER)
		const mine = await call(newcomer, 'GET', '/secrets?limit=200')
		const copy = (mine.data.items ?? []).find(
			(s: { sourceSecretId?: string; name?: string }) =>
				s.sourceSecretId === secretId || s.name === `Auto confirm ${RUN}`,
		)
		expect(copy, 'the newcomer holds a copy of the folder secret').toBeTruthy()
		const plain = await storeAction(newcomer, 'secret', 'fetchSecret', copy.id)
		expect(plain.key, 'the newcomer reads the value').toBe(VALUE)
	})
})
