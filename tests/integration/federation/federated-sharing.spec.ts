/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Federated sharing end to end on two Nextcloud 35 instances
 * (keepiq#789, sharing-federated-recipients task 5.1).
 *
 * A (FED_A_URL) and B (FED_B_URL) are provisioned by setup.sh: admin has the
 * development vault on both, each administrator pinned the other as a
 * partner, and admin on B opted in to receiving. Then, in real browsers:
 *
 *   1. admin on A shares "Production Database" with admin@<B> from the share
 *      dialog: the certificate is fetched over signed OCM, verified in the
 *      browser, its fingerprint shown, and only ciphertext is sent;
 *   2. admin on B accepts it under "Incoming from other organisations" and
 *      reads the value of the read-only copy in the browser;
 *   3. admin on A changes the password; B's copy shows the new one;
 *   4. admin on A revokes; B's copy is gone.
 *
 * A second test covers what the recipient still controls (tasks 3.5, 4.4,
 * 4.5): B files the copy in a folder, a new name from A reaches it while the
 * folder stays, and B deleting the copy shows the share as declined on A.
 *
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-password-change-reaches-bob
 * @spec openspec/specs/federated-sharing/spec.md#scenario-revocation-removes-bobs-copy
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-files-his-copy-in-a-folder
 * @spec openspec/specs/federated-sharing/spec.md#scenario-a-new-name-reaches-bob
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
 */

import type { Browser, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	DEV_MASTER_PASSWORD,
	gotoVaultRoute,
	unlockVault,
} from '../../e2e/workflows/_workflow-helpers.ts'

/**
 * A required instance URL; there is no default, because this spec writes
 * to both instances.
 *
 * @param {string} name The environment variable.
 * @return {string}
 */
function required(name: string): string {
	const value = (process.env[name] ?? '').trim().replace(/\/+$/, '')
	if (value === '') {
		throw new Error(
			`${name} is not set. Stand the pair up with tests/integration/federation/compose.yaml and setup.sh first.`,
		)
	}
	return value
}

const A = required('FED_A_URL')
const B = required('FED_B_URL')
const APP = '/index.php/apps/keepiq'
const SECRET_NAME = 'Production Database'
const NEW_PASSWORD = `rotated-${Date.now()}`
// Bob's own cloud id. Nextcloud reads a cloud id without a scheme as https,
// so a user of an http instance (as this pair is) is addressed with it.
const BOB = `admin@${B.replace(/^https:\/\//, '')}`
const ALICE_HOST = new URL(A).host

/**
 * A logged-in page on one instance, with the vault unlocked.
 *
 * @param {Browser} browser The browser.
 * @param {string} baseURL The instance.
 * @return {Promise<Page>}
 */
async function signIn(browser: Browser, baseURL: string): Promise<Page> {
	const context = await browser.newContext({ baseURL })
	const page = await context.newPage()
	await page.goto('/index.php/login', { waitUntil: 'domcontentloaded' })
	await page.locator('input[name="user"]').fill('admin')
	await page.locator('input[name="password"]').fill('admin')
	await page.locator('button[type="submit"]').first().click()
	await page.waitForURL((url) => !/\/login/.test(url.pathname), {
		timeout: 60_000,
	})
	// No product tour and no support note, as tests/e2e/global-setup.ts does
	// for the single-instance suite.
	await page.evaluate(() => {
		window.localStorage.setItem('cn-walkthrough-seen:keepiq', '999.0.0')
		window.localStorage.setItem('cn-support-dialog-shown:keepiq', '1')
	})
	await unlockVault(page)
	return page
}

/**
 * The id of a secret of the signed-in user, by name.
 *
 * @param {Page} page The page.
 * @param {string} name The secret's name.
 * @return {Promise<string>}
 */
async function secretId(page: Page, name: string): Promise<string> {
	const id = await page.evaluate(
		async ({ app, wanted }) => {
			const token =
				(window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
			const response = await fetch(`${app}/api/v1/secrets`, {
				headers: { requesttoken: token, Accept: 'application/json' },
			})
			const body = await response.json()
			const rows = Array.isArray(body) ? body : (body.items ?? [])
			return (
				rows.find((row: { name: string }) => row.name === wanted)?.id ?? ''
			)
		},
		{ app: APP, wanted: name },
	)
	expect(id, `${name} on ${page.url()}`).not.toBe('')
	return id
}

/**
 * Revoke what an earlier run left on this secret, so a reused pair starts
 * clean.
 *
 * @param {Page} page The owner's page.
 * @param {string} id The secret.
 * @return {Promise<void>}
 */
async function revokeLeftovers(page: Page, id: string): Promise<void> {
	await page.evaluate(
		async ({ app, secret }) => {
			const token =
				(window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
			const rows = await (
				await fetch(`${app}/api/v1/secrets/${secret}/federated-shares`, {
					headers: { requesttoken: token },
				})
			).json()
			for (const row of rows) {
				await fetch(`${app}/api/v1/federated-shares/${row.id}`, {
					method: 'DELETE',
					headers: { requesttoken: token },
				})
			}
		},
		{ app: APP, secret: id },
	)
}

/**
 * Open a secret in the detail sidebar.
 *
 * @param {Page} page The page.
 * @param {string} id The secret.
 * @return {Promise<void>}
 */
async function openSecret(page: Page, id: string): Promise<void> {
	// In-app navigation: a page load would lock the vault again.
	await gotoVaultRoute(page, 'secrets')
	await gotoVaultRoute(page, `secrets/${id}`)
	await expect(page.getByTestId('secret-detail-sidebar')).toBeVisible()
}

/**
 * The value of the open secret, revealed.
 *
 * @param {Page} page The page.
 * @return {Promise<string>}
 */
async function revealedValue(page: Page): Promise<string> {
	const sidebar = page.getByTestId('secret-detail-sidebar')
	await sidebar.getByRole('button', { name: 'Show' }).first().click()
	const field = sidebar.locator('.keepiq-password-field input').first()
	await expect(field).toHaveAttribute('type', 'text')
	return await field.inputValue()
}

/**
 * Share the open secret with Bob from the share dialog, with the vault key
 * proof, and check only ciphertext left.
 *
 * @param {Page} alice The owner's page, on the secret.
 * @param {string} plain The value that must not be sent.
 * @return {Promise<void>}
 */
async function shareWithBob(alice: Page, plain: string): Promise<void> {
	await alice.getByTestId('secret-detail-share').click()
	const form = alice.getByTestId('federated-share-form')
	await expect(form).toBeVisible()
	await form.getByLabel('Their account at the other organisation').fill(BOB)
	await form.getByTestId('federated-share-check').click()
	await expect(form.getByTestId('federated-share-fingerprint')).toHaveText(
		/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/,
	)
	const sent = alice.waitForResponse(
		(r) =>
			r.url().includes('/federated-shares')
			&& r.request().method() === 'POST'
			&& r.status() === 201,
	)
	await form.getByTestId('federated-share-submit').click()
	// Every recipient at another organisation is new: the vault key proof.
	const prompt = alice.getByRole('dialog', {
		name: 'Confirm with your master password',
	})
	await prompt.getByLabel('Your master password').fill(DEV_MASTER_PASSWORD)
	await prompt.getByRole('button', { name: 'Confirm' }).click()
	const body = (await (await sent).request().postDataJSON()) as Record<
		string,
		string
	>
	expect(JSON.stringify(body)).not.toContain(plain)
	await expect(form.getByTestId('federated-share-done')).toBeVisible()
	await expect(form.getByTestId('federated-share-list')).toContainText(BOB)
}

/**
 * Accept the newest pending share of a secret on B and open the copy.
 *
 * @param {Page} bob The recipient's page.
 * @param {string} name The secret's name.
 * @return {Promise<{row: import('@playwright/test').Locator, copyId: string}>}
 */
async function acceptOnB(bob: Page, name: string) {
	await gotoVaultRoute(bob, 'incoming')
	const pending = bob
		.locator('[data-testid^="incoming-share-"]')
		.filter({ hasText: name })
		.filter({ hasText: 'Waiting for your answer' })
		.first()
	await expect(pending).toBeVisible()
	const row = bob.getByTestId((await pending.getAttribute('data-testid')) ?? '')
	await row.getByTestId('incoming-share-accept').click()
	await expect(row.getByTestId('incoming-share-open')).toBeVisible()
	await row.getByTestId('incoming-share-open').click()
	await expect(bob.getByTestId('secret-detail-federated')).toContainText(
		ALICE_HOST,
	)
	const copyId = new URL(bob.url()).pathname.split('/').pop() ?? ''
	return { row, copyId }
}

/**
 * Call Keepiq's API from the signed-in page.
 *
 * @param {Page} page The page.
 * @param {string} method The HTTP method.
 * @param {string} path Below /apps/keepiq.
 * @param {object} [body] The JSON body.
 * @return {Promise<{status: number, data: any}>}
 */
async function call(page: Page, method: string, path: string, body?: object) {
	return await page.evaluate(
		async ({ url, verb, payload }) => {
			const token =
				(window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
			const response = await fetch(url, {
				method: verb,
				headers: {
					requesttoken: token,
					Accept: 'application/json',
					'Content-Type': 'application/json',
				},
				body: payload === undefined ? undefined : JSON.stringify(payload),
			})
			let data = null
			try {
				data = await response.json()
			} catch {
				// No body.
			}
			return { status: response.status, data }
		},
		{ url: `${APP}${path}`, verb: method, payload: body },
	)
}

test('share with a user of a partner instance, update, and revoke', async ({
	browser,
}) => {
	const alice = await signIn(browser, A)
	const sourceId = await secretId(alice, SECRET_NAME)
	await revokeLeftovers(alice, sourceId)

	// 1. Share from the share dialog. The value is whatever the source holds
	// now (a reused pair carries the last run's rotation).
	await openSecret(alice, sourceId)
	const oldPassword = await revealedValue(alice)
	expect(oldPassword).not.toBe('')
	await shareWithBob(alice, oldPassword)

	// 2. Bob accepts and reads it.
	const bob = await signIn(browser, B)
	const { row, copyId } = await acceptOnB(bob, SECRET_NAME)
	await expect(bob.getByTestId('secret-detail-edit')).toHaveCount(0)
	await expect(bob.getByTestId('secret-detail-share')).toHaveCount(0)
	expect(await revealedValue(bob)).toBe(oldPassword)

	// 3. Alice changes the password; the change reaches Bob's copy.
	await openSecret(alice, sourceId)
	await alice.getByTestId('secret-detail-edit').click()
	const synced = alice.waitForResponse(
		(r) =>
			/\/api\/v1\/federated-shares\/[0-9a-f-]+$/.test(r.url())
			&& r.request().method() === 'PUT',
		{ timeout: 60_000 },
	)
	const dialog = alice.getByRole('dialog', { name: 'Edit secret' })
	await dialog.getByRole('textbox', { name: 'Secret value' }).fill(NEW_PASSWORD)
	await dialog.getByRole('button', { name: 'Save' }).click()
	expect((await synced).status()).toBe(200)

	await expect
		.poll(
			async () => {
				await openSecret(bob, copyId)
				return await revealedValue(bob)
			},
			{ timeout: 60_000 },
		)
		.toBe(NEW_PASSWORD)

	// 4. Alice revokes; Bob's copy is gone.
	await openSecret(alice, sourceId)
	await alice.getByTestId('secret-detail-share').click()
	const shareRow = alice
		.locator('[data-testid^="federated-share-row-"]')
		.filter({ hasText: BOB })
	const revoked = alice.waitForResponse(
		(r) =>
			r.url().includes('/federated-shares/')
			&& r.request().method() === 'DELETE',
	)
	await shareRow.getByTestId('federated-share-revoke').click()
	expect((await revoked).status()).toBe(200)
	await expect(shareRow).toHaveCount(0)

	await gotoVaultRoute(bob, 'incoming')
	await expect(row.getByTestId('incoming-share-status')).toHaveText(
		'Withdrawn by the sender',
	)
	const status = await bob.evaluate(
		async ({ app, id }) => {
			const token =
				(window as any).OC?.requestToken
				?? document.head.dataset.requesttoken
			return (
				await fetch(`${app}/api/v1/secrets/${id}`, {
					headers: { requesttoken: token },
				})
			).status
		},
		{ app: APP, id: copyId },
	)
	expect(status).toBe(404)
})

test('the recipient files the copy, follows a new name, and declines by deleting it', async ({
	browser,
}) => {
	const alice = await signIn(browser, A)
	const sourceId = await secretId(alice, SECRET_NAME)
	await revokeLeftovers(alice, sourceId)
	await openSecret(alice, sourceId)
	await shareWithBob(alice, await revealedValue(alice))

	const bob = await signIn(browser, B)
	const { row, copyId } = await acceptOnB(bob, SECRET_NAME)

	// Filing (task 3.5): the sidebar offers Move, and a move is stored,
	// while any other change stays refused.
	await bob.getByTestId('secret-detail-more').click()
	await expect(bob.getByTestId('secret-detail-move')).toBeVisible()
	await bob.keyboard.press('Escape')
	const folder = await call(bob, 'POST', '/api/v1/folders', {
		name: `From partners ${Date.now()}`,
	})
	expect(folder.status).toBe(201)
	const moved = await call(bob, 'PUT', `/api/v1/secrets/${copyId}`, {
		folderId: folder.data.id,
	})
	expect(moved.status).toBe(200)
	expect(moved.data.folderId).toBe(folder.data.id)
	const renamedByBob = await call(bob, 'PUT', `/api/v1/secrets/${copyId}`, {
		folderId: folder.data.id,
		name: 'Mine now',
	})
	// Refused. The OCS layer of this route still turns a 403 into 200 with
	// the status in the envelope (the 428 change of 4 Oct is another lane's),
	// so the evidence is the refusal message and the unchanged name.
	expect(
		renamedByBob.status === 403
			|| renamedByBob.data?.ocs?.meta?.statuscode === 403,
	).toBe(true)
	expect((await call(bob, 'GET', `/api/v1/secrets/${copyId}`)).data?.name).toBe(
		SECRET_NAME,
	)

	// A new name (task 4.5): Alice renames only; Bob's copy follows and
	// stays in his folder.
	const newName = `${SECRET_NAME} ${Date.now()}`
	expect(
		(await call(alice, 'PUT', `/api/v1/secrets/${sourceId}`, { name: newName }))
			.status,
	).toBe(200)
	try {
		await expect
			.poll(
				async () =>
					(await call(bob, 'GET', `/api/v1/secrets/${copyId}`)).data?.name,
				{
					timeout: 30_000,
				},
			)
			.toBe(newName)
		const copy = await call(bob, 'GET', `/api/v1/secrets/${copyId}`)
		expect(copy.data.folderId).toBe(folder.data.id)
	} finally {
		await call(alice, 'PUT', `/api/v1/secrets/${sourceId}`, {
			name: SECRET_NAME,
		})
	}

	// Deleting declines (task 4.4): Bob moves the copy to the trash from the
	// sidebar; his row says declined and Alice's share shows it.
	await openSecret(bob, copyId)
	await bob.getByTestId('secret-detail-more').click()
	await bob.getByTestId('secret-detail-delete').click()
	await bob.getByTestId('secret-delete-confirm').click()
	await expect(bob.getByTestId('secret-delete-dialog')).toHaveCount(0)
	await gotoVaultRoute(bob, 'incoming')
	await expect(row.getByTestId('incoming-share-status')).toHaveText('Declined')

	await expect
		.poll(
			async () =>
				(
					await call(
						alice,
						'GET',
						`/api/v1/secrets/${sourceId}/federated-shares`,
					)
				).data?.find(
					(share: { recipientCloudId: string }) =>
						share.recipientCloudId === BOB,
				)?.status,
			{ timeout: 30_000 },
		)
		.toBe('declined')
	await openSecret(alice, sourceId)
	await alice.getByTestId('secret-detail-share').click()
	await expect(
		alice
			.locator('[data-testid^="federated-share-row-"]')
			.filter({ hasText: BOB })
			.getByTestId('federated-share-state'),
	).toHaveText('Declined: they removed their copy. Share again if they need it.')
})
