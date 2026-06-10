/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Folders + secret sharing.
 *
 * GOAL: create a folder, move a secret into it, and share a secret (with a user
 * or via a public link), asserting the share exists.
 *
 * HONEST STATUS (verified live 2026-06-10): NONE of these legs are drivable yet.
 *
 *   - NO folder-create UI: FolderTree.vue only renders/selects folders. No Vue
 *     component wires folderStore.createFolder. POST /api/v1/folders also returns
 *     HTTP 500 — the same owner_type NOT-NULL violation as secret create (the
 *     entity's `protected string $ownerType = 'user'` default means
 *     setOwnerType('user') never marks the field dirty, so QBMapper::insert omits
 *     the column → DB NULL). So a folder cannot be created via UI or API.
 *
 *   - NO sharing UI: the linkShare Pinia store exists but NO component imports it;
 *     SecretDetail.vue offers only view + delete. There is no "Share" affordance.
 *
 *   - Sharing requires a secret to share, and no secret can be stored (see
 *     secret-crud-encryption.spec.ts bugs B + C). So even the link-share API
 *     cannot be exercised end-to-end.
 *
 * The one verifiable leg below asserts the folder LIST contract; the create /
 * move / share legs are fixme with their precise blockers.
 *
 * @e2e openspec/specs/folders/spec.md#user-organises-secrets-into-folders
 */
import { test, expect } from '@playwright/test'
import { gotoLockSettled } from './_workflow-helpers'

const REQ_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`

test.describe('Workflow: folders + sharing — folders/spec.md', () => {
	test('the folder list API returns a well-formed (empty) tree', async ({ page }) => {
		await gotoLockSettled(page)
		const folders = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/folders', {
				credentials: 'include', headers: { requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		}, REQ_TOKEN)
		expect(folders.status).toBe(200)
		expect(Array.isArray(folders.body)).toBe(true)
	})

	/*
	 * BUG — folder create 500s (owner_type NOT-NULL). Un-fixme once
	 * POST /api/v1/folders persists owner_type.
	 */
	test.fixme('create a folder (API returns 200 and the folder appears)', async ({ page }) => {
		await gotoLockSettled(page)
		const status = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/folders', {
				method: 'POST', credentials: 'include',
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				body: JSON.stringify({ name: '__e2e_folder' }),
			})
			return res.status
		}, REQ_TOKEN)
		expect(status).toBe(200)
	})

	/*
	 * Blocked — needs a folder AND a secret, neither creatable. Un-fixme once both
	 * create paths work; then move a secret into the folder and assert its
	 * folderId persisted.
	 */
	test.fixme('move a secret into a folder (folderId persisted)', async () => {
		// Intentionally empty — see block comment for the blocker.
	})

	/*
	 * Blocked — no sharing UI and no secret to share. Un-fixme once a secret can
	 * be created; then create a link share via POST
	 * /api/v1/secrets/{id}/link-shares and assert the share token resolves via the
	 * public two-phase endpoint GET /api/v1/public/link-shares/{token}.
	 */
	test.fixme('share a secret via a public link (share exists and token resolves)', async () => {
		// Intentionally empty — see block comment for the blocker.
	})

	/*
	 * Blocked — no sharing UI and no secret to share. Un-fixme once a secret can
	 * be created and shared with another Nextcloud user; then assert the recipient
	 * sees the shared secret.
	 */
	test.fixme('share a secret with another Nextcloud user (recipient access)', async () => {
		// Intentionally empty — see block comment for the blocker.
	})
})
