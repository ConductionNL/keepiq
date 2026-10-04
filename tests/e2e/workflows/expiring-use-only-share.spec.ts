/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow: a use-only share with an end date
 * (sharing-use-only-and-expiring-shares task 6.1, keepiq#791).
 *
 * The owner shares a login use-only, ending tomorrow. The recipient finds the
 * copy in the web app and the detail view offers no reveal and no copy, while
 * the owner's own detail view does (the control that proves the selectors
 * can match). Then the end date passes, and the copy is gone from the
 * recipient's list, list API and detail read.
 *
 * HOW THE END DATE "PASSES"
 * -------------------------
 * The server decides with its own clock (SecretMapper::excludeAccessExpired),
 * and a browser test cannot move the server's clock; `page.clock` would only
 * fool the web app. So the test moves the END instead: the owner shortens the
 * share's end to a few seconds ahead through `PATCH /api/v1/shares/{id}`
 * (the owner's own edit route) and waits it out.
 *
 * The share is made through the web app's share store (`encryptForRecipient`
 * and `registerBatch`, the calls BulkShareDialog makes), so the recipient's
 * copy is encrypted by the app's own code; the dialog's fields are covered by
 * tests/components/ShareRestrictionFields.spec.js.
 *
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 * @spec openspec/specs/expiring-shares/spec.md#requirement-the-server-stops-serving-an-expired-copy-at-its-end-date
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
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

/**
 * The ids of the secrets the page's user can list.
 *
 * @param page A page of the app.
 * @return The ids.
 */
async function listedIds(page: Page): Promise<string[]> {
	const listed = await api(page, 'GET', '/secrets?limit=100')
	expect(listed.status).toBe(200)
	return (listed.body.items as Array<{ id: string }>).map((s) => s.id)
}

/**
 * Open a secret's detail view over the list.
 *
 * @param page An unlocked page of the app.
 * @param id The secret's id.
 */
async function openDetail(page: Page, id: string): Promise<void> {
	await gotoVaultRoute(page, `secrets/${id}`)
	await expect(page.locator('.secret-detail__card')).toBeVisible({
		timeout: 30_000,
	})
}

