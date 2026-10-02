/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The web app's passkey list shows a browser extension credential as one,
 * and revoking it calls the delete route (keepiq#784).
 *
 * @spec openspec/specs/extension-biometric-unlock/spec.md#requirement-extension-credentials-are-visible-and-revocable-in-the-web-app
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PasskeyManager from '../../src/components/PasskeyManager.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const CREDENTIALS = [
	{
		id: 'web-1',
		label: 'MacBook Touch ID',
		clientKind: 'web',
		status: 'active',
		lastUsedAt: null,
	},
	{
		id: 'ext-1',
		label: 'Laptop',
		clientKind: 'extension',
		status: 'active',
		lastUsedAt: null,
	},
]

function mountManager() {
	return mount(PasskeyManager, {
		global: {
			mixins: [{ methods: { t: (_app, key) => key } }],
			stubs: {
				NcNoteCard: { template: '<div><slot /></div>' },
				NcButton: {
					template:
						'<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>',
					emits: ['click'],
				},
				NcPasswordField: true,
				NcTextField: true,
				KeyIcon: true,
			},
		},
	})
}

describe('PasskeyManager', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.PublicKeyCredential = function PublicKeyCredential() {}
		Object.defineProperty(navigator, 'credentials', {
			value: {},
			configurable: true,
		})
		vi.spyOn(axios, 'get').mockResolvedValue({ data: CREDENTIALS })
	})

	afterEach(() => {
		delete window.PublicKeyCredential
		vi.restoreAllMocks()
	})

	it('labels the extension credential and only that one', async () => {
		const wrapper = mountManager()
		await flush()

		expect(wrapper.find('[data-testid="passkey-client-ext-1"]').text()).toBe(
			'Browser extension',
		)
		expect(wrapper.find('[data-testid="passkey-client-web-1"]').exists()).toBe(
			false,
		)
		expect(wrapper.find('[data-testid="passkey-ext-1"]').text()).toContain(
			'Laptop',
		)
	})

	it('revokes the extension credential through the delete route', async () => {
		const del = vi
			.spyOn(axios, 'delete')
			.mockResolvedValue({ data: { revoked: true } })
		const wrapper = mountManager()
		await flush()

		await wrapper.find('[data-testid="passkey-revoke-ext-1"]').trigger('click')
		await flush()

		expect(del).toHaveBeenCalledTimes(1)
		expect(del.mock.calls[0][0]).toContain('/apps/keepiq/api/v1/passkeys/ext-1')
		expect(wrapper.find('[data-testid="passkey-ext-1"]').exists()).toBe(false)
	})
})
