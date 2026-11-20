/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Read a QR module matrix back with jsQR, the decoder the app already ships.
 */

import jsQR from 'jsqr'
import { QUIET_ZONE } from '../../../src/utils/qrCode.js'

/**
 * Decode a matrix with jsQR, drawn at four pixels per module with its quiet zone.
 *
 * @param {{size: number, dark: boolean[][]}} matrix The matrix.
 * @return {object|null} What jsQR read.
 */
export function decodeMatrix(matrix) {
	const scale = 4
	const side = (matrix.size + 2 * QUIET_ZONE) * scale
	const pixels = new Uint8ClampedArray(side * side * 4).fill(255)
	matrix.dark.forEach((line, row) => {
		line.forEach((isDark, col) => {
			if (!isDark) {
				return
			}
			for (let y = 0; y < scale; y++) {
				for (let x = 0; x < scale; x++) {
					const px = (col + QUIET_ZONE) * scale + x
					const py = (row + QUIET_ZONE) * scale + y
					const i = (py * side + px) * 4
					pixels[i] = 0
					pixels[i + 1] = 0
					pixels[i + 2] = 0
				}
			}
		})
	})

	return jsQR(pixels, side, side)
}
