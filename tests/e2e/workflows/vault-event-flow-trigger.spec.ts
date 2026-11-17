/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A vault event starts an OpenRegister flow (clients-vault-event-flow-triggers).
 *
 * Needs OpenRegister enabled on the instance; skipped without it. An admin
 * publishes a flow on `keepiq.share.granted` and switches the sharing group
 * on; one account shares a login with another; the flow then holds exactly
 * one run, and nothing in that run carries the login's name, username or
 * value.
 */
import type { APIRequestContext, BrowserContext, Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { storeSecret, withStore } from './_vault-actions.ts'
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

const owner = newVaultUser('flowowner')
const recipient = newVaultUser('flowrecv')
const NAME = 'Flow trigger login'
const VALUE = 'flow-secret-value-2026'
const OR_API = '/index.php/apps/openregister/api'
const AUDIT = '/index.php/apps/keepiq/api/settings/admin/audit'

let admin: APIRequestContext
let flowId = ''
let groupsBefore: unknown = []
const sessions: Array<{ context: BrowserContext; page: Page }> = []

test.describe('vault event flow trigger', () => {
	test.beforeAll(async ({ baseURL }) => {
		admin = await adminApi(baseURL)
		const catalog = await admin.get(`${OR_API}/flow/event-catalog`, {
			headers: { 'OCS-APIRequest': 'true' },
		})
		test.skip(
			catalog.status() !== 200,
			'OpenRegister is not enabled on this instance',
		)
		await createUsers(admin, [owner, recipient])
	})

	test.afterAll(async () => {
		for (const session of sessions) {
			await session.context.close()
		}
		if (flowId !== '') {
			await admin.delete(`${OR_API}/flows/${flowId}`, {
				headers: { 'OCS-APIRequest': 'true' },
			})
		}
		await admin
			.put(AUDIT, {
				headers: { 'OCS-APIRequest': 'true' },
				data: { flow_trigger_groups: groupsBefore },
			})
			.catch(() => undefined)
		await deleteUsers(admin, [owner, recipient])
		await admin?.dispose()
	})

	test('a granted share starts one run with no secret material', async ({
		browser,
	}) => {
		// @e2e vault-event-flow-triggers::a-granted-share-starts-a-flow
		test.setTimeout(180_000)
		const headers = { 'OCS-APIRequest': 'true' }

		const catalog = await (
			await admin.get(`${OR_API}/flow/event-catalog`, { headers })
		).text()
		expect(catalog, 'the flow palette lists the vault trigger').toContain(
			'keepiq.share.granted',
		)

		const created = await admin.post(`${OR_API}/flows`, {
			headers,
			data: {
				name: `keepiq share trigger ${Date.now()}`,
				trigger: 'keepiq.share.granted',
				enabled: true,
				executionMode: 'sync',
				nodes: [{ id: 'start' }, { id: 'end' }],
				edges: [],
			},
		})
		expect(created.status(), await created.text()).toBe(201)
		flowId = (await created.json()).id
		const published = await admin.post(`${OR_API}/flows/${flowId}/publish`, {
			headers,
		})
		expect(published.status(), await published.text()).toBe(200)

		const before = await admin.get(AUDIT, { headers })
		groupsBefore = (await before.json())?.flow_trigger_groups ?? []
		const saved = await admin.put(AUDIT, {
			headers,
			data: { flow_trigger_groups: ['sharing'] },
		})
		expect(saved.status(), await saved.text()).toBe(200)

		for (const user of [recipient, owner]) {
			const session = await signedInContext(browser, user)
			sessions.push(session)
			await setUpVault(session.page, user)
			await gotoVaultRoute(session.page, 'secrets')
		}
		const page = sessions[1].page
		const sourceId = await storeSecret(page, NAME, VALUE)
		// The share store comes into use with the detail sidebar.
		await gotoVaultRoute(page, `secrets/${sourceId}`)
		await expect(page.getByTestId('secret-detail-sidebar')).toBeVisible({
			timeout: 30_000,
		})
		const certificate = await api(
			page,
			'GET',
			`/shares/recipient-certificate?userId=${encodeURIComponent(recipient.uid)}`,
		)
		expect(certificate.status).toBe(200)
		const item = await withStore<{ status: string }>(
			page,
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
				}], { masterPassword: data.masterPassword })
				return items[0]
			}`,
			{
				sourceId,
				certificate: certificate.body.certificate,
				targetUserId: recipient.uid,
				masterPassword: owner.masterPassword,
			},
		)
		expect(item, 'the share was registered').toBeTruthy()

		let runs: Array<Record<string, unknown>> = []
		await expect
			.poll(
				async () => {
					const listed = await admin.get(`${OR_API}/flow-runs?limit=100`, {
						headers,
					})
					const body = await listed.json()
					runs = (body.results ?? []).filter(
						(run: Record<string, unknown>) =>
							run.flowUuid === flowId
							|| run.flowId === flowId
							|| run.flow === flowId,
					)
					return runs.length
				},
				{ timeout: 60_000 },
			)
			.toBe(1)

		const uuid = String(runs[0].uuid ?? runs[0].id)
		const detail = await (
			await admin.get(`${OR_API}/flow-runs/${uuid}`, { headers })
		).text()
		expect(detail).toContain('share.granted')
		expect(detail).toContain(owner.uid)
		for (const secret of [NAME, VALUE, owner.masterPassword]) {
			expect(detail, 'the run carries secret material').not.toContain(secret)
		}
	})
})
