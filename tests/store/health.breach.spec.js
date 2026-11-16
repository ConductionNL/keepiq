/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The opt-in breach check of the health store and the finding lists it
 * exposes: only password-bearing values are checked, each distinct value
 * once, only the five-character SHA-1 prefix leaves the browser, and an
 * unreachable range marks the check unavailable.
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { sha256Hex } from '../../src/health/engine.js'
import { useHealthStore } from '../../src/store/modules/health.js'

/**
 * @param {string} value A value.
 * @return {Promise<string>} Its SHA-1 as upper-case hex.
 */
async function sha1(value) {
	const digest = await crypto.subtle.digest(
		'SHA-1',
		new TextEncoder().encode(value),
	)
	return Array.from(new Uint8Array(digest))
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
		.toUpperCase()
}

/**
 * Answer the range proxy as if `breached` appeared `count` times.
 *
 * @param {object} breached value → count.
 * @return {Promise<object>} The post spy.
 */
async function rangeProxy(breached) {
	const lines = {}
	for (const [value, count] of Object.entries(breached)) {
		const hash = await sha1(value)
		lines[hash.slice(0, 5)] =
			`${lines[hash.slice(0, 5)] ?? ''}${hash.slice(5)}:${count}\n`
	}
	return vi.spyOn(axios, 'post').mockImplementation(async (url, body) => ({
		data: {
			suffixes: `0000000000000000000000000000000000A:1\n${lines[body.prefix] ?? ''}`,
		},
	}))
}

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('runBreachChecks', () => {
	it('checks each distinct password once, sending only the prefix', async () => {
		const post = await rangeProxy({ hunter2: 42 })
		const store = useHealthStore()

		const results = await store.runBreachChecks([
			{ id: 'a', value: 'hunter2' },
			{ id: 'b', value: 'hunter2' },
			{ id: 'c', value: 'a-clean-passphrase' },
			{ id: 'd', value: '' },
			{ id: 'e', value: null },
		])

		expect(post).toHaveBeenCalledTimes(2)
		for (const [url, body] of post.mock.calls) {
			expect(url).toBe('/apps/keepiq/api/v1/breach-check/range')
			expect(Object.keys(body)).toEqual(['prefix'])
			expect(body.prefix).toHaveLength(5)
		}
		expect(post.mock.calls[0][1].prefix).toBe(
			(await sha1('hunter2')).slice(0, 5),
		)
		expect(JSON.stringify(post.mock.calls)).not.toContain('hunter2')

		expect(results.size).toBe(2)
		expect(results.get(await sha256Hex('hunter2'))).toEqual({
			status: 'breached',
			count: 42,
		})
		expect(results.get(await sha256Hex('a-clean-passphrase'))).toEqual({
			status: 'clean',
			count: 0,
		})
		expect(store.breachStatus).toBe('ready')
	})

	it('marks the check unavailable when a range cannot be fetched', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('proxy down'))
		const store = useHealthStore()

		const results = await store.runBreachChecks([{ id: 'a', value: 'hunter2' }])

		expect(results.get(await sha256Hex('hunter2'))).toEqual({
			status: 'unavailable',
			count: 0,
		})
		expect(store.breachStatus).toBe('unavailable')
	})

	it('asks nothing for a vault with no passwords', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useHealthStore()

		const results = await store.runBreachChecks([{ id: 'a', value: '' }])

		expect(results.size).toBe(0)
		expect(post).not.toHaveBeenCalled()
		expect(store.breachStatus).toBe('ready')
	})
})

describe('the finding lists', () => {
	it('splits the findings by flag and maps scores by id', () => {
		const store = useHealthStore()
		store.findings = [
			{ id: 'w', flags: ['weak', 'reused'], score: 1 },
			{ id: 's', flags: ['stale'], score: 4 },
			{ id: 'b', flags: ['breached', 'compromised'], score: 0 },
			{
				id: 'h',
				flags: ['insecure-address', 'two-factor-available'],
				score: null,
			},
			{ id: 'p', flags: ['passkey-available'] },
		]

		expect(store.weakFindings.map((f) => f.id)).toEqual(['w'])
		expect(store.reusedFindings.map((f) => f.id)).toEqual(['w'])
		expect(store.staleFindings.map((f) => f.id)).toEqual(['s'])
		expect(store.breachedFindings.map((f) => f.id)).toEqual(['b'])
		expect(store.compromisedFindings.map((f) => f.id)).toEqual(['b'])
		expect(store.insecureAddressFindings.map((f) => f.id)).toEqual(['h'])
		expect(store.twoFactorAvailableFindings.map((f) => f.id)).toEqual(['h'])
		expect(store.passkeyAvailableFindings.map((f) => f.id)).toEqual(['p'])
		expect(store.scoreById).toEqual({ w: 1, s: 4, b: 0 })
	})
})
