/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * DEEP workflow — Secret CRUD + zero-knowledge encryption round-trip (core proof).
 *
 * GOAL: prove you can store a secret, retrieve its plaintext (decrypt), edit it,
 * delete it, and that the value is encrypted at rest.
 *
 * HONEST STATUS (verified live 2026-06-10): the create / edit round-trip is NOT
 * drivable, for TWO independent, reproduced reasons documented as test.fixme:
 *
 *   (A) NO create/edit UI exists. The Pinia secret store defines createSecret()
 *       and updateSecret(), but NO Vue component imports or wires them — neither
 *       SecretList.vue nor SecretDetail.vue offers a "New secret" / "Edit" form.
 *       SecretDetail can only VIEW (decrypt) and DELETE. So a UI-driven create is
 *       impossible.
 *
 *   (B) The REST create path is broken server-side. POST /api/v1/secrets returns
 *       HTTP 500 with `null value in column "owner_type" of relation
 *       "oc_doriath_secrets" violates not-null constraint`, even though
 *       SecretService::create() calls $secret->setOwnerType('user'). Root cause:
 *       the entity's default `protected string $ownerType = 'user'` already equals
 *       the value being set, so NC's magic setter never marks the field dirty and
 *       QBMapper::insert omits the column from the INSERT (→ DB NULL). The same
 *       defect breaks the folder seed (SeedDevelopmentSecrets).
 *
 *   (C) Even if (B) were fixed, the client-side encryption step throws. The store's
 *       createSecret() calls importPublicKey(session.certificate), but the suite's
 *       `certificate` field is a full X.509 CERTIFICATE while importPublicKey feeds
 *       its DER straight into crypto.subtle.importKey('spki', ...) — which expects a
 *       SubjectPublicKeyInfo, not a certificate — throwing DataError. So the
 *       browser cannot encrypt a secret value at all.
 *
 * Net effect: with no suite-owner able to write a secret, the encrypt → store →
 * fetch → decrypt → assert-match round-trip and the encryption-at-rest check
 * cannot be exercised end-to-end. The legs below assert everything that IS
 * verifiable today (route gating, list contract, type contract, encryption-at-rest
 * CONTRACT shape) and fixme the blocked legs with their precise blockers.
 *
 * @e2e openspec/specs/secrets/spec.md#user-stores-a-secret
 */
import { test, expect } from '@playwright/test'
import {
	APP_BASE,
	gotoLockSettled,
	lockHeading,
} from './_workflow-helpers'

const REQ_TOKEN = `(() => {
	const head = document.querySelector('head[data-requesttoken]');
	return (head && head.getAttribute('data-requesttoken'))
		|| (window.OC && window.OC.requestToken) || '';
})()`

