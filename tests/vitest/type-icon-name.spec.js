/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Material Design icon shown for each secret type.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/initial-state', () => ({ loadState: () => null }))

const { typeIconName } = await import('../../src/utils/favicon.js')

describe('typeIconName', () => {
	it.each([
		['login', 'Key'],
		['api_key', 'CodeTags'],
		['ssh_key', 'Console'],
		['totp', 'ClockOutline'],
		['identity', 'CardAccountDetailsOutline'],
	])('maps %s to %s', (typeName, icon) => {
		expect(typeIconName(typeName)).toBe(icon)
	})

	it('falls back to the key icon for an unknown type', () => {
		expect(typeIconName('unknown')).toBe('Key')
	})
})
