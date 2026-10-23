import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import { webWordlist } from '@/src/testing/vectors'
import { createWordlist, WORDLIST_SIZE } from './wordlist'

const bundled = readFileSync(new URL('../../public/wordlist/eff-large.txt', import.meta.url), 'utf8')

describe('the bundled word list', () => {
	it('equals the web app list', () => {
		expect(bundled.split('\n').filter(Boolean)).toEqual(webWordlist())
	})

	it('is the EFF original with its dice numbers put back', () => {
		const dice = (i: number) => [...i.toString(6).padStart(5, '0')].map((digit) => Number(digit) + 1).join('')
		const original = bundled.split('\n').filter(Boolean).map((word, i) => `${dice(i)}\t${word}\n`).join('')
		// The hash the web module's header and LICENSE.txt name.
		expect(createHash('sha256').update(original).digest('hex')).toBe('addd35536511597a02fa0a9ff1e5284677b8883b83e986e43f15a3db996b903e')
	})
})

describe('createWordlist', () => {
	it('loads once and shares the list', async () => {
		const load = vi.fn(async () => bundled)
		const wordlist = createWordlist(load)
		const [first, second] = await Promise.all([wordlist(), wordlist()])
		expect(first).toHaveLength(WORDLIST_SIZE)
		expect(second).toBe(first)
		await wordlist()
		expect(load).toHaveBeenCalledTimes(1)
	})

	it('gives null for a failed or truncated load, and tries again on the next call', async () => {
		const load = vi.fn<() => Promise<string>>()
			.mockRejectedValueOnce(new Error('offline'))
			.mockResolvedValueOnce('abacus\nabdomen\n')
			.mockResolvedValueOnce(bundled)
		const wordlist = createWordlist(load)
		expect(await wordlist()).toBeNull()
		expect(await wordlist()).toBeNull()
		expect(await wordlist()).toHaveLength(WORDLIST_SIZE)
		expect(load).toHaveBeenCalledTimes(3)
	})

	it('fetches the bundled file by its extension URL', async () => {
		const fetch = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response(bundled))
		const { wordlist } = await import('./wordlist')
		expect(await wordlist()).toHaveLength(WORDLIST_SIZE)
		expect(String(fetch.mock.calls[0]![0])).toMatch(/\/wordlist\/eff-large\.txt$/)
	})
})
