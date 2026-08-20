/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/secretRequest/SecretRequestList.vue`.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.4
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import axios from '@nextcloud/axios'

import SecretRequestList from '../../src/components/secretRequest/SecretRequestList.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('SecretRequestList', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('renders the empty state when there are no requests', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(
			wrapper.find('[data-testid="secret-request-list-empty"]').exists(),
		).toBe(true)
	})

	it('renders one row per request with the correct status-flavored testid', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'tok-abcdef0123',
					requested_fields: ['key'],
				},
				{
					id: 'r2',
					status: 'fulfilled',
					token: 'tok-zzzzzzzzzz',
					requested_fields: ['key'],
				},
			],
		})
		const wrapper = mount(SecretRequestList)
		await flush()
		expect(
			wrapper.find('[data-testid="secret-request-row-pending"]').exists(),
		).toBe(true)
		expect(
			wrapper.find('[data-testid="secret-request-row-fulfilled"]').exists(),
		).toBe(true)
		// Revoke only on pending rows.
		expect(
			wrapper.findAll('[data-testid="secret-request-row-revoke"]'),
		).toHaveLength(1)
	})

	it('filters by secretId when the prop is set', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 't1',
					secret_id: 'sec-1',
					requested_fields: ['key'],
				},
				{
					id: 'r2',
					status: 'pending',
					token: 't2',
					secret_id: 'sec-2',
					requested_fields: ['key'],
				},
			],
		})
		const wrapper = mount(SecretRequestList, {
			propsData: { secretId: 'sec-2' },
		})
		await flush()
		expect(
			wrapper.findAll('[data-testid="secret-request-row-pending"]'),
		).toHaveLength(1)
	})

	it('dispatches the revoke action when the revoke button is clicked', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'tok-1',
					requested_fields: ['key'],
				},
			],
		})
		const del = vi.spyOn(axios, 'delete').mockResolvedValue({ data: {} })
		const wrapper = mount(SecretRequestList)
		await flush()
		await wrapper
			.find('[data-testid="secret-request-row-revoke"]')
			.trigger('click')
		await flush()
		expect(del).toHaveBeenCalled()
	})
	it('copies the fill link for a pending request', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		Object.defineProperty(navigator, 'clipboard', {
			value: { writeText },
			configurable: true,
		})
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: 'aaaaaaaabbbbbbbbccccccccdddddddd',
					requested_fields: ['key'],
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		await wrapper
			.find('[data-testid="secret-request-row-copy-r1"]')
			.trigger('click')

		// The anonymous shell form — the one a recipient without an account can open.
		expect(writeText).toHaveBeenCalledTimes(1)
		const copied = writeText.mock.calls[0][0]
		expect(copied).toContain('/apps/doriath/public#/share/request/')
		expect(copied).toContain('aaaaaaaabbbbbbbbccccccccdddddddd')
		expect(copied).not.toContain('/api/v1/public/')
	})

	it('offers no link for a fulfilled or lapsed request', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'done',
					status: 'fulfilled',
					token: 'tok-1',
					requested_fields: ['key'],
				},
				{
					id: 'lapsed',
					status: 'pending',
					token: 'tok-2',
					requested_fields: ['key'],
					// Expiry is checked against the timestamp, not the status: nothing
					// sweeps yet, so a lapsed request still reads as pending.
					expiresAt: '2000-01-01T00:00:00+00:00',
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		expect(
			wrapper.find('[data-testid="secret-request-row-copy-done"]').exists(),
		).toBe(false)
		expect(
			wrapper.find('[data-testid="secret-request-row-copy-lapsed"]').exists(),
		).toBe(false)
	})

	it('never renders the full token', async () => {
		const full = 'ffffffff11111111222222223333333'
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: [
				{
					id: 'r1',
					status: 'pending',
					token: full,
					requested_fields: ['key'],
				},
			],
		})

		const wrapper = mount(SecretRequestList)
		await flush()

		// Truncated on screen; the full value reaches the clipboard only on request.
		expect(wrapper.text()).not.toContain(full)
	})
})
