/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Encrypted transfer (CXP) through files (portability-cxp-cross-provider-transfer).
 *
 * The receiver downloads a request file; the sender loads it, seals its vault
 * to it and downloads the sealed response; the receiver loads that response
 * and the import wizard opens with the received item. An altered response
 * opens nothing. Both sides are fresh accounts with their own vault.
 */
import type {
	APIRequestContext,
	Browser,
	BrowserContext,
	Page,
} from '@playwright/test'
import type { VaultUser } from './_vault-users.ts'

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'
import { storeSecret } from './_vault-actions.ts'
import {
	adminApi,
	clickButton,
	createUsers,
	deleteUsers,
	newVaultUser,
	setUpVault,
	signedInContext,
} from './_vault-users.ts'
import { clickOverflowAction, gotoVaultRoute } from './_workflow-helpers.ts'

const receiver = newVaultUser('cxprecv')
const sender = newVaultUser('cxpsend')
const ITEM = 'Transferred note'

let admin: APIRequestContext
const sessions: Array<{ context: BrowserContext; page: Page }> = []

/**
 * Sign in, set up the vault and open the CXP dialog on the files transport.
 *
 * @param browser The Playwright browser.
 * @param user The account.
 * @param direction `receive` or `send`.
 * @return The page with the dialog open.
 */
async function openTransfer(
	browser: Browser,
	user: VaultUser,
	direction: 'receive' | 'send',
): Promise<Page> {
	const session = await signedInContext(browser, user)
	sessions.push(session)
	const { page } = session
	await setUpVault(page, user)
	await gotoVaultRoute(page, 'secrets')
	if (direction === 'send') {
		await storeSecret(page, ITEM, 'carried-across-2026')
		await gotoVaultRoute(page, 'password-health')
		await gotoVaultRoute(page, 'secrets')
	}
	await clickOverflowAction(page, 'cxp-transfer')
	const dialog = page.getByTestId('cxp-dialog')
	await expect(dialog).toBeVisible({ timeout: 15_000 })
	const label =
		direction === 'receive'
			? 'Receive credentials (import)'
			: 'Send credentials (export)'
	await dialog
		.getByRole('radio', { name: label })
		.evaluate((el: HTMLElement) => el.click())
	await dialog
		.getByRole('radio', { name: 'Another provider (files)' })
		.evaluate((el: HTMLElement) => el.click())
	return page
}

/**
 * Click a control natively and wait for the file it downloads.
 *
 * @param page The page.
 * @param testid The control's data-testid.
 * @return The downloaded file's text.
 */
async function download(page: Page, testid: string): Promise<string> {
	const pending = page.waitForEvent('download', { timeout: 60_000 })
	await page.getByTestId(testid).evaluate((el: HTMLElement) => el.click())
	const file = await pending
	return readFile((await file.path()) as string, 'utf8')
}

test.describe('CXP transfer through files', () => {
	test.beforeAll(async ({ baseURL }) => {
		admin = await adminApi(baseURL)
		await createUsers(admin, [receiver, sender])
	})

	test.afterAll(async () => {
		for (const session of sessions) {
			await session.context.close()
		}
		await deleteUsers(admin, [receiver, sender])
		await admin?.dispose()
	})

	test('a sealed response file opens the import wizard; an altered one opens nothing', async ({
		browser,
	}) => {
		// @e2e portability-cxp::a-user-receives-from-another-provider
		// @e2e portability-cxp::a-user-sends-to-another-provider
		// @e2e portability-cxp::a-tampered-response-is-refused
		test.setTimeout(240_000)
		const receive = await openTransfer(browser, receiver, 'receive')
		const request = await download(receive, 'cxp-download-request')
		expect(() => JSON.parse(request)).not.toThrow()

		const send = await openTransfer(browser, sender, 'send')
		const sendDialog = send.getByTestId('cxp-dialog')
		await sendDialog.getByTestId('cxp-request-file').setInputFiles({
			name: 'keepiq-cxp-request.json',
			mimeType: 'application/json',
			buffer: Buffer.from(request),
		})
		await expect(sendDialog.getByTestId('cxp-request-loaded')).toBeVisible({
			timeout: 15_000,
		})
		await sendDialog
			.getByTestId('cxp-master-password')
			.locator('input')
			.or(sendDialog.locator('input[data-testid="cxp-master-password"]'))
			.first()
			.fill(sender.masterPassword)

		const pending = send.waitForEvent('download', { timeout: 60_000 })
		await sendDialog
			.getByTestId('cxp-do-send')
			.evaluate((el: HTMLElement) => el.click())
		// A first press may only report what CXF cannot carry; the second sends.
		const confirm = sendDialog.getByText('Confirm and send sealed transfer')
		if (await confirm.isVisible({ timeout: 5_000 }).catch(() => false)) {
			await sendDialog
				.getByTestId('cxp-do-send')
				.evaluate((el: HTMLElement) => el.click())
		}
		const sealed = await readFile(
			(await (await pending).path()) as string,
			'utf8',
		)
		await expect(sendDialog.getByTestId('cxp-sent')).toBeVisible()
		expect(sealed).not.toContain(ITEM)
		expect(sealed).not.toContain('carried-across-2026')

		const receiveDialog = receive.getByTestId('cxp-dialog')
		const altered = JSON.parse(sealed)
		const field = Object.keys(altered).find(
			(k) => typeof altered[k] === 'string' && altered[k].length > 40,
		)
		expect(field, 'a sealed field to alter').toBeTruthy()
		const value: string = altered[field!]
		altered[field!] = (value[0] === 'A' ? 'B' : 'A') + value.slice(1)
		await receiveDialog.getByTestId('cxp-response-file').setInputFiles({
			name: 'altered.json',
			mimeType: 'application/json',
			buffer: Buffer.from(JSON.stringify(altered)),
		})
		await expect(receiveDialog).toContainText('could not be verified', {
			timeout: 15_000,
		})

		await receiveDialog.getByTestId('cxp-response-file').setInputFiles({
			name: 'sealed.json',
			mimeType: 'application/json',
			buffer: Buffer.from(sealed),
		})
		const wizard = receive.locator('.import-wizard__mapping')
		await expect(wizard).toBeVisible({ timeout: 30_000 })
		await expect(wizard).toContainText(ITEM)
		await clickButton(receive, '^Cancel$').catch(() => undefined)
	})
})