test.describe('use-only share with an end date', () => {
	test('the recipient cannot reveal or copy it, and it is gone after its end', async ({
		browser,
		baseURL,
	}) => {
		test.setTimeout(300_000)
		const admin = await adminApi(baseURL)
		const owner = newVaultUser('oscar')
		const recipient = newVaultUser('rita')
		await createUsers(admin, [owner, recipient])

		try {
			const rita = await signedInContext(browser, recipient)
			await setUpVault(rita.page, recipient)

			const oscar = await signedInContext(browser, owner)
			await setUpVault(oscar.page, owner)
			await gotoVaultRoute(oscar.page, 'secrets')
			await expect(oscar.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})

			// The owner's login, encrypted by the app.
			const types = await api(oscar.page, 'GET', '/secret-types')
			const loginType = (
				types.body as Array<{ id: string; name: string }>
			).find((t) => t.name === 'login')
			const name = `Supplier portal ${owner.uid}`
			const sourceId = await withStore<string>(
				oscar.page,
				'secret',
				`async (store, data) => (await store.createSecret({
					name: data.name, url: 'https://portal.supplier.example', login: 'rita@supplier.example',
					key: 'Use-only-secret-value-1', typeId: data.typeId,
				})).id`,
				{ name, typeId: loginType?.id },
			)

			// Control: the owner's own detail view offers reveal and copy. Opening
			// it also brings the share store into use on the page.
			await openDetail(oscar.page, sourceId)
			await expect(
				oscar.page
					.locator('.secret-detail button[aria-label="Show"]')
					.first(),
			).toBeVisible()
			await expect(
				oscar.page
					.locator('.secret-detail button[aria-label="Copy password"]')
					.first(),
			).toBeVisible()

			// Share it use-only, ending tomorrow, the way BulkShareDialog does.
			const tomorrow = new Date(Date.now() + 24 * 3600 * 1000).toISOString()
			const certificate = await api(
				oscar.page,
				'GET',
				`/shares/recipient-certificate?userId=${encodeURIComponent(recipient.uid)}`,
			)
			expect(certificate.status).toBe(200)
			const item = await withStore<{
				status: string
				recipientSecretId: string
			}>(
				oscar.page,
				'share',
				`async (store, data) => {
					const secrets = document.querySelector('#keepiq-app').__vue_app__.config.globalProperties.$pinia._s.get('secret')
					const plain = await secrets.fetchSecret(data.sourceId)
					const blob = await store.encryptForRecipient(
						{ key: plain.key ?? '', login: plain.login ?? '', additionalFields: '' },
						data.certificate,
					)
					const { items } = await store.registerBatch([{
						sourceSecretId: data.sourceId,
						targetUserId: data.targetUserId,
						encryptedKey: blob.key ?? '',
						encryptedLogin: blob.login ?? null,
						encryptedAdditionalFields: null,
						useOnly: true,
						expiresAt: data.expiresAt,
					}], { masterPassword: data.masterPassword })
					return items[0]
				}`,
				{
					sourceId,
					certificate: certificate.body.certificate,
					targetUserId: recipient.uid,
					expiresAt: tomorrow,
					masterPassword: owner.masterPassword,
				},
			)
			expect(item.status).toBe('created')
			const copyId = item.recipientSecretId

			// The recipient sees the copy, marked use-only, with no reveal and no copy.
			await gotoVaultRoute(rita.page, 'secrets')
			await expect(rita.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			await expect(rita.page.getByText(name).first()).toBeVisible({
				timeout: 30_000,
			})
			await openDetail(rita.page, copyId)
			await expect(
				rita.page.getByTestId('secret-detail-use-only'),
			).toBeVisible()
			await expect(
				rita.page.locator('.secret-detail button[aria-label="Show"]'),
			).toHaveCount(0)
			// The login stays plain metadata (D3), so "Copy login" may remain.
			await expect(
				rita.page.locator(
					'.secret-detail button[aria-label="Copy password"]',
				),
			).toHaveCount(0)
			await expect(
				rita.page
					.locator('.secret-detail')
					.getByText('rita@supplier.example')
					.first(),
			).toBeVisible()
			await expect(rita.page.locator('.secret-detail')).not.toContainText(
				'Use-only-secret-value-1',
			)
			expect(await listedIds(rita.page)).toContain(copyId)

			// The end date passes: the owner moves it to a few seconds from now.
			const shares = await api(
				oscar.page,
				'GET',
				`/secrets/${sourceId}/shares`,
			)
			const share = (
				shares.body as Array<{ id: string; targetUserId: string }>
			).find((s) => s.targetUserId === recipient.uid)
			expect(share, 'the owner lists the share').toBeTruthy()
			const end = new Date(Date.now() + 5_000)
			const patched = await api(oscar.page, 'PATCH', `/shares/${share?.id}`, {
				useOnly: true,
				expiresAt: end.toISOString(),
			})
			expect(patched.status, JSON.stringify(patched.body)).toBe(200)
			await rita.page.waitForTimeout(
				Math.max(0, end.getTime() - Date.now()) + 2_000,
			)

			// Gone from the recipient's list (API and web app) and detail read.
			expect(await listedIds(rita.page)).not.toContain(copyId)
			expect((await api(rita.page, 'GET', `/secrets/${copyId}`)).status).toBe(
				404,
			)
			await gotoVaultRoute(rita.page, 'trash')
			await gotoVaultRoute(rita.page, 'secrets')
			await expect(rita.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			await expect(
				rita.page.locator('.secret-list-item', { hasText: name }),
			).toHaveCount(0, { timeout: 20_000 })

			await oscar.context.close()
			await rita.context.close()
		} finally {
			await deleteUsers(admin, [owner, recipient])
			await admin.dispose()
		}
	})
})
