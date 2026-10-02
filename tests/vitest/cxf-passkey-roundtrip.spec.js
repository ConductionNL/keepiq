/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A passkey survives a CXF export and re-import (keepiq#113).
 *
 * Drives the real chain a vault export takes: a secret shaped as the server
 * serves it (a type UUID, the passkey JSON in `key`) through serializeVault(),
 * buildCxfDocument() with the type-name map, JSON text, and the REGISTERED
 * `cxf` import parser the wizard and the CXP receive path call.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-client-side-cxf-import
 */

import { describe, expect, it } from 'vitest'
import { buildCxfDocument } from '../../src/cxf/cxf.js'
import { serializeVault } from '../../src/export/serializer.js'
import { getParser } from '../../src/import/parserRegistry.js'
import { buildPasskeyCredential, parsePasskey } from '../../src/passkey/passkey.js'

import '../../src/import/parsers/index.js'

describe('CXF passkey round trip (keepiq#113)', () => {
	it('re-imports an exported passkey as one accepted passkey row', async () => {
		const key = JSON.stringify(
			buildPasskeyCredential({
				credentialId: 'CRED_ID',
				rpId: 'example.com',
				rpName: 'Example',
				userName: 'alice',
				userHandle: 'HANDLE',
				privateKey: 'PRIVATE_KEY_PLACEHOLDER',
			}),
		)
		const served = [
			{
				id: 's1',
				name: 'Example',
				url: 'example.com',
				typeId: 'uuid-of-passkey-type',
				key,
				login: null,
				additionalFields: null,
				folderId: null,
			},
		]

		const payload = serializeVault(served, [])
		const { document, itemCount } = buildCxfDocument(payload.secrets, {
			typeNamesById: { 'uuid-of-passkey-type': 'passkey' },
		})
		expect(itemCount).toBe(1)

		const rows = await getParser('cxf').parse(JSON.stringify(document))

		expect(rows).toHaveLength(1)
		expect(rows[0].type).toBe('passkey')
		expect(rows[0].errors).toEqual([])
		const credential = parsePasskey(rows[0].password)
		expect(credential.credentialId).toBe('CRED_ID')
		expect(credential.rpId).toBe('example.com')
		expect(credential.userHandle).toBe('HANDLE')
		expect(credential.privateKey).toBe('PRIVATE_KEY_PLACEHOLDER')
	})
})
