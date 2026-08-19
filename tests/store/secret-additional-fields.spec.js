/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the secret store carries additional fields to the server.
 *
 * This is the seam where the zero-knowledge property either holds or does not.
 * Member NAMES and values both live inside the single encrypted blob (ADR-003), so
 * a leak here is not a cosmetic bug: it would put "client-secret" and its value in
 * the request body in plaintext, on a server that must never be able to read it.
 *
 * Also pins the empty-blob rule. `{}` and null look interchangeable in JavaScript
 * and are not: `{}` says "this secret has no additional fields", null says "nothing
 * was sent", which the update path reads as "leave the stored blob alone". Removing
 * the last member has to mean the former.
 *
 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-edit-a-secret-from-the-ui
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/crypto/index.js', () => ({
	importPublicKey: vi.fn(async () => 'PUBKEY_HANDLE'),
	rsaEncrypt: vi.fn(async (value) => `ENC(${value})`),
	rsaDecrypt: vi.fn(async (value) => String(value).replace(/^ENC\((.*)\)$/, '$1')),
}))

import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSessionStore } from '../../src/store/modules/session.js'

describe('secret store — additional fields', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		useSessionStore().certificate = 'PEM'
	})

	it('encrypts the members as ONE blob on create', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 's1' } })

		await useSecretStore().createSecret({
			name: 'Supplier API',
			key: 'topsecret',
			additionalFields: { 'client-id': 'abc', tenant: 'acme' },
		})

		const body = post.mock.calls[0][1]
		expect(body.additionalFields).toBe('ENC({"client-id":"abc","tenant":"acme"})')
	})

	it('puts the members ONLY inside the blob, nowhere else in the body', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 's1' } })

		await useSecretStore().createSecret({
			name: 'Supplier API',
			key: 'topsecret',
			additionalFields: { 'client-secret': 'shhh' },
		})

		// Note what this can and cannot prove. `rsaEncrypt` is mocked here as
		// ENC(<input>), so the "ciphertext" contains its own plaintext by
		// construction — asserting the body holds no 'shhh' anywhere would be
		// asserting a property of the mock, and it fails for that reason alone.
		//
		// What IS provable at this seam: the members reach the server in the
		// additionalFields field and are NOT duplicated into any other field, so
		// nothing carries a member name or value outside the blob. That the blob is
		// genuinely unreadable is proved with real keys in
		// tests/vitest/additional-fields-crypto.spec.js.
		const body = { ...post.mock.calls[0][1] }
		delete body.additionalFields
		const withoutBlob = JSON.stringify(body)

		expect(withoutBlob).not.toContain('client-secret')
		expect(withoutBlob).not.toContain('shhh')
	})

	it('rewrites the whole blob when one member changes', async () => {
		// The blob IS the storage unit, so there is no per-member update: an edit
		// sends every member the dialog held.
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 's1' } })

		await useSecretStore().updateSecret('s1', {
			additionalFields: { 'client-id': 'CHANGED', tenant: 'acme' },
		})

		expect(put.mock.calls[0][1].additionalFields).toBe(
			'ENC({"client-id":"CHANGED","tenant":"acme"})',
		)
	})

	it('sends an EMPTY blob, not null, when the last member is removed', async () => {
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 's1' } })

		await useSecretStore().updateSecret('s1', { additionalFields: {} })

		const body = put.mock.calls[0][1]
		expect(body.additionalFields).toBe('ENC({})')
		expect(body.additionalFields).not.toBeNull()
	})

	it('leaves the stored blob alone when the caller sends nothing', async () => {
		// The distinction that makes the empty-blob rule meaningful: omitting the
		// field must NOT be read as "remove them all".
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { id: 's1' } })

		await useSecretStore().updateSecret('s1', { name: 'Renamed only' })

		expect('additionalFields' in put.mock.calls[0][1]).toBe(false)
	})

	it('refuses to encrypt anything while the vault is locked', async () => {
		useSessionStore().certificate = null
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		await expect(
			useSecretStore().createSecret({
				name: 'x',
				key: 'y',
				additionalFields: { a: '1' },
			}),
		).rejects.toThrow(/locked/i)
		expect(post).not.toHaveBeenCalled()
	})
})
