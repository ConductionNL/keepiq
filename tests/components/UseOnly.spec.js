/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Keepiq's web app never reveals, copies, exports or edits a use-only copy
 * (sharing-use-only-and-expiring-shares tasks 4.1 and 5.4).
 *
 * @spec openspec/specs/use-only-shares/spec.md#requirement-keepiqs-clients-never-reveal-a-use-only-value
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CopyButton from '../../src/components/CopyButton.vue'
import PasswordField from '../../src/components/PasswordField.vue'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { serializeVault } from '../../src/export/serializer.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('use-only copies in the web app', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
	})

	it('PasswordField offers no reveal and no copy, and never decrypts', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(PasswordField, { props: { resolve, useOnly: true } })

		expect(wrapper.findAll('button')).toHaveLength(0)
		await wrapper.vm.toggle()
		await expect(wrapper.vm.resolvePlain()).rejects.toThrow()
		expect(resolve).not.toHaveBeenCalled()
		expect(wrapper.html()).not.toContain('hunter2')
	})

	it('PasswordField still offers both for a normal secret', () => {
		const wrapper = mount(PasswordField, { props: { resolve: vi.fn() } })
		expect(wrapper.findAll('button').length).toBeGreaterThanOrEqual(2)
	})

	it('CopyButton renders nothing and copies nothing for a use-only value', async () => {
		const resolve = vi.fn().mockResolvedValue('hunter2')
		const wrapper = mount(CopyButton, { props: { resolve, useOnly: true } })

		expect(wrapper.find('button').exists()).toBe(false)
		await wrapper.vm.onCopy()
		expect(resolve).not.toHaveBeenCalled()
		expect(navigator.clipboard.writeText).not.toHaveBeenCalled()
	})

	it('every export leaves a use-only copy out', () => {
		const payload = serializeVault(
			[
				{ name: 'Mine', key: 'p1', useOnly: false },
				{ name: 'Supplier portal', key: 'S3cr3tValue', useOnly: true },
			],
			[],
		)
		expect(payload.secrets.map((s) => s.name)).toEqual(['Mine'])
		expect(JSON.stringify(payload)).not.toContain('S3cr3tValue')
	})

	it('refuses to decrypt a copy whose access ended, also offline', async () => {
		const session = useSessionStore()
		session.cryptoKey = {}
		const store = useSecretStore()
		await expect(
			store.decryptSecret({
				id: 'copy',
				key: 'CIPHER',
				accessExpiresAt: '2020-01-01T00:00:00+00:00',
			}),
		).rejects.toThrow(/ended/)
	})

	describe('detail sidebar', () => {
		async function mountDetail(secret) {
			vi.spyOn(axios, 'get').mockResolvedValue({ data: [] })
			vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
			window.OC = { currentUser: 'bob' }
			const secretStore = useSecretStore()
			secretStore.fetchSecret = vi.fn().mockResolvedValue(secret)
			useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])
			const wrapper = mount(SecretDetailSidebar, {
				props: { secretId: secret.id },
				global: {
					stubs: {
						NcAppSidebar: {
							template:
								'<aside><slot name="description" /><slot /></aside>',
						},
						NcActions: { template: '<div><slot /></div>' },
						NcActionButton: { template: '<button><slot /></button>' },
						NcNoteCard: { template: '<div><slot /></div>' },
						PasswordField: {
							props: ['useOnly'],
							template:
								'<input data-testid="stub-password" :data-use-only="String(useOnly)" />',
						},
						CopyButton: {
							template: '<button data-testid="stub-copy" />',
						},
						ShareList: true,
						GroupShareList: true,
						DelegationManager: true,
						ShareRequestForm: true,
						SecretRequestList: true,
						VersionHistoryPanel: {
							template: '<div data-testid="stub-versions" />',
						},
					},
				},
			})
			await flush()
			await flush()
			return wrapper
		}

		it('shows URL and login but no edit, share or additional fields', async () => {
			const wrapper = await mountDetail({
				id: 'copy',
				name: 'Supplier portal',
				url: 'https://portal.supplier.example',
				login: 'bob@example.com',
				key: 'secret',
				additionalFields: { pin: '1234' },
				ownerId: 'bob',
				useOnly: true,
			})

			expect(wrapper.text()).toContain('https://portal.supplier.example')
			expect(wrapper.text()).toContain('bob@example.com')
			expect(
				wrapper.find('[data-testid="secret-detail-use-only"]').exists(),
			).toBe(true)
			expect(wrapper.find('[data-testid="secret-detail-edit"]').exists()).toBe(
				false,
			)
			expect(
				wrapper.find('[data-testid="secret-detail-share"]').exists(),
			).toBe(false)
			expect(
				wrapper
					.find('[data-testid="stub-password"]')
					.attributes('data-use-only'),
			).toBe('true')
			expect(wrapper.text()).not.toContain('1234')
			expect(wrapper.find('[data-testid="stub-versions"]').exists()).toBe(
				false,
			)
		})

		it('offers edit and share on a normal copy', async () => {
			const wrapper = await mountDetail({
				id: 'mine',
				name: 'Mine',
				key: 'secret',
				ownerId: 'bob',
			})
			expect(wrapper.find('[data-testid="secret-detail-edit"]').exists()).toBe(
				true,
			)
			expect(
				wrapper.find('[data-testid="secret-detail-share"]').exists(),
			).toBe(true)
			expect(wrapper.find('[data-testid="stub-versions"]').exists()).toBe(true)
		})
	})
})
