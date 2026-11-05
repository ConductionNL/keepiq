import { describe, expect, it } from 'vitest'
import { concat } from './bytes'

describe('concat', () => {
	it('joins byte arrays and number lists in order', () => {
		expect(concat(new Uint8Array([1, 2]), [3], new Uint8Array(), [4, 5])).toEqual(new Uint8Array([1, 2, 3, 4, 5]))
	})

	it('returns an empty array for no parts', () => {
		expect(concat()).toEqual(new Uint8Array())
	})

	it('copies, so changing a part later leaves the result alone', () => {
		const part = new Uint8Array([7])
		const joined = concat(part)
		part[0] = 8
		expect(joined).toEqual(new Uint8Array([7]))
	})
})
