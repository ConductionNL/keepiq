/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow: the team-folder manager role
 * (sharing-team-folder-manager-role task 3.3, keepiq#790).
 *
 * The owner shares a folder holding one secret and makes Olga a manager.
 * Olga adds Bob as a viewer, and her browser runs the fan-out from her own
 * copies, because only the people who can decrypt a secret can make a copy
 * for someone else. Bob then decrypts the folder secret in his own browser.
 * Olga cannot make Bob a manager: only the owner grants that.
 *
 * Each step calls the team-folder store exactly as TeamFolderDialog does
 * (`addMember` then `runFanOut`, `setMemberGrade`), so the encryption for the
 * new member is the app's own code in the manager's browser. The dialog's
 * rendering for owners, managers and viewers is covered by
 * tests/components/TeamFolderRoles.spec.js.
 *
 * Bob's grade is read back from the server, because a refusal on an
 * OCSController route reaches a browser as an HTTP 200 OCS envelope (see the
 * #673 live checks), so the response status alone proves nothing.
 *
 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-managers-keep-the-membership-current
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { readSecretValue, withStore } from './_vault-actions.ts'
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

interface Member {
	id: string
	memberType: string
	memberId: string
	grade: string
	addedBy?: string
}

/**
 * The team folder's members, as the server holds them.
 *
 * @param page A page of a member of the folder.
 * @param teamFolderId The team folder.
 * @return The members.
 */
async function members(page: Page, teamFolderId: string): Promise<Member[]> {
	const response = await api(page, 'GET', `/team-folders/${teamFolderId}/members`)
	expect(response.status).toBe(200)
	return response.body as Member[]
}

/**
 * Add a user and run the fan-out, as TeamFolderDialog's add button does.
 *
 * @param page The page of the owner or a manager.
 * @param teamFolderId The team folder.
 * @param uid The user to add.
 */
async function addMemberAndFanOut(
	page: Page,
	teamFolderId: string,
	uid: string,
): Promise<void> {
	await withStore(
		page,
		'teamFolder',
		`async (store, data) => {
			try {
				await store.addMember(data.teamFolderId, 'user', data.uid)
				return await store.runFanOut(data.teamFolderId)
			} catch (e) {
				// Keep the server's answer: an axios error loses it on the way out.
				throw new Error(JSON.stringify({ url: e?.config?.url, status: e?.response?.status, data: e?.response?.data, body: e?.config?.data }))
			}
		}`,
		{ teamFolderId, uid },
	)
}

test.describe('team-folder manager role', () => {
	test('a manager adds a viewer who can read, and cannot make them a manager', async ({
		browser,
		baseURL,
	}) => {
		test.setTimeout(300_000)
		const admin = await adminApi(baseURL)
		const owner = newVaultUser('tom')
		const olga = newVaultUser('olga')
		const bob = newVaultUser('bob')
		await createUsers(admin, [owner, olga, bob])

		try {
			// Everyone needs a vault: a copy is encrypted to its holder's key.
			const olgaSession = await signedInContext(browser, olga)
			await setUpVault(olgaSession.page, olga)
			const bobSession = await signedInContext(browser, bob)
			await setUpVault(bobSession.page, bob)
			const tom = await signedInContext(browser, owner)
			await setUpVault(tom.page, owner)
			await gotoVaultRoute(tom.page, 'secrets')
			await expect(tom.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})

			// The owner's folder with one secret, shared as a team folder.
			const folder = await api(tom.page, 'POST', '/folders', {
				name: `DevOps ${owner.uid}`,
			})
			expect(folder.status).toBe(201)
			const types = await api(tom.page, 'GET', '/secret-types')
			const note = (types.body as Array<{ id: string; name: string }>).find(
				(t) => t.name === 'note',
			)
			const secretName = `Deploy token ${owner.uid}`
			const secretValue = `team-folder-value-${owner.uid}`
			await withStore(
				tom.page,
				'secret',
				`async (store, data) => (await store.createSecret({
					name: data.name, key: data.value, typeId: data.typeId, folderId: data.folderId,
				})).id`,
				{
					name: secretName,
					value: secretValue,
					typeId: note?.id,
					folderId: folder.body.id,
				},
			)
			const teamFolder = await withStore<{ id: string }>(
				tom.page,
				'teamFolder',
				'async (store, folderId) => store.shareFolder(folderId)',
				folder.body.id,
			)

			// The owner adds Olga and makes her a manager.
			await addMemberAndFanOut(tom.page, teamFolder.id, olga.uid)
			const olgaMember = (await members(tom.page, teamFolder.id)).find(
				(m) => m.memberId === olga.uid,
			)
			expect(olgaMember, 'Olga is a member').toBeTruthy()
			await withStore(
				tom.page,
				'teamFolder',
				"async (store, data) => store.setMemberGrade(data.teamFolderId, data.memberId, 'manage')",
				{ teamFolderId: teamFolder.id, memberId: olgaMember?.id },
			)
			expect(
				(await members(tom.page, teamFolder.id)).find(
					(m) => m.memberId === olga.uid,
				)?.grade,
			).toBe('manage')

			// Olga, as manager, adds Bob as a viewer; her browser runs the fan-out.
			await gotoVaultRoute(olgaSession.page, 'secrets')
			await expect(olgaSession.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			await addMemberAndFanOut(olgaSession.page, teamFolder.id, bob.uid)
			const bobMember = (await members(olgaSession.page, teamFolder.id)).find(
				(m) => m.memberId === bob.uid,
			)
			expect(bobMember?.grade).toBe('read')
			expect(bobMember?.addedBy).toBe(olga.uid)

			// Bob reads the folder secret in his own browser.
			await gotoVaultRoute(bobSession.page, 'secrets')
			await expect(bobSession.page.locator('.secret-list-view')).toBeVisible({
				timeout: 30_000,
			})
			const listed = await api(bobSession.page, 'GET', '/secrets?limit=100')
			const copy = (
				listed.body.items as Array<{ id: string; name: string }>
			).find((s) => s.name === secretName)
			expect(copy, 'Bob holds a copy of the folder secret').toBeTruthy()
			expect(await readSecretValue(bobSession.page, copy!.id)).toBe(
				secretValue,
			)

			// Olga cannot make Bob a manager. The server keeps him a viewer.
			const refused = await api(
				olgaSession.page,
				'PATCH',
				`/team-folders/${teamFolder.id}/members/${bobMember?.id}`,
				{ grade: 'manage' },
			)
			// The route answers 400 with the reason. The spec asks for a
			// forbidden response, but a 403 on this OCSController would reach a
			// browser as an HTTP 200 OCS envelope (POLICY.md, #673 live checks),
			// so the status is left as is and the server state is the proof.
			expect([400, 403], JSON.stringify(refused.body)).toContain(
				refused.status,
			)
			expect(refused.body?.message).toBe(
				'Only the owner can make a member a manager',
			)
			expect(
				(await members(tom.page, teamFolder.id)).find(
					(m) => m.memberId === bob.uid,
				)?.grade,
			).toBe('read')

			for (const context of [
				tom.context,
				olgaSession.context,
				bobSession.context,
			]) {
				await context.close()
			}
		} finally {
			await deleteUsers(admin, [owner, olga, bob])
			await admin.dispose()
		}
	})
})
