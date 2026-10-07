/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * DEEP workflow: a refusal on an OCS route reaches the browser
 * (harden-vault-key-material-guards task 6.5, keepiq#673 and #698).
 *
 * Nextcloud's OCSMiddleware turns a 403 of an OCSController into an HTTP 200
 * OCS envelope without the body's `error`, so a share to someone new, which
 * the server refuses until the owner proves the master password, read as a
 * success and no share was made. Keepiq now refuses with 428 and
 * `error: key_proof_required`. This flow sends the first share to a new
 * recipient through the web app's share store without a proof, and checks
 * that the refusal arrives as 428, that the app asks for the master password,
 * and that the share is made once it is given.
 *
 * @spec openspec/specs/vault-key-proof/spec.md#scenario-the-refusal-reaches-the-browser-on-an-ocs-route
 * @spec openspec/specs/user-sharing/spec.md#requirement-sharing-with-a-new-party-requires-a-verified-key-proof
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
 * Open a secret's detail view, which brings the share store into use.
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

test.describe('a refusal on an OCS route reaches the browser', () => {
	test('a first share to someone new asks for the master password', async ({
		browser,
		baseURL,
	}) => {
		test.setTimeout(240_000)
		const admin = await adminApi(baseURL)
		const owner = newVaultUser('olaf')
		const recipient = newVaultUser('nina')
		await createUsers(admin, [owner, recipient])

		try {
			const nina = await signedInContext(browser, recipient)
			await setUpVault(nina.page, recipient)

			const olaf = await signedInContext(browser, owner)
			// The first-open support dialog would cover the master password prompt.
			await olaf.context.addInitScript(() => {
				window.localStorage.setItem('cn-support-dialog-shown:keepiq', '1')
			})
			await setUpVault(olaf.page, owner)
			await gotoVaultRoute(olaf.page, 'secrets')
			await expect(olaf.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			const types = await api(olaf.page, 'GET', '/secret-types')
			const loginType = (
				types.body as Array<{ id: string; name: string }>
			).find((t) => t.name === 'login')
			const secretId = await withStore<string>(
				olaf.page,
				'secret',
				`async (store, data) => (await store.createSecret({
					name: data.name, url: 'https://shared.example', login: 'olaf',
					key: 'Shared-secret-value-1', typeId: data.typeId,
				})).id`,
				{ name: `Shared login ${owner.uid}`, typeId: loginType?.id },
			)

			// The refusal itself: 428 with its code, not an HTTP 200 envelope.
			const refused = await api(
				olaf.page,
				'POST',
				`/secrets/${secretId}/shares`,
				{
					targetUserId: recipient.uid,
					recipientSecretId: `copy-${recipient.uid}`,
				},
			)
			expect(refused.status, JSON.stringify(refused.body)).toBe(428)
			expect(refused.body?.error).toBe('key_proof_required')
			expect(refused.body?.ocs).toBeUndefined()

			// The web app's own path: the share store sends it without a proof,
			// reads the refusal and asks for the master password.
			await openDetail(olaf.page, secretId)
			await olaf.page.evaluate(
				([id, target]) => {
					const host = document.querySelector(
						'#keepiq-app',
					) as HTMLElement & {
						__vue_app__: {
							config: {
								globalProperties: {
									$pinia: { _s: Map<string, any> }
								}
							}
						}
					}
					const store =
						host.__vue_app__.config.globalProperties.$pinia._s.get(
							'share',
						)
					;(window as any).__shareResult = store
						.createShare(id, target, `copy-${target}`)
						.then((row: { targetUserId: string }) => ({ ok: true, row }))
						.catch((e: any) => ({
							ok: false,
							status: e?.response?.status,
							data: e?.response?.data,
						}))
				},
				[secretId, recipient.uid],
			)
			const prompt = olaf.page.getByTestId('key-proof-prompt')
			await expect(prompt).toBeVisible({ timeout: 20_000 })
			await expect(prompt).toContainText('You are sharing with someone new')
			await prompt
				.getByLabel('Your master password')
				.fill(owner.masterPassword)
			await olaf.page.getByTestId('key-proof-prompt-confirm').click()
			const result = await olaf.page.evaluate(
				() => (window as any).__shareResult,
			)
			expect(result.ok, JSON.stringify(result)).toBe(true)
			expect(result.row.targetUserId).toBe(recipient.uid)

			const shares = await api(olaf.page, 'GET', `/secrets/${secretId}/shares`)
			expect(
				(shares.body as Array<{ targetUserId: string }>).map(
					(s) => s.targetUserId,
				),
			).toContain(recipient.uid)

			for (const context of [olaf.context, nina.context]) {
				await context.close()
			}
		} finally {
			await deleteUsers(admin, [owner, recipient])
		}
	})
})
