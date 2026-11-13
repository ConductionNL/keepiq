/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CA health section's status indicator and its other admin actions:
 * which button shows for which status, that retry-bootstrap and
 * force-renew-intermediate post and then refresh the status, the issued
 * counts, and the message when renewing the root fails.
 *
 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
 * @spec openspec/specs/encryption-suites/spec.md#requirement-ca-certificate-renewal
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import CaHealthSection from '../../src/components/settings/CaHealthSection.vue'

/**
 * Mount the section with a translating mixin and light stubs.
 *
 * @return {object} The wrapper.
 */
function mountSection() {
	return mount(CaHealthSection, {
		global: {
			mixins: [
				{
					methods: {
						t: (_app, key, vars) =>
							Object.entries(vars || {}).reduce(
								(acc, [name, value]) => acc.replace(`{${name}}`, value),
								key,
							),
					},
				},
			],
			stubs: {
				CnSettingsSection: { template: '<section><slot /></section>' },
				NcDialog: {
					props: ['open'],
					template:
						'<div v-if="open" data-testid="stub-dialog"><slot /><slot name="actions" /></div>',
				},
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
}

/**
 * Find a button by its exact label.
 *
 * @param {object} wrapper The section wrapper.
 * @param {string} label The label.
 * @return {object|undefined} The button wrapper.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

afterEach(() => {
	vi.restoreAllMocks()
})

describe('CaHealthSection status', () => {
	it.each([
		['healthy', 'green', 'Healthy'],
		['expiring_soon', 'yellow', 'Expiring soon'],
		['action_required', 'red', 'Action required'],
		['not_configured', 'grey', 'Not configured'],
		['something_else', 'grey', 'Unknown'],
	])('shows %s as a %s indicator labelled %s', async (status, colour, label) => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { status } })
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.find('.ca-health__indicator').classes()).toContain(
			`ca-health__indicator--${colour}`,
		)
		expect(wrapper.find('strong').text()).toBe(label)
	})

	it('lists the issued counts when the server reports them', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: {
				status: 'healthy',
				issued: {
					activeUserSuites: 4,
					activeApplicationSuites: 2,
					storedCertificates: 9,
					storedExpiringSoon: 1,
				},
			},
		})
		const wrapper = mountSection()
		await flushPromises()

		expect(
			wrapper.findAll('[data-testid="ca-issued-counts"] li').map((li) => li.text()),
		).toEqual([
			'Active user vault certificates: 4',
			'Active application certificates: 2',
			'Stored certificate secrets: 9',
			'Stored certificates expiring within 30 days: 1',
		])
	})
})

describe('CaHealthSection actions', () => {
	it('offers only retry bootstrap when not configured, and refreshes after it', async () => {
		const get = vi.spyOn(axios, 'get')
			.mockResolvedValueOnce({ data: { status: 'not_configured' } })
			.mockResolvedValueOnce({ data: { status: 'healthy' } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flushPromises()

		expect(button(wrapper, 'Force renew intermediate')).toBeUndefined()
		expect(wrapper.find('[data-testid="ca-renew-root"]').exists()).toBe(false)

		await button(wrapper, 'Retry bootstrap').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/ca/bootstrap-retry')
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.find('strong').text()).toBe('Healthy')
		expect(button(wrapper, 'Retry bootstrap')).toBeUndefined()
	})

	it.each(['healthy', 'expiring_soon'])(
		'offers force renew intermediate when %s, posts it and refreshes',
		async (status) => {
			const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { status } })
			const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			const wrapper = mountSection()
			await flushPromises()

			expect(button(wrapper, 'Retry bootstrap')).toBeUndefined()
			await button(wrapper, 'Force renew intermediate').trigger('click')
			await flushPromises()

			expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/ca/renew-intermediate')
			expect(get).toHaveBeenCalledTimes(2)
			expect(wrapper.vm.loading).toBe(false)
		},
	)

	it('offers no renewal while action is required', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { status: 'action_required' } })
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.findAll('button')).toHaveLength(0)
	})

	it('reports a failed root renewal, closes the dialog and does not refresh', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({
			data: { status: 'healthy', root: { expiresAt: '2046-01-01' } },
		})
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('500'))
		const wrapper = mountSection()
		await flushPromises()

		await wrapper.find('[data-testid="ca-renew-root"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-testid="ca-renew-root-confirm"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="ca-renew-root-result"]').text()).toBe(
			'Could not renew the root certificate.',
		)
		expect(wrapper.vm.renewRootOpen).toBe(false)
		expect(wrapper.vm.loading).toBe(false)
		expect(get).toHaveBeenCalledTimes(1)
	})

	it('counts zero re-signed suites when the server omits the count', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({
			data: { status: 'healthy', root: { expiresAt: '2046-01-01' } },
		})
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flushPromises()

		wrapper.vm.renewRoot()
		await flushPromises()

		expect(wrapper.find('[data-testid="ca-renew-root-result"]').text()).toBe(
			'Root renewed. 0 encryption suites signed again.',
		)
	})
})
