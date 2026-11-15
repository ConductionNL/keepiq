/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The canonical passkey credential built from loose input: which input is
 * refused, and the default each optional field takes.
 *
 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-canonical-cxf-aligned-passkey-credential-schema
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { buildPasskeyCredential } from '../../src/passkey/passkey.js'

const REQUIRED = { credentialId: 'ID', rpId: 'example.com', privateKey: 'KEY' }

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-01-02T03:04:05.000Z'))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('buildPasskeyCredential', () => {
	it('refuses input that is no object or misses a required string', () => {
		expect(buildPasskeyCredential(undefined)).toBeNull()
		expect(buildPasskeyCredential('ID')).toBeNull()
		expect(buildPasskeyCredential(7)).toBeNull()
		for (const field of Object.keys(REQUIRED)) {
			expect(buildPasskeyCredential({ ...REQUIRED, [field]: '' })).toBeNull()
			expect(buildPasskeyCredential({ ...REQUIRED, [field]: 5 })).toBeNull()
			const missing = { ...REQUIRED }
			delete missing[field]
			expect(buildPasskeyCredential(missing)).toBeNull()
		}
	})

	it('defaults every optional field', () => {
		expect(buildPasskeyCredential({ ...REQUIRED })).toEqual({
			credentialId: 'ID',
			rpId: 'example.com',
			rpName: '',
			userName: '',
			userDisplayName: '',
			userHandle: '',
			privateKey: 'KEY',
			algorithm: -7,
			counter: 0,
			transports: [],
			createdAt: '2026-01-02T03:04:05.000Z',
		})
	})

	it('keeps given optional fields, stringified where they are strings', () => {
		expect(
			buildPasskeyCredential({
				...REQUIRED,
				rpName: 0,
				userName: false,
				userDisplayName: 'Alice',
				userHandle: 12,
				algorithm: -257,
				counter: 4,
				transports: ['usb', 3],
				createdAt: '2025-05-05T00:00:00Z',
				extra: 'dropped',
			}),
		).toEqual({
			credentialId: 'ID',
			rpId: 'example.com',
			rpName: '0',
			userName: 'false',
			userDisplayName: 'Alice',
			userHandle: '12',
			privateKey: 'KEY',
			algorithm: -257,
			counter: 4,
			transports: ['usb', '3'],
			createdAt: '2025-05-05T00:00:00Z',
		})
	})

	it('replaces optional fields of the wrong kind with their defaults', () => {
		const credential = buildPasskeyCredential({
			...REQUIRED,
			rpName: null,
			algorithm: 1.5,
			counter: '3',
			transports: 'usb',
			createdAt: '',
		})
		expect(credential.rpName).toBe('')
		expect(credential.algorithm).toBe(-7)
		expect(credential.counter).toBe(0)
		expect(credential.transports).toEqual([])
		expect(credential.createdAt).toBe('2026-01-02T03:04:05.000Z')
		expect(buildPasskeyCredential({ ...REQUIRED, createdAt: 5 }).createdAt).toBe(
			'2026-01-02T03:04:05.000Z',
		)
	})
})
