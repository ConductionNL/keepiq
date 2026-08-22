/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Folders + secret sharing.
 *
 * GOAL: create a folder, move a secret into it, and share a secret (with a user
 * or via a public link), asserting the share exists.
 *
 * HONEST STATUS (verified live 2026-06-11): all three legs are now drivable
 * through the real write-UI (implement-secrets-write-ui change):
 *
 *   - Folder create: SecretList.vue offers a "New folder" affordance that opens
 *     FolderCreateDialog (src/dialogs/), wired to folderStore.createFolder.
 *     POST /api/v1/folders persists owner_type cleanly.
 *   - Move: SecretDetail.vue offers a "Move" affordance (SecretMoveDialog) that
 *     re-parents a secret via secret.updateSecret({ folderId }).
 *   - Share: SecretDetail.vue offers a "Share" affordance (SecretShareDialog)
 *     that creates a password-protected public link via linkShare.createLinkShare
 *     (Argon2id + AES-GCM snapshot, client-side) and reveals the link + password
 *     once. The token resolves via the public two-phase endpoint.
 *
 * The vault unlocks headlessly with the dev master password (the suite's cert
 * carries the matching public key, so a UI-created secret round-trips). The
 * unlockVault() helper dispatches a native button click (the themed NcButton
 * swallows Playwright's synthetic click).
 *
 * User-to-user sharing remains DEFERRED — the implement-user-sharing backend +
 * store are unbuilt, so the share dialog surfaces it only as a disabled
 * affordance; that leg is asserted as disabled, not exercised.
 *
 * ⚠️ The file-level anchor that used to be here pointed at
 * `openspec/specs/folders/spec.md#user-organises-secrets-into-folders` (sigil
 * omitted on purpose — gate-19 parses a tag written in prose exactly like a real
 * one, so quoting a broken anchor re-creates it). It was DANGLING:
 * `openspec/specs/folders/` does not exist in this repository at all.
 * gate-19 says nothing about a dangling anchor, so these four passing UI tests
 * were credited to zero scenarios. They are anchored per-test below, against the
 * `secrets-write-ui` scenarios they actually drive.
 */
import { test, expect } from '@playwright/test'
import { gotoLockSettled, unlockVault, openVault } from './_workflow-helpers'

const REQ_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`

/** Native DOM click on the first button under `selector` matching `text`. */
async function nativeClickByText(
	page,
	selector: string,
	text: string,
): Promise<void> {
	await page.evaluate(
		({ selector, text }) => {
			const b = (
				Array.from(
					document.querySelectorAll(selector),
				) as HTMLButtonElement[]
			).find((x) => new RegExp(text, 'i').test(x.textContent || ''))
			if (b) {
				b.click()
			}
		},
		{ selector, text },
	)
}

/**
 * Native DOM click on the LAST button matching `text` — used for a dialog's
 * primary action when the same label also appears on the page behind the modal
 * (e.g. the "Move" affordance on the detail view + the dialog's "Move" submit).
 */
async function nativeClickLastByText(page, text: string): Promise<void> {
	await page.evaluate((text) => {
		const bs = (
			Array.from(
				document.querySelectorAll('body button'),
			) as HTMLButtonElement[]
		).filter((x) => new RegExp(text, 'i').test(x.textContent || ''))
		const b = bs[bs.length - 1]
		if (b) {
			b.click()
		}
	}, text)
}

/** Open a non-blocked seeded secret's detail view by clicking its list row. */
async function openFirstSecret(page): Promise<string> {
	await expect(page.locator('.secret-list-item').first()).toBeVisible({
		timeout: 15_000,
	})
	const name = await page.evaluate(() => {
		const rows = Array.from(
			document.querySelectorAll('.secret-list-item'),
		) as HTMLElement[]
		// Skip blocked secrets (their detail renders a "locked" empty state, not a card).
		const row =
			rows.find((r) => !r.classList.contains('secret-list-item--blocked'))
			|| rows[0]
		const n = row
			? (
					row.querySelector('.secret-list-item__name')?.textContent || ''
				).trim()
			: ''
		if (row) {
			const main = row.querySelector(
				'.secret-list-item__main',
			) as HTMLElement | null
			;(main || row).click()
		}
		return n
	})
	await expect(page.locator('.secret-detail__card')).toBeVisible({
		timeout: 20_000,
	})
	return name
}

test.describe('Workflow: folders + sharing — folders/spec.md', () => {
	test('the folder list API returns a well-formed (empty) tree', async ({
		page,
	}) => {
		await gotoLockSettled(page)
		const folders = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
				credentials: 'include',
				headers: { requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		}, REQ_TOKEN)
		expect(folders.status).toBe(200)
		expect(Array.isArray(folders.body)).toBe(true)
	})

	/*
	 * Folder create via the "New folder" dialog. The folder appears in the tree
	 * and is persisted (owner_type written, no NOT-NULL violation).
	 */
	test('create a folder via the UI dialog (appears in the tree, persists)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-folder
		const FOLDER = `__e2e_folder_${Date.now()}`
		await unlockVault(page)
		await openVault(page)

		await nativeClickByText(
			page,
			'.secret-list-view__sidebar button',
			'New folder',
		)
		await expect(page.locator('.folder-form')).toBeVisible({ timeout: 10_000 })
		await page
			.locator('.folder-form input[type="text"]')
			.first()
			.fill(FOLDER, { force: true })
		await page.waitForTimeout(300)
		await nativeClickByText(page, 'body button', 'Create folder')
		await expect(page.locator('.folder-form')).toHaveCount(0, {
			timeout: 15_000,
		})

		const folder = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
					credentials: 'include',
					headers: { requesttoken: token },
				})
				const body = await res.json()
				return (
					(Array.isArray(body) ? body : []).find((f) => f.name === name)
					|| null
				)
			},
			{ tokExpr: REQ_TOKEN, name: FOLDER },
		)
		expect(folder, 'folder must be persisted').toBeTruthy()

		await expect(
			page.locator('.secret-list-view__sidebar', { hasText: FOLDER }),
		).toBeVisible({ timeout: 10_000 })

		await page.evaluate(
			async ({ tokExpr, id }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				await fetch(`/index.php/apps/keepiq/api/v1/folders/${id}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { requesttoken: token },
				})
			},
			{ tokExpr: REQ_TOKEN, id: folder.id },
		)
	})

	/*
	 * Move a secret into a folder via the "Move" dialog and assert its folderId
	 * persisted. Creates a throwaway folder, moves the first seeded secret into
	 * it, asserts, then restores the secret to the vault root and deletes the
	 * folder.
	 */
	test('move a secret into a folder via the UI (folderId persisted)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::move-a-secret-into-a-folder
		const FOLDER = `__e2e_movefolder_${Date.now()}`
		await unlockVault(page)
		await openVault(page)

		const folder = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch('/index.php/apps/keepiq/api/v1/folders', {
					method: 'POST',
					credentials: 'include',
					headers: {
						requesttoken: token,
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({ name }),
				})
				return res.json()
			},
			{ tokExpr: REQ_TOKEN, name: FOLDER },
		)
		expect(folder.id).toBeTruthy()

		// Reload so the new folder is in the move dialog's options.
		// ADR-074 rule 4: `networkidle` cannot settle on Nextcloud. The reload
		// drops the in-memory key, so `unlockVault()` below has to wait for the
		// lock screen anyway — that is the readiness signal.
		await page.reload({ waitUntil: 'domcontentloaded' })
		await unlockVault(page)
		await openVault(page)

		const secretName = await openFirstSecret(page)
		expect(secretName).toBeTruthy()

		await nativeClickByText(page, '.secret-detail__actions button', 'Move')
		await expect(page.locator('.move-form')).toBeVisible({ timeout: 10_000 })
		await page.locator('.move-form .vs__dropdown-toggle').click()
		await page
			.locator('.vs__dropdown-menu li', { hasText: FOLDER })
			.first()
			.click()
		await page.waitForTimeout(300)
		// The detail view's "Move" affordance is still in the DOM behind the modal;
		// click the LAST "Move" button (the dialog's submit action).
		await nativeClickLastByText(page, 'Move')
		await expect(page.locator('.move-form')).toHaveCount(0, { timeout: 15_000 })

		const moved = await page.evaluate(
			async ({ tokExpr, name }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const res = await fetch(
					'/index.php/apps/keepiq/api/v1/secrets?limit=200',
					{
						credentials: 'include',
						headers: { requesttoken: token },
					},
				)
				const body = await res.json()
				return (body.items || []).find((s) => s.name === name) || null
			},
			{ tokExpr: REQ_TOKEN, name: secretName },
		)
		expect(moved, 'moved secret must exist').toBeTruthy()
		expect(moved.folderId).toBe(folder.id)

		// The scenario's SECOND clause: "the secret MUST appear under that
		// folder's filter in the vault list". A persisted `folderId` is the
		// write; this is the read, and they are not the same claim — the list
		// could filter on something else entirely and the row would vanish.
		// Navigate in place (a reload would drop the in-memory key).
		await page.evaluate((id) => {
			window.location.hash = `#/folders/${id}`
		}, folder.id)
		await page.waitForLoadState('domcontentloaded')
		await expect(
			page.locator('.secret-list-item', { hasText: secretName }),
			`"${secretName}" is not listed under the folder it was moved into`,
		).toBeVisible({ timeout: 20_000 })

		await page.evaluate(
			async ({ tokExpr, secretApiId, folderId }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				await fetch(
					`/index.php/apps/keepiq/api/v1/secrets/${secretApiId}`,
					{
						method: 'PUT',
						credentials: 'include',
						headers: {
							requesttoken: token,
							'Content-Type': 'application/json',
						},
						body: JSON.stringify({ folderId: null }),
					},
				)
				await fetch(`/index.php/apps/keepiq/api/v1/folders/${folderId}`, {
					method: 'DELETE',
					credentials: 'include',
					headers: { requesttoken: token },
				})
			},
			{ tokExpr: REQ_TOKEN, secretApiId: moved.id, folderId: folder.id },
		)
	})

	/*
	 * Share a secret via a public link through the "Share" dialog: the browser
	 * decrypts the secret, generates a one-time password, Argon2id+AES encrypts
	 * the snapshot, and POSTs only the blob. Assert the link + password are shown
	 * once and the token resolves via the public two-phase endpoint.
	 */
	test('share a secret via a public link (link shown once, token resolves)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::create-a-public-link-share
		await unlockVault(page)
		await openVault(page)
		await openFirstSecret(page)

		await nativeClickByText(page, '.secret-detail__actions button', 'Share')
		await expect(page.locator('.share-dialog')).toBeVisible({ timeout: 10_000 })
		await nativeClickByText(page, 'body button', 'Create link')

		// The one-time reveal shows the link URL and password.
		await expect(page.locator('.share-dialog__reveal')).toBeVisible({
			timeout: 15_000,
		})
		const revealed = page.locator('.share-dialog__reveal .share-dialog__value')
		const linkUrl = (await revealed.first().textContent())?.trim() || ''
		expect(linkUrl).toMatch(/\/share\/link\/|link-shares|token=/i)
		// The scenario says the dialog MUST show the link URL *and* the one-time
		// password, "each with a copy control". Two revealed values and two copy
		// controls — asserting only the URL would pass on a dialog that never
		// showed the password the recipient needs.
		await expect(revealed).toHaveCount(2)
		const generatedPassword = (await revealed.nth(1).textContent())?.trim() || ''
		expect(
			generatedPassword.length,
			'no one-time link password was revealed',
		).toBeGreaterThan(7)
		await expect(
			page.locator(
				'.share-dialog__reveal button, .share-dialog__reveal .copy-button',
			),
		).toHaveCount(2)

		// The created share's token must resolve via the PUBLIC two-phase endpoint.
		const tokenResolves = await page.evaluate(
			async ({ tokExpr }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const secrets = await (
					await fetch('/index.php/apps/keepiq/api/v1/secrets?limit=200', {
						credentials: 'include',
						headers: { requesttoken: token },
					})
				).json()
				for (const s of secrets.items || []) {
					const shares = await (
						await fetch(
							`/index.php/apps/keepiq/api/v1/secrets/${s.id}/link-shares`,
							{
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					).json()
					if (Array.isArray(shares) && shares.length > 0) {
						const tok =
							shares[0].token
							|| (shares[0].linkUrl || '').split('/').pop()
						if (tok) {
							const res = await fetch(
								`/index.php/apps/keepiq/api/v1/public/link-shares/${tok}`,
								{
									credentials: 'include',
									headers: { requesttoken: token },
								},
							)
							return { found: true, status: res.status }
						}
					}
				}
				return { found: false, status: 0 }
			},
			{ tokExpr: REQ_TOKEN },
		)
		expect(tokenResolves.found, 'a link share must exist').toBe(true)
		expect([200, 401, 403]).toContain(tokenResolves.status)

		// Cleanup: revoke all link shares so re-runs stay idempotent.
		await page.evaluate(
			async ({ tokExpr }) => {
				// eslint-disable-next-line no-eval
				const token = eval(tokExpr)
				const secrets = await (
					await fetch('/index.php/apps/keepiq/api/v1/secrets?limit=200', {
						credentials: 'include',
						headers: { requesttoken: token },
					})
				).json()
				for (const s of secrets.items || []) {
					const shares = await (
						await fetch(
							`/index.php/apps/keepiq/api/v1/secrets/${s.id}/link-shares`,
							{
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					).json()
					for (const sh of Array.isArray(shares) ? shares : []) {
						await fetch(
							`/index.php/apps/keepiq/api/v1/link-shares/${sh.id}`,
							{
								method: 'DELETE',
								credentials: 'include',
								headers: { requesttoken: token },
							},
						)
					}
				}
			},
			{ tokExpr: REQ_TOKEN },
		)
	})

	/*
	 * User-to-user sharing is DEFERRED (implement-user-sharing backend unbuilt).
	 * The Share dialog surfaces it as a DISABLED affordance; assert it is present
	 * and disabled rather than exercising a flow that has no backing store.
	 */
	test('share-with-a-user affordance is present but disabled (deferred backend)', async ({
		page,
	}) => {
		// @e2e secrets-write-ui::user-to-user-sharing-is-deferred
		await unlockVault(page)
		await openVault(page)
		await openFirstSecret(page)
		await nativeClickByText(page, '.secret-detail__actions button', 'Share')
		await expect(page.locator('.share-dialog')).toBeVisible({ timeout: 10_000 })
		const userBtn = page.locator('.share-dialog__user button')
		await expect(userBtn).toBeVisible()
		await expect(userBtn).toBeDisabled()
		await expect(userBtn).toContainText(/coming soon/i)

		// "…MUST NOT issue any request until the implement-user-sharing backend
		// exists." A disabled attribute is a claim about the markup; this is the
		// claim about the wire. Record every user-share request the page makes
		// while the affordance is clicked, and require none.
		const userShareRequests: string[] = []
		const listener = (r: { url: () => string }) => {
			if (
				/\/api\/v1\/(secrets\/[^/]+\/)?(user-)?shares?(\b|\/|\?)/.test(
					r.url(),
				)
				&& !/link-shares/.test(r.url())
			) {
				userShareRequests.push(r.url())
			}
		}
		page.on('request', listener)
		// A disabled button swallows a normal click, so dispatch a native one —
		// the same trick the themed-NcButton helpers use. If the handler is
		// wired up anyway, this is what would catch it.
		await page.evaluate(() => {
			const b = document.querySelector(
				'.share-dialog__user button',
			) as HTMLButtonElement | null
			b?.click()
		})
		await page.waitForTimeout(2_000)
		page.off('request', listener)
		expect(
			userShareRequests,
			'the deferred user-share affordance issued a request',
		).toEqual([])
	})
})