test.describe('Workflow: secret CRUD + encryption — secrets/spec.md', () => {
	test('secret routes are gated behind the lock screen (no plaintext leaks while locked)', async ({ page }) => {
		// Reaching /secrets without an in-memory key must land on the lock screen,
		// never on a rendered secret list. This is the zero-knowledge boundary.
		await page.goto(`${APP_BASE}/secrets`, { waitUntil: 'networkidle' })
		await expect(lockHeading(page)).toBeVisible({ timeout: 20_000 })
		await expect(lockHeading(page)).toHaveText(/Unlock Doriath|Set up your master password/i)
		await expect(page.locator('.secret-list-view .secret-list-item')).toHaveCount(0)
	})

	test('the secrets list API returns a clean, well-formed (empty) vault', async ({ page }) => {
		await gotoLockSettled(page)
		const list = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/secrets', {
				credentials: 'include', headers: { requesttoken: token },
			})
			return { status: res.status, body: await res.json().catch(() => null) }
		}, REQ_TOKEN)
		expect(list.status).toBe(200)
		expect(list.body).toMatchObject({ items: expect.any(Array), total: expect.any(Number) })
	})

	test('the secret-type catalogue is seeded (login/api_key/note/...) for typed secrets', async ({ page }) => {
		await gotoLockSettled(page)
		const types = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/secret-types', {
				credentials: 'include', headers: { requesttoken: token },
			})
			return { status: res.status, names: (await res.json()).map((t) => t.name) }
		}, REQ_TOKEN)
		expect(types.status).toBe(200)
		expect(types.names).toEqual(expect.arrayContaining(['login', 'api_key', 'note']))
	})

	test('the suite endpoint exposes a wrapped private key + certificate (decrypt material present)', async ({ page }) => {
		await gotoLockSettled(page)
		// The read/decrypt half of the zero-knowledge model depends on the suite
		// shipping the AES-wrapped PKCS#8 private key and the X.509 certificate.
		// Assert the contract shape (the actual decrypt is blocked by bug #5 —
		// the PHP-written envelope is JS-incompatible; see vault-unlock.spec.ts).
		const suite = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/suites', {
				credentials: 'include', headers: { requesttoken: token },
			})
			const body = await res.json()
			const active = body.find((s) => s.status === 'active')
			return {
				status: res.status,
				hasPrivateKey: !!active?.privateKey,
				certIsX509: (active?.certificate || '').includes('BEGIN CERTIFICATE'),
			}
		}, REQ_TOKEN)
		expect(suite.status).toBe(200)
		expect(suite.hasPrivateKey).toBe(true)
		expect(suite.certIsX509).toBe(true)
	})

	/*
	 * BUG (B) — server 500 on create. Un-fixme once POST /api/v1/secrets persists
	 * owner_type (mark the entity field dirty even when it equals the default).
	 */
	test.fixme('create a secret via the API stores it (HTTP 200, appears in the list)', async ({ page }) => {
		await gotoLockSettled(page)
		const created = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const res = await fetch('/index.php/apps/doriath/api/v1/secrets', {
				method: 'POST', credentials: 'include',
				headers: { requesttoken: token, 'Content-Type': 'application/json' },
				body: JSON.stringify({ name: '__e2e_roundtrip', key: btoa('opaque-ciphertext') }),
			})
			return res.status
		}, REQ_TOKEN)
		expect(created).toBe(200)
	})

	/*
	 * BUG (C) — client cannot encrypt. Un-fixme once importPublicKey extracts the
	 * SubjectPublicKeyInfo from the X.509 certificate (e.g. via an ASN.1/X.509
	 * parser) instead of importing the whole cert DER as 'spki'.
	 */
	test.fixme('the browser can RSA-encrypt a value with the suite certificate', async ({ page }) => {
		await gotoLockSettled(page)
		const ok = await page.evaluate(async (tokExpr) => {
			// eslint-disable-next-line no-eval
			const token = eval(tokExpr)
			const suites = await (await fetch('/index.php/apps/doriath/api/v1/suites', {
				credentials: 'include', headers: { requesttoken: token },
			})).json()
			const suite = suites.find((s) => s.status === 'active')
			const body = suite.certificate
				.replace(/-----BEGIN CERTIFICATE-----/, '').replace(/-----END CERTIFICATE-----/, '')
				.replace(/\s/g, '')
			const der = Uint8Array.from(atob(body), (c) => c.charCodeAt(0))
			await crypto.subtle.importKey('spki', der, { name: 'RSA-OAEP', hash: 'SHA-256' }, false, ['encrypt'])
			return true
		}, REQ_TOKEN)
		expect(ok).toBe(true)
	})

	/*
	 * CORE PROOF — blocked by (A)+(B)+(C). The full zero-knowledge round-trip:
	 * create (encrypt in browser) → list → open → decrypt → assert plaintext
	 * matches → edit → assert persisted → delete → assert gone. Un-fixme once a
	 * secret can be written (needs a create UI or a working create API + client
	 * encryption).
	 */
	test.fixme('zero-knowledge round-trip: store → retrieve plaintext → edit → delete', async () => {
		// Intentionally empty — see (A)/(B)/(C) above for the blockers.
	})

	/*
	 * ENCRYPTION-AT-REST — blocked: needs at least one stored secret to inspect.
	 * Once a secret exists, assert GET /api/v1/secrets/{id} returns ciphertext in
	 * `key` (never the plaintext) AND the raw oc_doriath_secrets.key column does
	 * not contain the plaintext. Un-fixme once a secret can be written.
	 */
	test.fixme('stored secret value is encrypted at rest (raw row holds no plaintext)', async () => {
		// Intentionally empty — see block comment for the blocker.
	})
})
