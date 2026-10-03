/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/application/ApplicationLeasePolicyForm.vue`
 * (keepiq#753 point 3). The per-application lease-policy endpoint had no
 * screen. The pins: an admin sees and saves the override (empty field means
 * inherit, sent as null), the registrant sees it read-only with no save, and
 * a viewer the server refuses (404) sees nothing.
 *
 * @spec openspec/specs/machine-secret-leases/spec.md#requirement-admin-lease-ttl-policy
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ApplicationLeasePolicyForm from '../../src/components/application/ApplicationLeasePolicyForm.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

function view(
	canEdit,
	override = { defaultTtl: 600, maxTtl: null, renewable: null },
) {
	return {
		effective: {
			defaultTtl: 600,
			maxTtl: 3600,
			renewable: true,
			blockOnRevoke: false,
		},
		override,
		instance: { defaultTtl: 900, maxTtl: 3600, renewable: true },
		canEdit,
	}
}

function mountForm() {
	return mount(ApplicationLeasePolicyForm, {
		propsData: { applicationId: 'app-1' },
		global: {
			mixins: [
				{
					methods: {
						t: (_app, key, vars) =>
							Object.entries(vars || {}).reduce(
								(acc, [name, value]) =>
									acc.replace(`{${name}}`, value),
								key,
							),
					},
				},
			],
			stubs: {
				NcNoteCard: { template: '<div><slot /></div>' },
				NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
			},
		},
	})
}

describe('ApplicationLeasePolicyForm', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
	})

	it('shows the policy in force and the stored override to an admin', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: view(true) })
		const wrapper = mountForm()
		await flush()

		expect(get.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/applications/app-1/lease-policy',
		)
		expect(
			wrapper.find('[data-testid="lease-policy-effective"]').text(),
		).toContain('600 seconds by default, 3600 seconds at most')
		expect(
			wrapper.find('[data-testid="lease-policy-default-ttl"]').element.value,
		).toBe('600')
		expect(
			wrapper.find('[data-testid="lease-policy-max-ttl"]').element.value,
		).toBe('')
		expect(
			wrapper.find('[data-testid="lease-policy-renewable"]').element.value,
		).toBe('inherit')
	})

	it('saves the override with empty fields as inherit', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: view(true) })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = mountForm()
		await flush()

		await wrapper.find('[data-testid="lease-policy-default-ttl"]').setValue('')
		await wrapper.find('[data-testid="lease-policy-max-ttl"]').setValue('1800')
		await wrapper.find('[data-testid="lease-policy-renewable"]').setValue('no')
		await wrapper.find('form').trigger('submit')
		await flush()

		expect(put).toHaveBeenCalledTimes(1)
		expect(put.mock.calls[0][0]).toContain(
			'/apps/keepiq/api/v1/applications/app-1/lease-policy',
		)
		expect(put.mock.calls[0][1]).toEqual({
			defaultTtl: null,
			maxTtl: 1800,
			renewable: false,
		})
		expect(wrapper.find('[data-testid="lease-policy-saved"]').exists()).toBe(
			true,
		)
	})

	it('shows the server refusal instead of a saved notice', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: view(true) })
		vi.spyOn(axios, 'put').mockRejectedValue({
			response: {
				status: 400,
				data: { message: 'Lease TTLs must be at least 60 seconds' },
			},
		})
		const wrapper = mountForm()
		await flush()

		await wrapper.find('form').trigger('submit')
		await flush()

		expect(wrapper.find('[data-testid="lease-policy-error"]').text()).toContain(
			'at least 60 seconds',
		)
		expect(wrapper.find('[data-testid="lease-policy-saved"]').exists()).toBe(
			false,
		)
	})

	it('is read-only for the registrant: no fields and no save', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: view(false) })
		const put = vi.spyOn(axios, 'put')
		const wrapper = mountForm()
		await flush()

		expect(wrapper.find('[data-testid="lease-policy-effective"]').exists()).toBe(
			true,
		)
		expect(wrapper.find('form').exists()).toBe(false)
		expect(wrapper.find('[data-testid="lease-policy-save"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="lease-policy-readonly"]').exists()).toBe(
			true,
		)
		expect(put).not.toHaveBeenCalled()
	})

	it('stays hidden when the server refuses the read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		const wrapper = mountForm()
		await flush()

		expect(
			wrapper.find('[data-testid="application-lease-policy"]').exists(),
		).toBe(false)
		expect(wrapper.find('[data-testid="lease-policy-error"]').exists()).toBe(
			false,
		)
	})
})
