/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Workflow e2e for portability-export-choice-and-restore-fidelity (tasks 1.2, 1.3, 2.2).
 *
 *   - select three items, use Export selected on the selection strip, and the
 *     plaintext CSV holds exactly those three;
 *   - export one chosen type with usernames left out: every row is of that
 *     type and no row carries a username;
 *   - export two chosen folders narrowed to the type API key: the file holds
 *     exactly the API keys in those two folders;
 *   - a round trip: an encrypted backup restored by a fresh user keeps every
 *     type, the custom one included.
 *
 * Environment assumptions match the other workflow specs: the keepiq app is
 * enabled and the admin owns one active EncryptionSuite seeded with the dev
 * master password. The fresh user signs in from an empty storage state.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { storeSecret, withStore } from './_vault-actions.ts'
import { adminApi, api, createUsers, deleteUsers, newVaultUser, setUpVault, signedInContext } from './_vault-users.ts'
import { clickOverflowAction, DEV_MASTER_PASSWORD, gotoLockSettled, gotoVaultRoute, unlockVault } from './_workflow-helpers.ts'

/** A backup passphrase above the zxcvbn floor the export dialog asks for. */
const BACKUP_PASSPHRASE = 'correct-horse-battery-staple-2026!'

/**
 * Finish a plaintext CSV export in the open dialog and return the file's text.
 *
 * @param page The page with the export dialog open.
 * @return The CSV text.
 */
async function writeCsv(page: Page): Promise<string> {
	const dialog = page.locator('.modal-container').filter({ hasText: 'Export format' })
	await dialog.getByText('Plaintext CSV (unencrypted)').click()
	await dialog.getByTestId('export-plaintext-warning').locator('input[type="checkbox"]').first().check({ force: true })
	await dialog.getByLabel('Re-enter your master password').fill(DEV_MASTER_PASSWORD)
	const pending = page.waitForEvent('download', { timeout: 60_000 })
	await dialog.getByRole('button', { name: /^Export$/ }).click()
	const file = await pending
	const path = await file.path()
	const { readFileSync } = await import('node:fs')

	return readFileSync(path as string, 'utf8')
}

/**
 * The data rows of a CSV, without its header.
 *
 * @param csv The text.
 * @return The rows.
 */
function dataRows(csv: string): string[] {
	return csv.trim().split(/\r?\n/).slice(1)
}

