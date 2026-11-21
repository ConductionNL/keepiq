import { describe, expect, it } from 'vitest'
import { encodeCbor, type CborValue } from './cbor'

const hex = (bytes: Uint8Array) => Buffer.from(bytes).toString('hex')

describe('encodeCbor', () => {
	// RFC 8949 Appendix A.
	it.each<[CborValue, string]>([
		[0, '00'],
		[23, '17'],
		[24, '1818'],
		[100, '1864'],
		[1000, '1903e8'],
		[1000000, '1a000f4240'],
		[0xffffffff, '1affffffff'],
		[-1, '20'],
		[-10, '29'],
		[-100, '3863'],
		[-1000, '3903e7'],
		['', '60'],
		['a', '6161'],
		['IETF', '6449455446'],
		['ü', '62c3bc'],
		[new Uint8Array(), '40'],
		[new Uint8Array([1, 2, 3, 4]), '4401020304'],
		[new Map(), 'a0'],
		[new Map([[1, 2], [3, 4]]), 'a201020304'],
	])('%o as %s', (value, expected) => {
		expect(hex(encodeCbor(value))).toBe(expected)
	})

	it('sorts map keys shortest first, then bytewise', () => {
		const map = new Map<number | string, CborValue>([['aa', 1], [-1, 2], ['b', 3], [10, 4], [1, 5]])
		expect(hex(encodeCbor(map))).toBe('a5' + '0105' + '0a04' + '2002' + '616203' + '62616101')
	})

	it('uses a two-byte head for a 300-byte string', () => {
		expect(hex(encodeCbor(new Uint8Array(300))).slice(0, 6)).toBe('59012c')
	})

	it.each([1.5, 2 ** 32, -(2 ** 32) - 1, Number.NaN])('refuses %d', (value) => {
		expect(() => encodeCbor(value)).toThrow(RangeError)
	})
})
