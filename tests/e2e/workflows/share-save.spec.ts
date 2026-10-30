/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The atomic share-save (keepiq#1254, fixing keepiq#1253), end to end: every
 * value change of a shared secret is saved through the edit dialog, the
 * version history or the offline queue, with the browser's own encryption,
 * and each holder checks the result in their own unlocked browser.
 *
 * Shares and team-folder grades are set up through the stores the sharing
 * dialogs call, as the other multi-user specs do. Partner (federated) shares
 * need a second instance and are not covered here.
 *
 * A group share goes through the sidebar's group-share form instead.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-shared-secret-and-every-copy-save-in-one-transaction
 * @spec openspec/specs/sharing-group/spec.md#requirement-a-group-member-saves-for-everyone-it-is-shared-with
 */
import type { APIRequestContext, BrowserContext, Page } from '@playwright/test'
import type { VaultUser } from './_vault-users.ts'

import { expect, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { BASE_URL } from '../base-url.ts'
import { withStore } from './_vault-actions.ts'
import {
	adminApi,
	api,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
} from './_vault-users.ts'
import { gotoVaultRoute } from './_workflow-helpers.ts'

interface Session {
	user: VaultUser
	context: BrowserContext
	page: Page
}

interface ApiCall {
	method: string
	path: string
	status: number
	error?: string
}

interface Value {
	key: string
	login: string
	additionalFields: Record<string, string>
}

const STALE_TEXT =
	'This secret was changed elsewhere after you opened it. Nothing was saved; open it again and redo your change.'

const BASE_HOST = new URL(BASE_URL).host

/** Requests to any host but the instance, aborted; must stay empty. */
const external: string[] = []

/**
 * Record every Keepiq API response of the page, with the error code of a
 * share-save answer.
 *
 * @param page The page.
 * @return The calls so far and a reset.
 */
function watchApi(page: Page): {
	calls: () => Promise<ApiCall[]>
	reset: () => void
} {
	let pending: Array<Promise<ApiCall>> = []
	page.on('response', (response) => {
		const url = new URL(response.url())
		const at = url.pathname.indexOf('/apps/keepiq/api/v1/')
		if (at === -1) {
			return
		}
		const call: ApiCall = {
			method: response.request().method(),
			path: url.pathname.slice(at + '/apps/keepiq/api/v1'.length),
			status: response.status(),
		}
		pending.push(
			call.path.endsWith('/share-save') && call.status !== 200
				? response
						.json()
						.then((body) => ({ ...call, error: body?.error }))
						.catch(() => call)
				: Promise.resolve(call),
		)
	})
	return {
		calls: () => Promise.all(pending),
		reset: () => {
			pending = []
		},
	}
}

/**
 * Assert the save went out as share-save requests only, with these answers,
 * and through none of the old fan-out routes.
 *
 * @param calls The recorded calls.
 * @param id The row the save was for.
 * @param expected Status and error code per share-save request, in order.
 */
function expectShareSaveOnly(
	calls: ApiCall[],
	id: string,
	expected: Array<[number, string?]>,
): void {
	const saves = calls.filter((c) => c.path.endsWith('/share-save'))
	expect(
		saves.map((c) => [c.method, c.path, c.status, c.error]),
		JSON.stringify(calls),
	).toEqual(
		expected.map(([status, error]) => [
			'PUT',
			`/secrets/${id}/share-save`,
			status,
			error,
		]),
	)
	const legacy = calls.filter(
		(c) =>
			c.path.endsWith('/sync')
			|| (c.method === 'PUT' && c.path.startsWith('/federated-shares/'))
			|| (c.method === 'PUT' && c.path === `/secrets/${id}`),
	)
	expect(legacy, 'no legacy fan-out or plain update').toEqual([])
}

/**
 * Sign the account in, give it a vault and keep requests on the instance.
 *
 * @param browser The browser.
 * @param user The account.
 * @return The session, unlocked on the vault list.
 */
async function startSession(
	browser: Parameters<typeof signedInContext>[0],
	user: VaultUser,
): Promise<Session> {
	const { context, page } = await signedInContext(browser, user)
	await context.route(
		(url) => url.host !== BASE_HOST,
		(route) => {
			external.push(route.request().url())
			return route.abort()
		},
	)
	// The first-open support dialog and the walkthrough would cover the page; see global-setup.ts.
	await context.addInitScript(() => {
		window.localStorage.setItem('cn-support-dialog-shown:keepiq', '1')
		window.localStorage.setItem('cn-walkthrough-seen:keepiq', '999.0.0')
	})
	await setUpVault(page, user)
	await openList(page)
	return { user, context, page }
}

/**
 * Show the vault list, which puts every store the helpers use in use.
 *
 * @param page An unlocked page.
 */
async function openList(page: Page): Promise<void> {
	await gotoVaultRoute(page, 'secrets')
	await expect(page.locator('.secret-list-view')).toBeVisible({ timeout: 30_000 })
}

/**
 * Unlock through the lock screen without reloading, so it works offline.
 *
 * @param session The session.
 */
async function lockAndUnlock(session: Session): Promise<void> {
	const { page, user } = session
	await gotoVaultRoute(page, 'lock')
	const field = page.locator('.lock-screen input[type="password"]').first()
	await field.waitFor({ state: 'visible', timeout: 30_000 })
	await field.fill(user.masterPassword, { force: true })
	await expect(page.getByTestId('unlock-with-password')).toBeEnabled()
	await page
		.getByTestId('unlock-with-password')
		.evaluate((el: HTMLElement) => el.click())
	await expect(page.locator('.lock-screen')).toHaveCount(0, { timeout: 60_000 })
}

/**
 * Unlock again when the inactivity timeout locked the vault meanwhile.
 *
 * @param session The session.
 */
async function ensureUnlocked(session: Session): Promise<void> {
	if (
		await withStore<boolean>(session.page, 'session', 'async (s) => s.isLocked')
	) {
		await lockAndUnlock(session)
	}
}

/**
 * The id of the secret type with this name.
 *
 * @param page An unlocked page.
 * @param name The type name, such as `login`.
 * @return The id.
 */
async function typeId(page: Page, name: string): Promise<string> {
	const types = await api(page, 'GET', '/secret-types')
	const found = (types.body as Array<{ id: string; name: string }>).find(
		(t) => t.name === name,
	)
	expect(found, `the ${name} type`).toBeTruthy()
	return found!.id
}

/**
 * Store a login secret with the owner's own encryption.
 *
 * @param owner The owner.
 * @param name The name.
 * @param value The value.
 * @param folderId An optional folder.
 * @return The id.
 */
async function createSecret(
	owner: Session,
	name: string,
	value: Value,
	folderId: string | null = null,
): Promise<string> {
	return withStore<string>(
		owner.page,
		'secret',
		`async (store, data) => (await store.createSecret({
			name: data.name, login: data.value.login, key: data.value.key,
			additionalFields: data.value.additionalFields, typeId: data.typeId,
			folderId: data.folderId,
		})).id`,
		{ name, value, typeId: await typeId(owner.page, 'login'), folderId },
	)
}

/**
 * Share a secret with each recipient, as the bulk share dialog does.
 *
 * @param owner The owner.
 * @param sourceId The secret.
 * @param recipients The recipients, each with a vault.
 * @param inPlace Share without navigating, when the page already uses the share store.
 * @return The recipient copy id per uid.
 */
async function share(
	owner: Session,
	sourceId: string,
	recipients: Session[],
	inPlace = false,
): Promise<Record<string, string>> {
	const certificates: Record<string, string> = {}
	for (const recipient of recipients) {
		const response = await api(
			owner.page,
			'GET',
			`/shares/recipient-certificate?userId=${encodeURIComponent(recipient.user.uid)}`,
		)
		expect(response.status).toBe(200)
		certificates[recipient.user.uid] = response.body.certificate
	}
	if (!inPlace) {
		// The detail view puts the share store in use.
		await openDetail(owner.page, sourceId)
	}
	const items = await withStore<
		Array<{ status: string; targetUserId: string; recipientSecretId: string }>
	>(
		owner.page,
		'share',
		`async (store, data) => {
			const secrets = document.querySelector('#keepiq-app').__vue_app__.config.globalProperties.$pinia._s.get('secret')
			const plain = await secrets.fetchSecret(data.sourceId)
			const rows = []
			for (const [uid, certificate] of Object.entries(data.certificates)) {
				const blob = await store.encryptForRecipient({
					key: plain.key ?? '', login: plain.login ?? '',
					additionalFields: plain.additionalFields ? JSON.stringify(plain.additionalFields) : '',
				}, certificate)
				rows.push({
					sourceSecretId: data.sourceId, targetUserId: uid,
					encryptedKey: blob.key ?? '', encryptedLogin: blob.login ?? null,
					encryptedAdditionalFields: blob.additionalFields ?? null,
				})
			}
			const { items } = await store.registerBatch(rows, { masterPassword: data.masterPassword })
			return items.map((i, n) => ({ ...i, targetUserId: rows[n].targetUserId }))
		}`,
		{ sourceId, certificates, masterPassword: owner.user.masterPassword },
	)
	const copies: Record<string, string> = {}
	for (const item of items) {
		expect(item.status, JSON.stringify(item)).toBe('created')
		copies[item.targetUserId] = item.recipientSecretId
	}
	return copies
}

/**
 * The value of a row, decrypted by the holder's own store.
 *
 * @param session The holder.
 * @param id The row.
 * @return The value.
 */
async function readValue(session: Session, id: string): Promise<Value> {
	return withStore<Value>(
		session.page,
		'secret',
		`async (store, id) => {
			const plain = await store.fetchSecret(id)
			return { key: plain.key, login: plain.login, additionalFields: plain.additionalFields }
		}`,
		id,
	)
}

/**
 * Open a row's detail view, fresh from the server.
 *
 * @param page An unlocked page.
 * @param id The row.
 */
async function openDetail(page: Page, id: string): Promise<void> {
	await openList(page)
	await gotoVaultRoute(page, `secrets/${id}`)
	await expect(page.locator('.secret-detail__card')).toBeVisible({
		timeout: 30_000,
	})
}

/**
 * Assert the holder's detail view shows this value, and that their own
 * decrypt returns exactly it.
 *
 * @param session The holder.
 * @param id The holder's row.
 * @param value The expected value.
 */
async function expectShows(
	session: Session,
	id: string,
	value: Value,
): Promise<void> {
	await ensureUnlocked(session)
	expect(await readValue(session, id)).toEqual(value)
	const { page } = session
	await openDetail(page, id)
	await expect(
		page.locator('.secret-detail__row', { hasText: 'Login' }).first(),
	).toContainText(value.login)
	await page
		.locator('.secret-detail button[aria-label="Show"]')
		.first()
		.evaluate((el: HTMLElement) => el.click())
	await expect(
		page.locator('.secret-detail .keepiq-password-field input').first(),
	).toHaveValue(value.key, { timeout: 15_000 })
	const extra = page.locator('.secret-detail__extra')
	for (const [field, text] of Object.entries(value.additionalFields)) {
		await expect(extra).toContainText(field)
		await expect(extra).toContainText(text)
	}
}

/**
 * Open the edit dialog of a row from its detail view.
 *
 * @param page An unlocked page.
 * @param id The row.
 */
async function openEdit(page: Page, id: string): Promise<void> {
	await openDetail(page, id)
	await page
		.getByTestId('secret-detail-edit')
		.evaluate((el: HTMLElement) => el.click())
	await expect(page.locator('.secret-form')).toBeVisible({ timeout: 20_000 })
	await expect(
		page.locator('.secret-form input[type="password"]').first(),
	).not.toHaveValue('', { timeout: 20_000 })
}

/**
 * Share a secret with a group through the sidebar's group-share form.
 *
 * @param owner The owner.
 * @param sourceId The secret.
 * @param groupId The group.
 * @param received How many members should receive a copy.
 */
async function shareWithGroup(
	owner: Session,
	sourceId: string,
	groupId: string,
	received: number,
): Promise<void> {
	const { page } = owner
	await openDetail(page, sourceId)
	await page
		.getByTestId('secret-detail-more-info')
		.evaluate((el: HTMLElement) => el.click())
	await page
		.getByTestId('group-share-open-form')
		.evaluate((el: HTMLElement) => el.click())
	const form = page.getByTestId('group-share-form')
	const box = form.getByRole('combobox')
	await box.click()
	await box.fill(groupId)
	await page.getByRole('option').filter({ hasText: groupId }).first().click()
	await form.getByTestId('group-share-form-submit').click()
	const prompt = page.getByTestId('key-proof-prompt')
	await expect(prompt).toBeVisible({ timeout: 20_000 })
	await prompt.getByLabel('Your master password').fill(owner.user.masterPassword)
	await page.getByTestId('key-proof-prompt-confirm').click()
	await expect(page.getByTestId('group-share-result')).toContainText(
		`${received} members received it, 0 did not`,
		{ timeout: 60_000 },
	)
}

/**
 * The write context the holder's browser reads for a row.
 *
 * @param session The holder.
 * @param id The row.
 * @return The status and the context.
 */
async function writeContext(
	session: Session,
	id: string,
): Promise<{ status: number; body: any }> {
	return api(session.page, 'GET', `/secrets/${id}/write-context`)
}

/**
 * Send a share-save of a new password from a holder's row that the server's writer check refuses with `not_allowed` before it looks at the base version.
 * The request carries no copies and is encrypted for the holder's own certificate.
 *
 * @param session The holder.
 * @param id The holder's row.
 * @param password The new password.
 * @return The status and the answer; a refusal leaves as 428 through OcsRefusalMiddleware.
 */
async function shareSaveFrom(
	session: Session,
	id: string,
	password: string,
): Promise<{ status: number; body: any }> {
	await ensureUnlocked(session)
	await openDetail(session.page, id)
	const current = await row(session.page, id)
	const key = await withStore<string>(
		session.page,
		'share',
		`async (store, key) => {
			const pinia = document.querySelector('#keepiq-app').__vue_app__.config.globalProperties.$pinia
			const certificate = pinia._s.get('session').certificate
			return (await store.encryptForRecipient({ key }, certificate)).key
		}`,
		password,
	)
	return api(session.page, 'PUT', `/secrets/${id}/share-save`, {
		baseUpdatedAt: current.updatedAt,
		patch: { key },
		copies: [],
	})
}

/**
 * Type a new login into the open edit dialog.
 *
 * @param page A page with the edit dialog open.
 * @param login The new login.
 */
async function fillLogin(page: Page, login: string): Promise<void> {
	await page
		.locator('.secret-form')
		.getByLabel('Login (optional)')
		.fill(login, { force: true })
}

/**
 * Type a new password into the open edit dialog and press Save.
 *
 * @param page A page with the edit dialog open.
 * @param password The new password.
 */
async function savePassword(page: Page, password: string): Promise<void> {
	await page
		.locator('.secret-form input[type="password"]')
		.first()
		.fill(password, { force: true })
	const save = page.getByRole('button', { name: 'Save', exact: true })
	await expect(save).toBeEnabled()
	await save.evaluate((el: HTMLElement) => el.click())
}

/**
 * Edit only the password through the dialog and wait for it to close.
 *
 * @param page An unlocked page.
 * @param id The row.
 * @param password The new password.
 */
async function editPassword(
	page: Page,
	id: string,
	password: string,
): Promise<void> {
	await openEdit(page, id)
	await savePassword(page, password)
	await expect(page.locator('.secret-form')).toHaveCount(0, { timeout: 60_000 })
}

/**
 * The row as the server holds it.
 *
 * @param page A page of the holder.
 * @param id The row.
 * @return The row.
 */
async function row(page: Page, id: string): Promise<Record<string, any>> {
	const response = await api(page, 'GET', `/secrets/${id}`)
	expect(response.status).toBe(200)
	return response.body
}

/**
 * A holder's copy of a shared secret, found by name.
 *
 * @param session The holder.
 * @param name The secret's name.
 * @return The copy id.
 */
async function copyByName(session: Session, name: string): Promise<string> {
	const listed = await api(session.page, 'GET', '/secrets?limit=200')
	const copy = (listed.body.items as Array<{ id: string; name: string }>).find(
		(s) => s.name === name,
	)
	expect(copy, `${session.user.uid} holds ${name}`).toBeTruthy()
	return copy!.id
}

/**
 * A secret's first value, unique per scenario.
 *
 * @param tag The scenario.
 * @return The value.
 */
function original(tag: string): Value {
	return {
		key: `Original-pass-${tag}-1!`,
		login: `login-${tag}@example.test`,
		additionalFields: { 'client-id': `client-${tag}` },
	}
}

test.describe('atomic share-save, end to end', () => {
	let admin: APIRequestContext
	let alice: Session
	let bob: Session
	let carol: Session
	let dave: Session
	const users = ['alice', 'bob', 'carol', 'dave'].map((p) =>
		newVaultUser(`ss${p}`),
	)
	/** The groups the tests created, deleted in afterAll. */
	const groups: string[] = []

	/**
	 * A new group with these members.
	 *
	 * @param members The members.
	 * @return The group id.
	 */
	async function newGroup(members: Session[]): Promise<string> {
		const groupId = `e2e-share-save-${Date.now().toString(36)}`
		const created = await admin.post('/ocs/v2.php/cloud/groups?format=json', {
			form: { groupid: groupId },
		})
		expect(created.status(), await created.text()).toBe(200)
		groups.push(groupId)
		for (const member of members) {
			const added = await admin.post(
				`/ocs/v2.php/cloud/users/${encodeURIComponent(member.user.uid)}/groups?format=json`,
				{ form: { groupid: groupId } },
			)
			expect(added.status(), await added.text()).toBe(200)
		}
		return groupId
	}

	test.beforeAll(async ({ browser }) => {
		test.setTimeout(300_000)
		admin = await adminApi(BASE_URL)
		await createUsers(admin, users)
		;[alice, bob, carol, dave] = await Promise.all(
			users.map((user) => startSession(browser, user)),
		)
	})

	test.afterAll(async () => {
		test.setTimeout(300_000)
		for (const session of [alice, bob, carol, dave]) {
			await session?.context.close().catch(() => undefined)
		}
		for (const group of groups) {
			await admin
				.delete(`/ocs/v2.php/cloud/groups/${group}?format=json`)
				.catch(() => undefined)
		}
		await deleteUsers(admin, users)
		await admin.dispose()
		expect(external, 'requests that left the instance').toEqual([])
	})

	test('an owner edit reaches the recipient and keeps the untouched fields', async () => {
		const value = original('one')
		const name = `Owner edit ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value)
		const copies = await share(alice, sourceId, [bob])

		const watch = watchApi(alice.page)
		await openEdit(alice.page, sourceId)
		watch.reset()
		await savePassword(alice.page, 'Changed-pass-one-2!')
		await expect(alice.page.locator('.secret-form')).toHaveCount(0, {
			timeout: 60_000,
		})
		expectShareSaveOnly(await watch.calls(), sourceId, [
			[409, 'incomplete'],
			[200],
		])

		const changed = { ...value, key: 'Changed-pass-one-2!' }
		await expectShows(bob, copies[bob.user.uid], changed)
		await expectShows(alice, sourceId, changed)
	})

	test('a write-grade copy editor saves for the owner and every other holder', async () => {
		const folder = await api(alice.page, 'POST', '/folders', {
			name: `Team ${alice.user.uid}`,
		})
		expect(folder.status).toBe(201)
		const value = original('two')
		const name = `Copy editor ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value, folder.body.id)
		const teamFolder = await withStore<{ id: string }>(
			alice.page,
			'teamFolder',
			'async (store, folderId) => store.shareFolder(folderId)',
			folder.body.id,
		)
		for (const member of [bob, carol]) {
			await withStore(
				alice.page,
				'teamFolder',
				`async (store, data) => {
					await store.addMember(data.teamFolderId, 'user', data.uid)
					return await store.runFanOut(data.teamFolderId)
				}`,
				{ teamFolderId: teamFolder.id, uid: member.user.uid },
			)
		}
		const members = await api(
			alice.page,
			'GET',
			`/team-folders/${teamFolder.id}/members`,
		)
		const bobMember = (
			members.body as Array<{ id: string; memberId: string }>
		).find((m) => m.memberId === bob.user.uid)
		await withStore(
			alice.page,
			'teamFolder',
			"async (store, data) => store.setMemberGrade(data.teamFolderId, data.memberId, 'write')",
			{ teamFolderId: teamFolder.id, memberId: bobMember?.id },
		)
		const bobCopy = await copyByName(bob, name)
		const carolCopy = await copyByName(carol, name)

		const watch = watchApi(bob.page)
		await openEdit(bob.page, bobCopy)
		watch.reset()
		await savePassword(bob.page, 'Bob-wrote-this-2!')
		await expect(bob.page.locator('.secret-form')).toHaveCount(0, {
			timeout: 60_000,
		})
		expectShareSaveOnly(await watch.calls(), bobCopy, [
			[409, 'incomplete'],
			[200],
		])

		const changed = { ...value, key: 'Bob-wrote-this-2!' }
		await expectShows(alice, sourceId, changed)
		await expectShows(carol, carolCopy, changed)
		await expectShows(bob, bobCopy, changed)
	})

	test('a direct-share recipient sees their copy as view only and still files and archives it', async () => {
		const value = original('view')
		const sourceId = await createSecret(
			alice,
			`View only ${alice.user.uid}`,
			value,
		)
		const copies = await share(alice, sourceId, [bob])
		const bobCopy = copies[bob.user.uid]
		const folder = await api(bob.page, 'POST', '/folders', {
			name: `Filed ${bob.user.uid}`,
		})
		expect(folder.status).toBe(201)

		await openDetail(bob.page, bobCopy)
		await expect(bob.page.getByTestId('secret-detail-view-only')).toHaveText(
			'Shared with you to view only. Ask its owner for edit rights to change it.',
			{ timeout: 20_000 },
		)
		await expect(bob.page.getByTestId('secret-detail-edit')).toHaveCount(0)

		// Moving goes through the store the move dialog calls.
		const watch = watchApi(bob.page)
		await withStore(
			bob.page,
			'secret',
			'async (store, data) => store.updateSecret(data.id, { folderId: data.folderId })',
			{ id: bobCopy, folderId: folder.body.id },
		)

		// The server holds the same line: a new value is refused with
		// `view_only`, filing the copy is not.
		const certificate = await withStore<string>(
			bob.page,
			'session',
			'async (store) => store.certificate',
		)
		const key = await withStore<string>(
			bob.page,
			'share',
			'async (store, data) => (await store.encryptForRecipient({ key: data.key }, data.cert)).key',
			{ key: 'Never-saved-view-1!', cert: certificate },
		)
		const refused = await api(bob.page, 'PUT', `/secrets/${bobCopy}`, { key })
		expect(refused.status, JSON.stringify(refused.body)).toBe(428)
		expect(refused.body.error).toBe('view_only')
		const filed = await api(bob.page, 'PUT', `/secrets/${bobCopy}`, {
			folderId: folder.body.id,
		})
		expect(filed.status, JSON.stringify(filed.body)).toBe(200)

		await bob.page
			.getByRole('button', { name: /Secret actions/i })
			.evaluate((el: HTMLElement) => el.click())
		await expect(bob.page.getByRole('menuitem', { name: 'Move' })).toBeVisible()
		await bob.page.getByRole('menuitem', { name: 'Archive' }).click()
		await expect
			.poll(async () => Boolean((await row(bob.page, bobCopy)).archivedAt), {
				timeout: 30_000,
			})
			.toBe(true)

		expect((await row(bob.page, bobCopy)).folderId).toBe(folder.body.id)
		expect(
			(await watch.calls()).filter((c) => c.path.endsWith('/share-save')),
		).toEqual([])
		expect(await readValue(bob, bobCopy)).toEqual(value)
		expect(await readValue(alice, sourceId)).toEqual(value)
	})

	test('a member of a group the secret is shared with saves for the owner and every other member', async () => {
		// @e2e sharing-group::a-group-member-saves-for-everyone
		// @e2e sharing-group::a-group-members-write-context-carries-the-write-grade
		// @e2e sharing-group::knowing-a-copys-id-reveals-nothing
		// @e2e user-sharing::recipient-updates-shared-secret
		const groupId = await newGroup([alice, bob, carol])
		const value = original('nine')
		const name = `Group member edit ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value)
		await shareWithGroup(alice, sourceId, groupId, 2)
		const bobCopy = await copyByName(bob, name)
		const carolCopy = await copyByName(carol, name)

		const context = await writeContext(bob, bobCopy)
		expect(context.status, JSON.stringify(context.body)).toBe(200)
		expect(context.body.effectiveGrade).toBe('write')
		expect(context.body.ownerCertificate).toBe(
			await withStore<string>(
				alice.page,
				'session',
				'async (store) => store.certificate',
			),
		)
		const unknown = await writeContext(dave, randomUUID())
		expect(unknown.status).toBe(404)
		expect(await writeContext(dave, bobCopy)).toEqual(unknown)

		await ensureUnlocked(bob)
		const watch = watchApi(bob.page)
		await openEdit(bob.page, bobCopy)
		watch.reset()
		await fillLogin(bob.page, 'bob-wrote-this@example.test')
		await savePassword(bob.page, 'Bob-wrote-this-9!')
		await expect(bob.page.locator('.secret-form')).toHaveCount(0, {
			timeout: 60_000,
		})
		expectShareSaveOnly(await watch.calls(), bobCopy, [
			[409, 'incomplete'],
			[200],
		])

		const changed = {
			...value,
			login: 'bob-wrote-this@example.test',
			key: 'Bob-wrote-this-9!',
		}
		await expectShows(alice, sourceId, changed)
		await expectShows(carol, carolCopy, changed)
		await expectShows(bob, bobCopy, changed)

		const audit = await api(alice.page, 'GET', `/audit/secret/${sourceId}`)
		expect(audit.status).toBe(200)
		const entries = audit.body.entries as Array<{
			eventType: string
			actorId: string
			metadata: Record<string, unknown>
		}>
		expect(
			entries
				.filter(
					(e) =>
						e.eventType === 'secret.updated'
						&& e.actorId === bob.user.uid,
				)
				.map((e) => e.metadata.changedFields),
			JSON.stringify(entries),
		).toEqual(['group-write sync'])
	})

	test('a member whose copy came through a direct share saves for everyone, and is refused once they left the group', async () => {
		// @e2e sharing-group::a-member-whose-copy-came-another-way-saves-for-everyone
		// @e2e sharing-group::a-member-who-left-the-group-is-refused
		const groupId = await newGroup([alice, bob, carol])
		const value = original('ten')
		const name = `Direct then group ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value)
		const bobCopy = (await share(alice, sourceId, [bob]))[bob.user.uid]
		await shareWithGroup(alice, sourceId, groupId, 2)
		const carolCopy = await copyByName(carol, name)
		expect((await writeContext(bob, bobCopy)).body.effectiveGrade).toBe('write')

		await ensureUnlocked(bob)
		const watch = watchApi(bob.page)
		await openEdit(bob.page, bobCopy)
		watch.reset()
		await savePassword(bob.page, 'Bob-direct-copy-10!')
		await expect(bob.page.locator('.secret-form')).toHaveCount(0, {
			timeout: 60_000,
		})
		expectShareSaveOnly(await watch.calls(), bobCopy, [
			[409, 'incomplete'],
			[200],
		])
		const changed = { ...value, key: 'Bob-direct-copy-10!' }
		await expectShows(alice, sourceId, changed)
		await expectShows(carol, carolCopy, changed)

		// Leaving removes only copies of the group share, so Bob's direct copy stays.
		const left = await admin.delete(
			`/ocs/v2.php/cloud/users/${encodeURIComponent(bob.user.uid)}/groups?format=json&groupid=${groupId}`,
		)
		expect(left.status(), await left.text()).toBe(200)
		expect(await copyByName(bob, name)).toBe(bobCopy)
		const context = await writeContext(bob, bobCopy)
		expect(context.body.effectiveGrade, JSON.stringify(context.body)).toBe(
			'none',
		)
		const before = await row(alice.page, sourceId)
		const refused = await shareSaveFrom(bob, bobCopy, 'Bob-after-leaving-10!')
		expect([refused.status, refused.body.error]).toEqual([428, 'not_allowed'])
		expect((await row(alice.page, sourceId)).updatedAt).toBe(before.updatedAt)
		await expectShows(alice, sourceId, changed)
		await expectShows(carol, carolCopy, changed)
		await expectShows(bob, bobCopy, changed)
	})

	test('a direct recipient in no group the secret is shared with holds a view-only copy', async () => {
		// @e2e sharing-group::a-direct-share-recipient-cannot-change-their-copy
		// @e2e user-sharing::recipient-updates-shared-secret
		const groupId = await newGroup([alice, bob, carol])
		const value = original('eleven')
		const name = `Direct edit ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value)
		await shareWithGroup(alice, sourceId, groupId, 2)
		const daveCopy = (await share(alice, sourceId, [dave]))[dave.user.uid]
		const bobCopy = await copyByName(bob, name)
		const carolCopy = await copyByName(carol, name)

		const context = await writeContext(dave, daveCopy)
		expect(context.body.effectiveGrade, JSON.stringify(context.body)).toBe(
			'none',
		)
		const refused = await shareSaveFrom(dave, daveCopy, 'Dave-everyone-11!')
		expect([refused.status, refused.body.error]).toEqual([428, 'not_allowed'])

		// Without a write grade the copy is view-only (user-sharing).
		await openDetail(dave.page, daveCopy)
		await expect(dave.page.getByTestId('secret-detail-view-only')).toBeVisible({
			timeout: 20_000,
		})
		await expect(dave.page.getByTestId('secret-detail-edit')).toHaveCount(0)
		const certificate = await withStore<string>(
			dave.page,
			'session',
			'async (store) => store.certificate',
		)
		const key = await withStore<string>(
			dave.page,
			'share',
			'async (store, data) => (await store.encryptForRecipient({ key: data.key }, data.cert)).key',
			{ key: 'Dave-wrote-this-11!', cert: certificate },
		)
		const ownCopy = await api(dave.page, 'PUT', `/secrets/${daveCopy}`, { key })
		expect(ownCopy.status, JSON.stringify(ownCopy.body)).toBe(428)
		expect(ownCopy.body.error).toBe('view_only')

		await expectShows(dave, daveCopy, value)
		await expectShows(alice, sourceId, value)
		await expectShows(bob, bobCopy, value)
		await expectShows(carol, carolCopy, value)
	})

	test('a read-grade member in no group the secret is shared with is refused and changes no copy', async () => {
		// @e2e folder-permission-grades::read-grade-member-cannot-write
		const folder = await api(alice.page, 'POST', '/folders', {
			name: `Read team ${alice.user.uid}`,
		})
		expect(folder.status).toBe(201)
		const value = original('twelve')
		const name = `Read grade ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value, folder.body.id)
		const teamFolder = await withStore<{ id: string }>(
			alice.page,
			'teamFolder',
			'async (store, folderId) => store.shareFolder(folderId)',
			folder.body.id,
		)
		for (const member of [bob, carol]) {
			await withStore(
				alice.page,
				'teamFolder',
				`async (store, data) => {
					await store.addMember(data.teamFolderId, 'user', data.uid)
					return await store.runFanOut(data.teamFolderId)
				}`,
				{ teamFolderId: teamFolder.id, uid: member.user.uid },
			)
		}
		const bobCopy = await copyByName(bob, name)
		const carolCopy = await copyByName(carol, name)

		const context = await writeContext(bob, bobCopy)
		expect(context.body.effectiveGrade, JSON.stringify(context.body)).toBe(
			'read',
		)
		const before = await row(alice.page, sourceId)
		const refused = await shareSaveFrom(bob, bobCopy, 'Bob-read-only-12!')
		expect([refused.status, refused.body.error]).toEqual([428, 'not_allowed'])
		expect((await row(alice.page, sourceId)).updatedAt).toBe(before.updatedAt)
		await expectShows(alice, sourceId, value)
		await expectShows(carol, carolCopy, value)
		await expectShows(bob, bobCopy, value)
	})

	test('a save from a stale dialog is refused, shown, and changes nothing', async () => {
		const value = original('three')
		const sourceId = await createSecret(alice, `Stale ${alice.user.uid}`, value)
		const copies = await share(alice, sourceId, [bob])

		const second = await alice.context.newPage()
		try {
			await second.goto('/index.php/apps/keepiq/lock', {
				waitUntil: 'domcontentloaded',
			})
			await lockAndUnlock({ ...alice, page: second })
			await openEdit(alice.page, sourceId)
			await openEdit(second, sourceId)

			await savePassword(alice.page, 'First-tab-wins-3!')
			await expect(alice.page.locator('.secret-form')).toHaveCount(0, {
				timeout: 60_000,
			})
			const afterFirst = await row(alice.page, sourceId)

			const watch = watchApi(second)
			await savePassword(second, 'Second-tab-loses-3!')
			await expect(second.getByTestId('secret-edit-error')).toContainText(
				STALE_TEXT,
				{ timeout: 30_000 },
			)
			await expect(second.locator('.secret-form')).toBeVisible()
			expectShareSaveOnly(await watch.calls(), sourceId, [[409, 'stale']])
			expect((await row(alice.page, sourceId)).updatedAt).toBe(
				afterFirst.updatedAt,
			)
		} finally {
			await second.close()
		}

		const kept = { ...value, key: 'First-tab-wins-3!' }
		await expectShows(bob, copies[bob.user.uid], kept)
		await expectShows(alice, sourceId, kept)
	})

	test('a refusal of the owner row inside the transaction rolls the copies back', async () => {
		const value = original('four')
		const sourceId = await createSecret(
			alice,
			`Rollback ${alice.user.uid}`,
			value,
		)
		const copies = await share(alice, sourceId, [bob])
		const before = await row(alice.page, sourceId)
		const versionsBefore = await api(
			alice.page,
			'GET',
			`/secrets/${sourceId}/versions`,
		)

		const certificate = await withStore<string>(
			alice.page,
			'session',
			'async (store) => store.certificate',
		)
		const encrypt = (key: string, cert: string) =>
			withStore<string>(
				alice.page,
				'share',
				'async (store, data) => (await store.encryptForRecipient({ key: data.key }, data.cert)).key',
				{ key, cert },
			)
		const body = {
			baseUpdatedAt: before.updatedAt,
			patch: {
				name: '   ',
				key: await encrypt('Never-saved-4!', certificate),
			},
			copies: [] as Array<{ secretId: string; key: string }>,
		}
		const first = await api(
			alice.page,
			'PUT',
			`/secrets/${sourceId}/share-save`,
			body,
		)
		expect(first.status, JSON.stringify(first.body)).toBe(409)
		expect(first.body.error).toBe('incomplete')
		const required = first.body.copies.required as Array<{
			secretId: string
			targetUserId: string
		}>
		expect(required.map((r) => r.secretId)).toEqual([copies[bob.user.uid]])
		const certificates = await api(
			alice.page,
			'POST',
			'/shares/recipient-certificates',
			{
				userIds: required.map((r) => r.targetUserId),
			},
		)
		for (const holder of required) {
			const cert = (
				certificates.body.recipients as Array<{
					userId: string
					certificate: string
				}>
			).find((r) => r.userId === holder.targetUserId)!.certificate
			body.copies.push({
				secretId: holder.secretId,
				key: await encrypt('Never-saved-4!', cert),
			})
		}

		const refused = await api(
			alice.page,
			'PUT',
			`/secrets/${sourceId}/share-save`,
			body,
		)
		test.info().annotations.push({
			type: 'refusal',
			description: `${refused.status} ${JSON.stringify(refused.body)}`,
		})
		expect(refused.status, JSON.stringify(refused.body)).toBe(400)
		// Only SecretService::update says this, and it runs after the copies are written.
		expect(refused.body.message).toBe('Secret name cannot be empty')

		const after = await row(alice.page, sourceId)
		expect(after.updatedAt).toBe(before.updatedAt)
		expect(after.key).toBe(before.key)
		expect(after.name).toBe(before.name)
		const versionsAfter = await api(
			alice.page,
			'GET',
			`/secrets/${sourceId}/versions`,
		)
		expect(versionsAfter.body).toEqual(versionsBefore.body)
		await expectShows(bob, copies[bob.user.uid], value)
	})

	test('a version restore reaches the recipient', async () => {
		const value = original('five')
		const sourceId = await createSecret(
			alice,
			`Restore ${alice.user.uid}`,
			value,
		)
		const copies = await share(alice, sourceId, [bob])
		await editPassword(alice.page, sourceId, 'Replaced-pass-5!')
		await expectShows(bob, copies[bob.user.uid], {
			...value,
			key: 'Replaced-pass-5!',
		})

		const versions = await api(
			alice.page,
			'GET',
			`/secrets/${sourceId}/versions`,
		)
		const list = (versions.body.items ?? versions.body) as Array<{
			versionNumber: number
		}>
		expect(list, JSON.stringify(versions.body)).toHaveLength(1)
		const number = list[0].versionNumber

		const watch = watchApi(alice.page)
		await openDetail(alice.page, sourceId)
		await alice.page
			.getByTestId('secret-detail-advanced')
			.evaluate((el: HTMLElement) => el.click())
		await alice.page
			.getByTestId(`version-restore-${number}`)
			.evaluate((el: HTMLElement) => el.click())
		watch.reset()
		const saved = alice.page.waitForResponse(
			(r) =>
				r.url().endsWith(`/secrets/${sourceId}/share-save`)
				&& r.status() !== 409,
			{ timeout: 60_000 },
		)
		await alice.page
			.getByTestId('version-restore-confirm')
			.evaluate((el: HTMLElement) => el.click())
		expect((await saved).status(), await (await saved).text()).toBe(200)
		await expect(alice.page.getByTestId('version-error')).toHaveCount(0)
		const calls = await watch.calls()
		expectShareSaveOnly(calls, sourceId, [[409, 'incomplete'], [200]])
		expect(calls.filter((c) => c.path.endsWith('/restore'))).toEqual([])

		await expectShows(bob, copies[bob.user.uid], value)
		await expectShows(alice, sourceId, value)
	})

	test('a share added during the save is caught by another incomplete round', async () => {
		const value = original('six')
		const name = `Incomplete ${alice.user.uid}`
		const sourceId = await createSecret(alice, name, value)
		const copies = await share(alice, sourceId, [bob])

		// Dave is shared with after the server named the copies it needs, so the
		// client's second request misses him and has to go round once more.
		let shared = false
		await alice.page.route(
			`**/secrets/${sourceId}/share-save`,
			async (route) => {
				const response = await route.fetch()
				if (!shared) {
					shared = true
					Object.assign(copies, await share(alice, sourceId, [dave], true))
				}
				await route.fulfill({ response })
			},
		)
		const watch = watchApi(alice.page)
		try {
			await openEdit(alice.page, sourceId)
			watch.reset()
			await savePassword(alice.page, 'Reached-dave-too-6!')
			await expect(alice.page.locator('.secret-form')).toHaveCount(0, {
				timeout: 60_000,
			})
		} finally {
			await alice.page.unroute(`**/secrets/${sourceId}/share-save`)
		}
		expect(shared).toBe(true)
		expectShareSaveOnly(await watch.calls(), sourceId, [
			[409, 'incomplete'],
			[409, 'incomplete'],
			[200],
		])

		const changed = { ...value, key: 'Reached-dave-too-6!' }
		await expectShows(dave, copies[dave.user.uid], changed)
		await expectShows(bob, copies[bob.user.uid], changed)
	})

	test('a save for more than 100 copies is staged and commits once', async ({
		browser,
	}) => {
		// Setting up 99 more vaults in the browser takes about ten minutes.
		test.skip(!process.env.KEEPIQ_E2E_STAGING, 'set KEEPIQ_E2E_STAGING=1 to run')
		test.setTimeout(2_400_000)
		const extra = Array.from({ length: 99 }, (_, n) => newVaultUser(`ssm${n}`))
		const started = Date.now()
		await createUsers(admin, extra)
		try {
			for (let i = 0; i < extra.length; i += 10) {
				await Promise.all(
					extra.slice(i, i + 10).map(async (user) => {
						const session = await startSession(browser, user)
						await session.context.close()
					}),
				)
			}
			console.log(
				`${extra.length} extra vaults in ${Math.round((Date.now() - started) / 1000)}s`,
			)

			await ensureUnlocked(alice)
			const value = original('eight')
			const sourceId = await createSecret(
				alice,
				`Staged ${alice.user.uid}`,
				value,
			)
			const copies = await share(alice, sourceId, [bob, dave])
			const certificates = await api(
				alice.page,
				'POST',
				'/shares/recipient-certificates',
				{
					userIds: extra.map((u) => u.uid),
				},
			)
			expect(certificates.status).toBe(200)
			const rows = await withStore<number>(
				alice.page,
				'share',
				`async (store, data) => {
					const secrets = document.querySelector('#keepiq-app').__vue_app__.config.globalProperties.$pinia._s.get('secret')
					const plain = await secrets.fetchSecret(data.sourceId)
					const rows = []
					for (const r of data.recipients) {
						const blob = await store.encryptForRecipient({
							key: plain.key, login: plain.login,
							additionalFields: JSON.stringify(plain.additionalFields),
						}, r.certificate)
						rows.push({
							sourceSecretId: data.sourceId, targetUserId: r.userId,
							encryptedKey: blob.key, encryptedLogin: blob.login,
							encryptedAdditionalFields: blob.additionalFields,
						})
					}
					const { items } = await store.registerBatch(rows, { masterPassword: data.masterPassword })
					return items.filter((i) => i.status === 'created').length
				}`,
				{
					sourceId,
					recipients: certificates.body.recipients,
					masterPassword: alice.user.masterPassword,
				},
			)
			expect(rows).toBe(extra.length)

			await ensureUnlocked(alice)
			const watch = watchApi(alice.page)
			await openEdit(alice.page, sourceId)
			watch.reset()
			await savePassword(alice.page, 'Staged-for-all-8!')
			await expect(alice.page.locator('.secret-form')).toHaveCount(0, {
				timeout: 120_000,
			})
			expectShareSaveOnly(await watch.calls(), sourceId, [
				[409, 'incomplete'],
				[202],
				[200],
			])

			const changed = { ...value, key: 'Staged-for-all-8!' }
			await expectShows(bob, copies[bob.user.uid], changed)
			await expectShows(dave, copies[dave.user.uid], changed)
		} finally {
			await deleteUsers(admin, extra)
		}
	})

	test('an offline edit is replayed through the share-save', async () => {
		test.setTimeout(240_000)
		const settings = await admin.get(
			'/index.php/apps/keepiq/api/settings/admin/general',
		)
		expect(settings.status()).toBe(200)
		const wasEnabled = (await settings.json())?.offline_edits_enabled === true
		const set = async (enabled: boolean) => {
			const response = await admin.put(
				'/index.php/apps/keepiq/api/settings/admin/general',
				{
					data: { offline_edits_enabled: enabled },
				},
			)
			expect(response.status(), await response.text()).toBe(200)
		}

		const value = original('seven')
		const sourceId = await createSecret(
			alice,
			`Offline ${alice.user.uid}`,
			value,
		)
		const copies = await share(alice, sourceId, [bob])
		await set(true)
		try {
			// An online unlock writes the snapshot the offline unlock opens.
			await lockAndUnlock(alice)
			await expect
				.poll(
					() =>
						withStore<string | null>(
							alice.page,
							'offline',
							'async (s) => s.syncedAt',
						),
					{
						timeout: 30_000,
					},
				)
				.not.toBeNull()

			await alice.context.setOffline(true)
			await lockAndUnlock(alice)
			expect(
				await withStore<boolean>(
					alice.page,
					'offline',
					'async (s) => s.servedFromCache && s.editsEnabled',
				),
			).toBe(true)
			await editPassword(alice.page, sourceId, 'Saved-offline-7!')
			expect(
				await withStore<number>(
					alice.page,
					'offline',
					'async (s) => s.pendingCount',
				),
			).toBe(1)

			await alice.context.setOffline(false)
			const watch = watchApi(alice.page)
			await lockAndUnlock(alice)
			await expect
				.poll(
					() =>
						withStore<number>(
							alice.page,
							'offline',
							'async (s) => s.pendingCount',
						),
					{
						timeout: 60_000,
					},
				)
				.toBe(0)
			expectShareSaveOnly(await watch.calls(), sourceId, [
				[409, 'incomplete'],
				[200],
			])
		} finally {
			await alice.context.setOffline(false)
			await set(wasEnabled)
		}

		await expectShows(bob, copies[bob.user.uid], {
			...value,
			key: 'Saved-offline-7!',
		})
	})
})
