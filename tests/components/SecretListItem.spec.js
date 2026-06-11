/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/SecretListItem.vue`.
 *
 * SecretListItem is the row component on SecretList. It MUST:
 *  - render the secret's name and url
 *  - emit `open` with the secret id when the row is clicked (so the
 *    parent list can navigate to /secrets/{id})
 *  - render the type-icon fallback when the URL has no favicon AND no
 *    favicon service is configured (the default privacy posture)
 *  - render a "Locked — suite revoked" badge instead of the copy button
 *    when secret.blocked is true (revoked-suite handling per ADR-007)
 *  - NOT bubble the inner CopyButton click as an `open` event (stop
 *    propagation on the actions slot — clicking copy must not navigate)
 *  - on copy, lazily decrypt by calling useSecretStore().fetchSecret
 *
 * @spec openspec/changes/implement-secrets/tasks.md#7.3
 * @spec openspec/changes/implement-secrets/tasks.md#13.4
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import SecretListItem from '../../src/components/SecretListItem.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

describe('SecretListItem', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		// Seed the type store so the iconComponent computed has data.
		const typeStore = useSecretTypeStore()
		typeStore.types = [
			{ id: 'type-login', name: 'login', label: 'Login' },
			{ id: 'type-api', name: 'api_key', label: 'API key' },
		]
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
		document.execCommand = vi.fn().mockReturnValue(true)
	})

	it('renders the secret name and url', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'GitHub PAT',
					url: 'https://github.com',
					typeId: 'type-api',
				},
			},
		})

		expect(wrapper.text()).toContain('GitHub PAT')
		expect(wrapper.text()).toContain('https://github.com')
	})

	it('emits `open` with the secret id on row click', async () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-42', name: 'AWS', url: null, typeId: 'type-login' },
			},
		})

		await wrapper.find('.secret-list-item').trigger('click')

		expect(wrapper.emitted('open')).toBeTruthy()
		expect(wrapper.emitted('open')[0]).toEqual(['s-42'])
	})

	it('renders the type-icon fallback when favicons are disabled (default)', () => {
		// faviconServiceUrl loadState returns '' (default) so resolveFaviconUrl
		// returns null and the <img> branch is skipped.
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: 'https://github.com', typeId: 'type-api' },
			},
		})

		expect(wrapper.find('img').exists()).toBe(false)
		// The fallback icon component renders an SVG via vue-material-design-icons.
		expect(wrapper.find('.secret-list-item__icon').exists()).toBe(true)
	})

	it('renders the locked badge instead of CopyButton when secret.blocked', () => {
		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: {
					id: 's-1',
					name: 'Old API',
					url: 'https://old.example',
					typeId: 'type-api',
					blocked: true,
					blockedReason: 'suite-revoked',
				},
			},
		})

		expect(wrapper.text()).toContain('Locked — suite revoked')
		expect(wrapper.find('.secret-list-item__blocked').exists()).toBe(true)
		expect(wrapper.classes()).toContain('secret-list-item--blocked')
		// The copy actions area MUST NOT render when blocked.
		expect(wrapper.find('.secret-list-item__actions').exists()).toBe(false)
	})

	it('clicking the copy button does NOT bubble as `open` (stop.propagation)', async () => {
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi.fn().mockResolvedValue({
			id: 's-1',
			name: 'X',
			key: 'plaintext-key',
		})

		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: null, typeId: 'type-login' },
			},
		})

		// Click directly on the actions wrapper which has @click.stop.
		await wrapper.find('.secret-list-item__actions').trigger('click')

		// The row's `open` event must NOT have fired.
		expect(wrapper.emitted('open')).toBeFalsy()
	})

	it('resolveKey() lazily decrypts via useSecretStore().fetchSecret', async () => {
		const secretStore = useSecretStore()
		secretStore.fetchSecret = vi.fn().mockResolvedValue({
			id: 's-1',
			name: 'X',
			key: 'decrypted-key',
		})

		const wrapper = mount(SecretListItem, {
			propsData: {
				secret: { id: 's-1', name: 'X', url: null, typeId: 'type-login' },
			},
		})

		const value = await wrapper.vm.resolveKey()

		expect(secretStore.fetchSecret).toHaveBeenCalledWith('s-1')
		expect(value).toBe('decrypted-key')
	})
})
