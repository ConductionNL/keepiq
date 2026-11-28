/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The browser-side QR encoder (vault-item-clone-preview-and-print task 3.2):
 * a code built by `qrMatrix()` decodes back to the exact password, including
 * characters outside Latin-1, and `qrPath()` draws one square per dark module.
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */

import { describe, expect, it } from 'vitest'
import { qrMatrix, qrPath, QUIET_ZONE } from '../../src/utils/qrCode.js'
import { decodeMatrix } from './support/decodeQr.js'

describe('qrMatrix', () => {
	it('encodes a password that decodes back to itself', () => {
		const password = 'Xk9#mQ2$vL7@pR4!zT6&'
		const decoded = decodeMatrix(qrMatrix(password))
		expect(decoded).not.toBeNull()
		expect(decoded.data).toBe(password)
	})

	it('keeps characters outside Latin-1 exact, as UTF-8 bytes', () => {
		const password = 'wachtwoord-€-ŝ-😀'
		const decoded = decodeMatrix(qrMatrix(password))
		expect(decoded).not.toBeNull()
		expect(decoded.binaryData).toEqual(
			Array.from(new TextEncoder().encode(password)),
		)
		expect(decoded.data).toBe(password)
	})

	it('grows the code with the text', () => {
		expect(qrMatrix('a').size).toBe(21)
		expect(qrMatrix('a'.repeat(200)).size).toBeGreaterThan(21)
	})

	it('refuses an empty or non-string text', () => {
		expect(() => qrMatrix('')).toThrow(TypeError)
		expect(() => qrMatrix(null)).toThrow(TypeError)
	})
})

describe('qrPath', () => {
	it('draws one unit square per dark module, offset by the quiet zone', () => {
		const matrix = {
			size: 2,
			dark: [
				[true, false],
				[false, true],
			],
		}
		expect(qrPath(matrix)).toBe(
			`M${QUIET_ZONE} ${QUIET_ZONE}h1v1h-1zM${QUIET_ZONE + 1} ${QUIET_ZONE + 1}h1v1h-1z`,
		)
	})

	it('draws as many squares as the real code has dark modules', () => {
		const matrix = qrMatrix('Guest Wi-Fi password')
		const dark = matrix.dark.flat().filter(Boolean).length
		expect(qrPath(matrix).split('M').length - 1).toBe(dark)
	})
})
