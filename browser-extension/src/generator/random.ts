/**
 * The random primitives of the generator, a port of Keepiq's shared `src/generator/generator.js`
 * (with `password.ts`, `passphrase.ts` and `username.ts`). Every draw goes through a `RandomInt`, so the
 * shared vectors' seeded source reproduces the web module's output draw for draw.
 */

/** A uniform integer in [min, max], both inclusive. */
export type RandomInt = (min: number, max: number) => number

/** What a generator gives: the value, or why the request was refused, as a code. */
export type Generated<F extends string> = { ok: true; value: string } | { ok: false; failure: F }

/** Rejection sampling over `crypto.getRandomValues`, so no value is favoured (no modulo bias). */
export const randomInt: RandomInt = (min, max) => {
	// A fractional range would return values above `max`.
	if (!Number.isInteger(min) || !Number.isInteger(max)) throw new RangeError('Bounds must be integers')
	const range = max - min + 1
	if (range <= 1) return min
	if (range > 0x100000000) throw new RangeError('Range exceeds 2^32')
	const limit = Math.floor(0x100000000 / range) * range
	const buffer = new Uint32Array(1)
	let value: number
	do {
		crypto.getRandomValues(buffer)
		value = buffer[0]!
	} while (value >= limit)
	return min + (value % range)
}

/** Fisher–Yates in place: every arrangement is equally likely. */
export function shuffle<T>(items: T[], rand: RandomInt): T[] {
	for (let i = items.length - 1; i > 0; i--) {
		const j = rand(0, i)
		const swap = items[i]!
		items[i] = items[j]!
		items[j] = swap
	}
	return items
}
