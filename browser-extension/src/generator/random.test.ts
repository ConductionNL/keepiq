import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { randomInt, shuffle } from './random'

describe('randomInt', () => {
	it('stays within the inclusive range', () => {
		for (let i = 0; i < 10_000; i++) {
			const value = randomInt(3, 7)
			expect(value).toBeGreaterThanOrEqual(3)
			expect(value).toBeLessThanOrEqual(7)
		}
		expect(randomInt(5, 5)).toBe(5)
	})

	it('fills 7776 buckets evenly over 1 000 000 draws', () => {
		const buckets = new Uint32Array(7776)
		for (let i = 0; i < 1_000_000; i++) buckets[randomInt(0, 7775)]!++
		const mean = 1_000_000 / 7776
		expect(Math.min(...buckets)).toBeGreaterThan(0)
		expect(Math.max(...buckets)).toBeLessThan(2 * mean)
	})

	it('rejects the draws that would favour low values', () => {
		// 2^32 is not a multiple of 3, so the top value is rejected and drawn again.
		const draws = [0xffffffff, 7]
		vi.spyOn(crypto, 'getRandomValues').mockImplementation((buffer) => {
			(buffer as Uint32Array)[0] = draws.shift()!
			return buffer
		})
		expect(randomInt(0, 2)).toBe(7 % 3)
		expect(draws).toEqual([])
	})

	it('refuses a range above 2^32, and takes the full 2^32', () => {
		expect(() => randomInt(0, 0x100000000)).toThrow(RangeError)
		const value = randomInt(0, 0xffffffff)
		expect(Number.isInteger(value) && value >= 0 && value <= 0xffffffff).toBe(true)
	})

	it.each([[0, 2.5], [0.5, 3], [Number.NaN, 3], [0, Number.NaN]])('refuses the bounds %s and %s', (min, max) => {
		expect(() => randomInt(min, max)).toThrow(RangeError)
	})
})

describe('shuffle', () => {
	it('keeps every item and puts each first about equally often', () => {
		const firsts = new Map<string, number>()
		for (let i = 0; i < 40_000; i++) {
			const items = shuffle(['a', 'b', 'c', 'd'], randomInt)
			expect([...items].sort()).toEqual(['a', 'b', 'c', 'd'])
			firsts.set(items[0]!, (firsts.get(items[0]!) ?? 0) + 1)
		}
		for (const count of firsts.values()) expect(Math.abs(count - 10_000)).toBeLessThan(600)
	})
})

describe('the source tree', () => {
	it('never uses Math.random', () => {
		const files = (dir: string): string[] => readdirSync(dir).flatMap((name) => {
			const path = join(dir, name)
			return statSync(path).isDirectory() ? files(path) : [path]
		})
		const root = new URL('..', import.meta.url).pathname
		const offenders = files(root).filter((path) => !path.endsWith('.test.ts') && readFileSync(path, 'utf8').includes('Math.random'))
		expect(offenders).toEqual([])
	})
})
