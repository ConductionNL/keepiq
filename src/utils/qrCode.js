/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A QR code of a text, built in the browser (vault-item-clone-preview-and-print
 * D3). Nothing is fetched: the encoder is the bundled `qrcode-generator`, and
 * the result is a module matrix the caller draws as SVG.
 *
 * The text is encoded as UTF-8 in byte mode. The encoder's own byte mode keeps
 * only the low byte of each UTF-16 unit, so a password with a character
 * outside Latin-1 would encode to other bytes than the user typed; handing it
 * the UTF-8 bytes as one character each keeps every byte exact.
 *
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */

import qrcode from 'qrcode-generator'

/** Error correction level M: recovers about 15% of a damaged or blurred code. */
const ERROR_CORRECTION = 'M'

/** Modules of light border around the code, as the QR standard asks. */
export const QUIET_ZONE = 4

/**
 * The QR module matrix of a text.
 *
 * @param {string} text The text to encode.
 * @return {{size: number, dark: boolean[][]}} The module count per side and, per row, whether each module is dark.
 * @throws {TypeError} When the text is not a non-empty string.
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */
export function qrMatrix(text) {
	if (typeof text !== 'string' || text === '') {
		throw new TypeError('A QR code needs a non-empty text')
	}

	const bytes = new TextEncoder().encode(text)
	const code = qrcode(0, ERROR_CORRECTION)
	code.addData(String.fromCharCode(...bytes), 'Byte')
	code.make()

	const size = code.getModuleCount()
	const dark = []
	for (let row = 0; row < size; row++) {
		const line = []
		for (let col = 0; col < size; col++) {
			line.push(code.isDark(row, col))
		}
		dark.push(line)
	}

	return { size, dark }
}

/**
 * An SVG path drawing the dark modules of a matrix, one unit per module,
 * offset by the quiet zone.
 *
 * @param {{size: number, dark: boolean[][]}} matrix The matrix from {@link qrMatrix}.
 * @return {string} The path's `d` attribute.
 * @spec openspec/specs/secret-item-actions/spec.md#requirement-print-a-login-or-show-its-password-as-a-qr-code
 */
export function qrPath(matrix) {
	const parts = []
	matrix.dark.forEach((line, row) => {
		line.forEach((isDark, col) => {
			if (isDark) {
				parts.push(`M${col + QUIET_ZONE} ${row + QUIET_ZONE}h1v1h-1z`)
			}
		})
	})

	return parts.join('')
}
