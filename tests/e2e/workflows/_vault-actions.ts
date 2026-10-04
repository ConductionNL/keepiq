/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Vault actions for the multi-user workflow specs, run through the web app's
 * OWN stores in the page.
 *
 * WHY THE STORES AND NOT HAND-ROLLED CRYPTO
 * -----------------------------------------
 * Storing a secret, sharing it and reading it back all encrypt or decrypt in
 * the browser. Re-implementing that in a spec (as secret-crud-encryption
 * does for one value) would test the spec's copy of the crypto, not the app's.
 * The Pinia stores the app itself calls are reachable from the mounted Vue
 * app, so these helpers call `createSecret` and `fetchSecret` (which
 * decrypts) exactly as the components do, with the page's unlocked
 * session key. The interface steps each flow is about (dialogs, the lock
 * screen, the recipient's detail view) stay in the specs as clicks.
 */
import type { Page } from '@playwright/test'

import { expect } from '@playwright/test'
import { api } from './_vault-users.ts'

/**
 * Run a function against one of the app's Pinia stores in the page.
 *
 * The store must already be in use by the page (every store these helpers
 * name is created when the vault list renders).
 *
 * @param page An unlocked page of the app.
 * @param storeId The Pinia store id, such as `secret`.
 * @param fn The body, as a string: an async arrow `(store, arg) => ...`.
 * @param arg A JSON-serialisable argument.
 * @return What the body returned.
 */
export async function withStore<T>(
	page: Page,
	storeId: string,
	fn: string,
	arg: unknown = null,
): Promise<T> {
	return page.evaluate(
		async ([id, body, value]) => {
			const host = document.querySelector('#keepiq-app') as
				| (HTMLElement & {
						__vue_app__?: {
							config: {
								globalProperties: {
									$pinia?: { _s: Map<string, unknown> }
								}
							}
						}
				  })
				| null
			const pinia = host?.__vue_app__?.config.globalProperties.$pinia
			const store = pinia?._s.get(id as string)
			if (!store) {
				throw new Error(`store ${id} is not in use on this page`)
			}

			const run = new Function(`return (${body})`)()
			return run(store, value)
		},
		[storeId, fn, arg],
	) as Promise<T>
}

/**
 * Store a note secret whose value the browser encrypts with the user's key.
 *
 * @param page An unlocked page of the app, on a vault route.
 * @param name The secret's name.
 * @param value The plaintext value.
 * @return The new secret's id.
 */
export async function storeSecret(
	page: Page,
	name: string,
	value: string,
): Promise<string> {
	const types = await api(page, 'GET', '/secret-types')
	const note = (types.body as Array<{ id: string; name: string }>).find(
		(t) => t.name === 'note',
	)
	expect(note, 'the note secret type').toBeTruthy()
	const id = await withStore<string>(
		page,
		'secret',
		`async (store, data) => {
			const created = await store.createSecret({ name: data.name, key: data.value, typeId: data.typeId })
			return created.id
		}`,
		{ name, value, typeId: note?.id },
	)
	expect(id, `stored ${name}`).toBeTruthy()
	return id
}

/**
 * Read a secret's value back through the app's own decrypt.
 *
 * @param page An unlocked page of the app.
 * @param id The secret's id.
 * @return The decrypted value.
 */
export async function readSecretValue(page: Page, id: string): Promise<string> {
	return withStore<string>(
		page,
		'secret',
		`async (store, secretId) => {
			// fetchSecret decrypts with the session key, as the detail view does.
			const plain = await store.fetchSecret(secretId)
			return plain.key
		}`,
		id,
	)
}

/**
 * Open the user settings dialog of the app.
 *
 * @param page A page of the app.
 */
export async function openUserSettings(page: Page): Promise<void> {
	await page
		.getByTestId('cn-nav-entry-UserSettings')
		.locator('a, button')
		.first()
		.evaluate((el: HTMLElement) => el.click())
	await expect(
		page.locator('[data-testid="recovery-enrolment"], #security').first(),
	).toBeAttached({ timeout: 20_000 })
}
