/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A seeded random source for the shared generator cases: xorshift32, one
 * step per draw, then `min + x % range`. The native apps' Kotlin core has
 * the same function in its commonTest (GeneratorVectorsTest), so a case
 * pins every draw the web generator makes, in order. Tests only: the
 * generators themselves use crypto.getRandomValues and the platform source.
 *
 * @param {number} seed A non-zero 32-bit seed.
 * @return {(min: number, max: number) => number}
 */
export function seededRandom(seed) {
	let x = seed >>> 0 || 1
	return (min, max) => {
		x ^= x << 13
		x >>>= 0
		x ^= x >>> 17
		x ^= x << 5
		x >>>= 0
		const range = max - min + 1
		return range <= 1 ? min : min + (x % range)
	}
}
