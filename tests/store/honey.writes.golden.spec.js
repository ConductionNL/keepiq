/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the honey store's writes — flag, unflag, acknowledge and
 * snooze: the request each sends, the state it leaves on success, and on a
 * refusal or failure the message it keeps (the server's, else the error's
 * own) with `false` returned and the state untouched.
 *
 * @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
 * @spec openspec/specs/honey-credentials/spec.md#requirement-any-access-to-a-honey-secret-raises-a-high-severity-alert
 * @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useHoneyStore } from '../../src/store/modules/honey.js'

const REFUSED = { response: { status: 403, data: { message: 'Only the owner or an admin may do this' } } }
const DOWN = new Error('Network down')

const ALERTS = [
	{ id: 'a1', acknowledged: false },
	{ id: 'a2', acknowledged: false },
]

/**
 * @return {object} The store with a stale error, a status and two alerts.
 */
function store() {
	const honey = useHoneyStore()
	honey.error = 'stale'
	honey.status = { flagged: false, flag: null }
	honey.alerts = ALERTS.map((a) => ({ ...a }))
	return honey
}

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('honey store writes (golden)', () => {
	it('flag: posts the note and marks the secret flagged', async () => {
		const honey = store()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'f1', note: 'bait' } })
		expect(await honey.flag('s1', 'bait')).toBe(true)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/s1/honey', { note: 'bait' })
		expect(honey.status).toEqual({ flagged: true, flag: { id: 'f1', note: 'bait' } })
		expect(honey.error).toBe(null)
	})

	it('flag: sends an empty note by default', async () => {
		const honey = store()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		await honey.flag('s1')
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/s1/honey', { note: '' })
	})

	it('flag: a refusal keeps the server message and leaves the status', async () => {
		const honey = store()
		vi.spyOn(axios, 'post').mockRejectedValue(REFUSED)
		expect(await honey.flag('s1', 'bait')).toBe(false)
		expect(honey.error).toBe('Only the owner or an admin may do this')
		expect(honey.status).toEqual({ flagged: false, flag: null })
	})

	it('unflag: deletes the flag and marks the secret unflagged', async () => {
		const honey = store()
		honey.status = { flagged: true, flag: { id: 'f1' } }
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: null })
		expect(await honey.unflag('s1')).toBe(true)
		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/secrets/s1/honey')
		expect(honey.status).toEqual({ flagged: false, flag: null })
		expect(honey.error).toBe(null)
	})

	it('unflag: a failure keeps the error message and leaves the status', async () => {
		const honey = store()
		honey.status = { flagged: true, flag: { id: 'f1' } }
		vi.spyOn(axios, 'delete').mockRejectedValue(DOWN)
		expect(await honey.unflag('s1')).toBe(false)
		expect(honey.error).toBe('Network down')
		expect(honey.status).toEqual({ flagged: true, flag: { id: 'f1' } })
	})

	it('acknowledge: posts and swaps the alert in place', async () => {
		const honey = store()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'a2', acknowledged: true } })
		expect(await honey.acknowledge('a2')).toBe(true)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/honey/alerts/a2/acknowledge')
		expect(post.mock.calls[0]).toHaveLength(1)
		expect(honey.alerts).toEqual([
			{ id: 'a1', acknowledged: false },
			{ id: 'a2', acknowledged: true },
		])
		expect(honey.error).toBe(null)
	})

	it('acknowledge: a refusal keeps the message and the alerts', async () => {
		const honey = store()
		vi.spyOn(axios, 'post').mockRejectedValue(REFUSED)
		expect(await honey.acknowledge('a2')).toBe(false)
		expect(honey.error).toBe('Only the owner or an admin may do this')
		expect(honey.alerts).toEqual(ALERTS)
	})

	it('snooze: posts the hours and swaps the alert in place', async () => {
		const honey = store()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: { id: 'a1', snoozedUntil: 'later' } })
		expect(await honey.snooze('a1', 4)).toBe(true)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/honey/alerts/a1/snooze', { hours: 4 })
		expect(honey.alerts).toEqual([
			{ id: 'a1', snoozedUntil: 'later' },
			{ id: 'a2', acknowledged: false },
		])
	})

	it('snooze: defaults to 24 hours, and a failure keeps the message and the alerts', async () => {
		const honey = store()
		const post = vi.spyOn(axios, 'post').mockRejectedValue(DOWN)
		expect(await honey.snooze('a1')).toBe(false)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/honey/alerts/a1/snooze', { hours: 24 })
		expect(honey.error).toBe('Network down')
		expect(honey.alerts).toEqual(ALERTS)
	})

	it('clears the last error before each write', async () => {
		const honey = store()
		let seen = 'unset'
		vi.spyOn(axios, 'post').mockImplementation(async () => {
			seen = honey.error
			return { data: {} }
		})
		await honey.flag('s1')
		expect(seen).toBe(null)
	})
})
