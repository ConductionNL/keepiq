/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for the "renew root" action in
 * `src/components/settings/CaHealthSection.vue` (keepiq#741).
 *
 * `POST /api/v1/ca/renew-root` had no caller, so an admin warned about the
 * root's expiry had no way to act on it from the settings. The pins: the
 * button exists when there is a root, pressing it only opens a confirmation
 * (it re-signs every suite, so a stray click must not fire it), and only
 * the confirm posts to the renew-root endpoint.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-ca-certificate-renewal
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CaHealthSection from '../../src/components/settings/CaHealthSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const STATUS = {
	status: 'healthy',
	root: { id: 'root-1', expiresAt: '2046-01-01' },
	intermediate: { id: 'int-1', expiresAt: '2029-01-01' },
}

function mountSection() {
	return mount(CaHealthSection, {
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

describe('CaHealthSection renew root', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
		vi.spyOn(axios, 'get').mockResolvedValue({ data: STATUS })
	})

	it('offers a renew root action when a root exists', async () => {
		vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		expect(wrapper.find('[data-testid="ca-renew-root"]').exists()).toBe(true)
	})

	it('asks for confirmation and does not post on the first click', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		await wrapper.find('[data-testid="ca-renew-root"]').trigger('click')
		await flush()

		expect(wrapper.find('[data-testid="ca-renew-root-warning"]').exists()).toBe(
			true,
		)
		expect(post).not.toHaveBeenCalled()
	})

	it('posts to renew-root on confirm and shows how many suites were signed again', async () => {
		const post = vi
			.spyOn(axios, 'post')
			.mockResolvedValue({ data: { resignedCount: 7 } })
		const wrapper = mountSection()
		await flush()

		await wrapper.find('[data-testid="ca-renew-root"]').trigger('click')
		await flush()
		await wrapper.find('[data-testid="ca-renew-root-confirm"]').trigger('click')
		await flush()

		expect(post).toHaveBeenCalledTimes(1)
		expect(post.mock.calls[0][0]).toContain('/apps/keepiq/api/v1/ca/renew-root')
		expect(wrapper.find('[data-testid="ca-renew-root-result"]').text()).toContain(
			'7',
		)
		expect(wrapper.find('[data-testid="ca-renew-root-warning"]').exists()).toBe(
			false,
		)
	})

	it('cancel closes the dialog without posting', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const wrapper = mountSection()
		await flush()

		await wrapper.find('[data-testid="ca-renew-root"]').trigger('click')
		await flush()
		await wrapper.find('[data-testid="ca-renew-root-cancel"]').trigger('click')
		await flush()

		expect(post).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="ca-renew-root-warning"]').exists()).toBe(
			false,
		)
	})
})
