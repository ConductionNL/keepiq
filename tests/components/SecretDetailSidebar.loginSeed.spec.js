/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A login that carries a seed shows a live code, and the seed text is not
 * listed among its additional fields (vault-login-totp-codes task 1.3).
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-the-login-shows-a-live-code
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'

const stubAll = {
	NcAppSidebar: { template: '<aside><slot /></aside>' },
	NcButton: { template: '<button><slot /></button>' },
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	Delete: { template: '<span />' },
	Lock: { template: '<span />' },
	Pencil: { template: '<span />' },
	FolderMove: { template: '<span />' },
	ShareVariant: { template: '<span />' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	ShareList: { template: '<div />' },
	TotpDisplay: {
		props: ['seed'],
		template: '<output class="totp-stub">{{ seed ? "code" : "" }}</output>',
	},
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the detail sidebar over a secret with the given decrypted blob.
 *
 * @param {object|null} additionalFields The decrypted members.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountDetail(additionalFields, typeName = 'login') {
	const secret = {
		id: 's-1',
		name: 'Supplier API',
		typeId: 'login',
		key: 'value',
		additionalFields,
	}

	useSecretStore().fetchSecret = vi.fn().mockResolvedValue(secret)
	useSecretTypeStore().types = [{ id: 'login', name: typeName, label: typeName }]
	useSecretTypeStore().fetchTypes = vi.fn().mockResolvedValue([])

	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's-1' },
		global: {
			stubs: stubAll,
		},
	})
	await flush()
	await flush()

	return wrapper
}

const SEED = 'otpauth://totp/rfc?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'

describe('SecretDetailSidebar: the code of a login', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		window.OC = { currentUser: 'alice' }
	})

	it('shows the code row with the login seed and keeps the seed out of the list', async () => {
		const wrapper = await mountDetail({ totp: SEED, pin: '1234' })

		const totp = wrapper.findComponent('[data-testid="secret-detail-totp"]')
		expect(totp.exists()).toBe(true)
		expect(totp.props('seed')).toBe(SEED)
		expect(wrapper.text()).toContain('pin')
		expect(wrapper.text()).not.toContain(SEED)
	})

	it('reads a legacy otpauth member as the seed', async () => {
		const wrapper = await mountDetail({ otpauth: SEED })

		expect(
			wrapper
				.findComponent('[data-testid="secret-detail-totp"]')
				.props('seed'),
		).toBe(SEED)
		expect(wrapper.vm.hasAdditionalFields).toBe(false)
	})

	it('shows no code row for a login without a seed', async () => {
		const wrapper = await mountDetail({ pin: '1234' })

		expect(wrapper.find('[data-testid="secret-detail-totp"]').exists()).toBe(
			false,
		)
	})

	it('hands a malformed seed to the code row, which shows the invalid state', async () => {
		const wrapper = await mountDetail({ totp: 'not a seed' })

		expect(
			wrapper
				.findComponent('[data-testid="secret-detail-totp"]')
				.props('seed'),
		).toBe('not a seed')
	})

	it('leaves a member named totp on a note in the list', async () => {
		const wrapper = await mountDetail({ totp: 'text' }, 'note')

		expect(wrapper.find('[data-testid="secret-detail-totp"]').exists()).toBe(
			false,
		)
		expect(wrapper.text()).toContain('totp')
	})
})