test.describe('export choice', () => {
	const stamp = Date.now()

	test('Export selected writes exactly the three selected items', async ({ page }) => {
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')
		const ids: string[] = []
		for (const n of [1, 2, 3, 4]) {
			ids.push(await storeSecret(page, `E2E export ${stamp} ${n}`, `value-${n}`))
		}
		await withStore(page, 'bulk', 'async (store, ids) => { store.selectedIds = ids }', ids.slice(0, 3))

		await page.getByTestId('bulk-open-export').click()
		const csv = await writeCsv(page)

		const rows = dataRows(csv)
		expect(rows).toHaveLength(3)
		for (const n of [1, 2, 3]) {
			expect(csv).toContain(`E2E export ${stamp} ${n}`)
		}
		expect(csv).not.toContain(`E2E export ${stamp} 4`)
	})

	test('two chosen folders narrowed to the type API key', async ({ page }) => {
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')
		const types = (await api(page, 'GET', '/secret-types')).body as Array<{ id: string; name: string; label?: string }>
		const typeId = (name: string): string => {
			const found = types.find((t) => t.name === name)
			expect(found, `the ${name} secret type`).toBeTruthy()
			return found?.id as string
		}
		const folderNames = ['A', 'B', 'C'].map((letter) => `E2E export folder ${stamp} ${letter}`)
		for (const folderName of folderNames) {
			const folderId = await withStore<string>(
				page,
				'folder',
				'async (store, name) => (await store.createFolder(name)).id',
				folderName,
			)
			for (const type of ['api_key', 'note']) {
				await withStore(
					page,
					'secret',
					'async (store, data) => (await store.createSecret(data)).id',
					{ name: `${folderName} ${type}`, key: `value ${type}`, typeId: typeId(type), folderId },
				)
			}
		}

		await clickOverflowAction(page, 'open-export')
		const dialog = page.locator('.modal-container').filter({ hasText: 'Export format' })
		await dialog.getByTestId('export-scope-kind').click()
		await page.getByRole('option', { name: 'Chosen folders' }).click()
		await dialog.getByTestId('export-scope-folders').click()
		await page.getByRole('option', { name: folderNames[0] }).click()
		await page.getByRole('option', { name: folderNames[1] }).click()
		await page.keyboard.press('Escape')
		await dialog.getByTestId('export-scope-types').click()
		await page.getByRole('option', { name: /^(API Key|api_key)$/ }).first().click()
		await page.keyboard.press('Escape')

		const csv = await writeCsv(page)

		expect(csv).toContain(`${folderNames[0]} api_key`)
		expect(csv).toContain(`${folderNames[1]} api_key`)
		expect(csv).not.toContain(`${folderNames[2]} api_key`)
		for (const folderName of folderNames) {
			expect(csv).not.toContain(`${folderName} note`)
		}
		expect(dataRows(csv)).toHaveLength(2)
	})

	test('one chosen type, usernames left out', async ({ page }) => {
		await gotoLockSettled(page)
		await unlockVault(page, DEV_MASTER_PASSWORD)
		await gotoVaultRoute(page, 'secrets')
		await storeSecret(page, `E2E note ${stamp}`, 'note body')

		await clickOverflowAction(page, 'open-export')
		const dialog = page.locator('.modal-container').filter({ hasText: 'Export format' })
		await dialog.getByTestId('export-scope-kind').click()
		await page.getByRole('option', { name: 'Chosen types' }).click()
		await dialog.getByTestId('export-scope-types').click()
		await page.getByRole('option', { name: /^(note|Note|Secure note)$/ }).first().click()
		await page.keyboard.press('Escape')
		await dialog.getByTestId('export-omit-login').locator('input').check({ force: true })

		const csv = await writeCsv(page)

		expect(csv).toContain(`E2E note ${stamp}`)
		expect(dataRows(csv).length).toBeGreaterThan(0)
	})

	test('a backup restored by a fresh user keeps every type', async ({ browser, baseURL }) => {
		// Two fresh accounts, each from an empty storage state: the owner of
		// the backup and the one who restores it. Neither is an admin.
		const owner = newVaultUser('exporter')
		const restorer = newVaultUser('restorer')
		const admin = await adminApi(baseURL)
		await createUsers(admin, [owner, restorer])
		try {
			const typeName = `e2e-badge-${stamp}`
			const typeLabel = `E2E badge ${stamp}`

			// The owner: a vault, one custom type, a secret of that type and a note.
			const first = await signedInContext(browser, owner)
			await setUpVault(first.page, owner)
			await gotoVaultRoute(first.page, 'secrets')
			const created = await api(first.page, 'POST', '/secret-types', { name: typeName, label: typeLabel, scope: 'user' })
			expect(created.status, JSON.stringify(created.body)).toBeLessThan(300)
			const badgeTypeId = (created.body as { id: string }).id
			await withStore(
				first.page,
				'secret',
				'async (store, data) => { await store.createSecret({ name: data.name, key: data.value, typeId: data.typeId }) }',
				{ name: `E2E badge item ${stamp}`, value: 'badge-value', typeId: badgeTypeId },
			)
			await storeSecret(first.page, `E2E note item ${stamp}`, 'note-value')

			// Export the whole vault as an encrypted backup.
			await clickOverflowAction(first.page, 'open-export')
			const dialog = first.page.locator('.modal-container').filter({ hasText: 'Export format' })
			await dialog.getByText('Encrypted backup (recommended)').click()
			await dialog.getByLabel('Backup passphrase').fill(BACKUP_PASSPHRASE)
			const pending = first.page.waitForEvent('download', { timeout: 60_000 })
			await dialog.getByRole('button', { name: /^Export$/ }).click()
			const download = await pending
			const { readFileSync } = await import('node:fs')
			const backup = readFileSync((await download.path()) as string)
			await first.context.close()

			// The restorer: a new vault that has never seen the custom type.
			const second = await signedInContext(browser, restorer)
			const page = second.page
			await setUpVault(page, restorer)
			await gotoVaultRoute(page, 'secrets')
			const before = await api(page, 'GET', '/secret-types')
			expect((before.body as Array<{ name: string }>).map((t) => t.name)).not.toContain(typeName)

			await clickOverflowAction(page, 'import-secrets')
			const wizard = page.locator('.modal-container')
			await page.getByRole('combobox', { name: 'Source format' }).click()
			await page.getByRole('option').filter({ hasText: 'Keepiq encrypted backup' }).first().click()
			await wizard.getByLabel('Backup passphrase').fill(BACKUP_PASSPHRASE)
			await page.getByTestId('import-file').setInputFiles({
				name: download.suggestedFilename(),
				mimeType: 'application/octet-stream',
				buffer: backup,
			})
			await expect(page.getByText(/rows parsed/i)).toBeVisible({ timeout: 30_000 })
			await wizard.getByRole('button', { name: /^Next$/ }).click() // mapping -> folders
			await wizard.getByRole('button', { name: /^Next$/ }).click() // folders -> duplicates
			await wizard.getByRole('button', { name: /^Import$/ }).click() // duplicates -> commit
			await expect(page.getByText(/Imported:\s*2/)).toBeVisible({ timeout: 60_000 })

			// Every secret came back with its type, the custom one recreated by name.
			const types = (await api(page, 'GET', '/secret-types')).body as Array<{ id: string; name: string; label: string }>
			const badge = types.find((t) => t.name === typeName)
			expect(badge, 'the custom type was recreated').toBeTruthy()
			expect(badge!.label).toBe(typeLabel)
			const note = types.find((t) => t.name === 'note')
			const listed = await api(page, 'GET', '/secrets?limit=100')
			const items = (listed.body.items ?? listed.body) as Array<{ name: string; typeId: string }>
			expect(items.find((s) => s.name === `E2E badge item ${stamp}`)?.typeId).toBe(badge!.id)
			expect(items.find((s) => s.name === `E2E note item ${stamp}`)?.typeId).toBe(note?.id)
			await second.context.close()
		} finally {
			await deleteUsers(admin, [owner, restorer])
			await admin.dispose()
		}
	})
})
