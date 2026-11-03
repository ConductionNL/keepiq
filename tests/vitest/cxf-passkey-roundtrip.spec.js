/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Passkeys and one-time codes survive a CXF export and re-import
 * (keepiq#113, keepiq#1327).
 *
 * Drives the real chain a vault export takes: secrets shaped as the web app
 * decrypts them (a type UUID, the value in `key`, a login's own `totp` and
 * `passkeys`) through serializeVault(), buildCxfDocument() with the type-name
 * map, JSON text, and the REGISTERED `cxf` import parser the wizard and the
 * CXP receive path call.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-client-side-cxf-import
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-import-and-export-keep-a-logins-code-and-passkeys-on-it
 */

import { describe, expect, it } from 'vitest'
import { buildCxfDocument } from '../../src/cxf/cxf.js'
import { serializeVault } from '../../src/export/serializer.js'
import { getParser } from '../../src/import/parserRegistry.js'
import { passkeyEntries } from '../../src/passkey/loginPasskeys.js'
import { buildPasskeyCredential } from '../../src/passkey/passkey.js'
import { parseOtpauth } from '../../src/totp/totp.js'

import '../../src/import/parsers/index.js'

const TYPE_NAMES = {
	'uuid-of-login-type': 'login',
	'uuid-of-passkey-type': 'passkey',
}

/**
 * Export decrypted secrets to CXF text and import that text again.
 *
 * @param {Array<object>} served The decrypted secrets.
 * @return {Promise<{rows: Array<object>, itemCount: number}>}
 */
async function roundTrip(served) {
	const payload = serializeVault(served, [])
	const { document, itemCount } = buildCxfDocument(payload.secrets, {
		typeNamesById: TYPE_NAMES,
	})
	const rows = await getParser('cxf').parse(JSON.stringify(document))
	return { rows, itemCount }
}

/**
 * A canonical passkey credential for example.com.
 *
 * @param {string} id The credential id.
 * @return {object} The credential.
 */
function credential(id) {
	return buildPasskeyCredential({
		credentialId: id,
		rpId: 'example.com',
		rpName: 'Example',
		userName: 'alice',
		userHandle: 'HANDLE',
		privateKey: `PRIVATE_KEY_${id}`,
	})
}

describe('CXF round trip of passkeys and codes', () => {
	it('re-imports a standalone passkey item as a login carrying the passkey', async () => {
		const { rows, itemCount } = await roundTrip([
			{
				id: 's1',
				name: 'Example',
				url: 'example.com',
				typeId: 'uuid-of-passkey-type',
				key: JSON.stringify(credential('CRED_ID')),
				login: null,
				additionalFields: null,
				totp: '',
				passkeys: '',
				folderId: null,
			},
		])
		expect(itemCount).toBe(1)
		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('login')
		expect(rows[0].errors).toEqual([])
		const [{ credential: stored }] = passkeyEntries(rows[0].passkeys)
		const imported = buildPasskeyCredential(stored)
		expect(imported.credentialId).toBe('CRED_ID')
		expect(imported.rpId).toBe('example.com')
		expect(imported.userHandle).toBe('HANDLE')
		expect(imported.privateKey).toBe('PRIVATE_KEY_CRED_ID')
	})

	it('re-imports a login with a code and passkeys as that one login', async () => {
		const totp = 'otpauth://totp/Example:alice?secret=JBSWY3DPEHPK3PXP'
		const { rows, itemCount } = await roundTrip([
			{
				id: 's1',
				name: 'Example',
				url: 'https://example.com',
				typeId: 'uuid-of-login-type',
				key: 'pw-placeholder',
				login: 'alice',
				additionalFields: null,
				totp,
				passkeys: JSON.stringify([credential('A'), credential('B')]),
				folderId: null,
			},
		])
		expect(itemCount).toBe(1)
		expect(rows).toHaveLength(1)
		const [row] = rows
		expect(row.errors).toEqual([])
		expect(row.type).toBe('login')
		expect(row.password).toBe('pw-placeholder')
		expect(row.login).toBe('alice')
		// A labelled seed at the default parameters keeps its label.
		expect(parseOtpauth(row.totp)).toEqual(parseOtpauth(totp))
		expect(parseOtpauth(row.totp)).toMatchObject({
			issuer: 'Example',
			account: 'alice',
		})
		expect(
			passkeyEntries(row.passkeys).map(({ credential: c }) => c.privateKey),
		).toEqual(['PRIVATE_KEY_A', 'PRIVATE_KEY_B'])
	})

	it('re-imports a login with neither as a plain login', async () => {
		const { rows } = await roundTrip([
			{
				id: 's1',
				name: 'Plain',
				url: null,
				typeId: 'uuid-of-login-type',
				key: 'pw',
				login: null,
				additionalFields: null,
				totp: '',
				passkeys: '',
				folderId: null,
			},
		])
		expect(rows).toHaveLength(1)
		expect('totp' in rows[0]).toBe(false)
		expect('passkeys' in rows[0]).toBe(false)
	})
})
