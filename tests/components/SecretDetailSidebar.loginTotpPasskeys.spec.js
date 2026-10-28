/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A login's one-time code and passkeys in the secret sidebar (keepiq#1326):
 * the code shows with its copy, never the seed; the passkeys list by their
 * metadata, never a private key; Delete is offered only where the login may
 * be changed, and saves the remaining passkeys with the base versions.
 *
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-the-login-shows-a-live-code
 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
 */

import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SecretDetailSidebar from '../../src/components/SecretDetailSidebar.vue'
import TotpDisplay from '../../src/components/TotpDisplay.vue'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { valueFieldsSupported } from '../../src/utils/valueFields.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/utils/valueFields.js', async (importOriginal) => ({
	...(await importOriginal()),
	valueFieldsSupported: vi.fn(() => true),
}))

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const SEED = 'otpauth://totp/Bank:alice?secret=GEZDGNBVGY3TQOJQ'
const BASE = '2026-10-01T10:00:00+00:00'
const BANK = {
	credentialId: 'Y3JlZC1iYW5r',
	rpId: 'bank.example',
	rpName: 'Bank',
	userName: 'alice',
	privateKey: 'PRIVATE-KEY-BANK',
	createdAt: '2026-09-01T10:00:00Z',
}
const BANK_APP = {
	...BANK,
	credentialId: 'Y3JlZC1hcHA',
	userName: 'alice-app',
	privateKey: 'PRIVATE-KEY-APP',
}

const button = {
	props: ['disabled'],
	emits: ['click'],
	template:
		'<button :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
}
const stubs = {
	NcAppSidebar: { template: '<aside><slot name="description" /><slot /></aside>' },
	NcActions: { template: '<div><slot /></div>' },
	NcActionButton: { template: '<button><slot /></button>' },
	NcButton: button,
	NcEmptyContent: { template: '<div><slot /></div>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	CopyButton: { template: '<button />' },
	PasswordField: { template: '<input />' },
	TotpDisplay: { props: ['seed'], template: '<div class="totp-stub" />' },
	ShareList: true,
	GroupShareList: true,
	DelegationManager: true,
	ShareRequestForm: true,
	SecretRequestList: true,
	SecretRequestCreateDialog: true,
	SecretActivityTab: true,
	AttachmentPanel: true,
	RotationPanel: true,
	HoneyPanel: true,
	VersionHistoryPanel: true,
}

/**
 * Mount the sidebar over a secret Bob holds.
 *
 * @param {object} fields Fields of the secret beyond the defaults.
 * @param {object|null} [context] Its write-context; Bob's own secret by default.
 * @param {object} [globalStubs] The component stubs.
 * @return {Promise<object>} The wrapper.
 */
async function mountSecret(fields = {}, context = undefined, globalStubs = stubs) {
	window.OC = { currentUser: 'bob' }
	vi.spyOn(axios, 'get').mockImplementation(async (url) => ({
		data: /write-context$/.test(url)
			? context === undefined
				? { sourceSecretId: 's1', effectiveGrade: 'owner' }
				: context
			: [],
	}))
	useSecretStore().fetchSecret = vi.fn(async (id) => ({
		id,
		name: 'Bank',
		typeId: 'type-login',
		key: 'password',
		login: 'alice',
		ownerId: 'bob',
		updatedAt: BASE,
		totp: SEED,
		passkeys: JSON.stringify([BANK, BANK_APP]),
		...fields,
	}))
	const wrapper = mount(SecretDetailSidebar, {
		props: { secretId: 's1' },
		global: { stubs: globalStubs },
		attachTo: document.body,
	})
	await flush()
	await flush()
	return wrapper
}

const has = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`).exists()

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	const typeStore = useSecretTypeStore()
	typeStore.types = [
		{ id: 'type-login', name: 'login', label: 'Login' },
		{ id: 'type-totp', name: 'totp', label: 'Authenticator' },
		{ id: 'type-passkey', name: 'passkey', label: 'Passkey' },
		{ id: 'type-note', name: 'note', label: 'Note' },
	]
	typeStore.fetchTypes = vi.fn().mockResolvedValue()
	vi.mocked(valueFieldsSupported).mockReturnValue(true)
	vi.mocked(showError).mockClear()
	document.body.innerHTML = ''
})

describe("a login's code and passkeys", () => {
	it('shows the code from the seed, never the seed itself', async () => {
		const wrapper = await mountSecret()

		const code = wrapper.findComponent(
			'[data-testid="secret-detail-login-totp"]',
		)
		expect(code.props('seed')).toBe(SEED)
		expect(wrapper.html()).not.toContain('GEZDGNBVGY3TQOJQ')
	})

	it('lists the passkeys without a private key, with Delete for its owner', async () => {
		const wrapper = await mountSecret()

		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(wrapper.html()).not.toContain('PRIVATE-KEY')
		expect(wrapper.findAll('[data-testid="passkey-list-delete"]')).toHaveLength(
			2,
		)
	})

	it('shows neither row for a login without them', async () => {
		const wrapper = await mountSecret({ totp: '', passkeys: '' })
		expect(has(wrapper, 'secret-detail-login-totp')).toBe(false)
		expect(has(wrapper, 'secret-detail-login-passkeys')).toBe(false)
	})

	it('shows them on any type that holds them, with the same Delete', async () => {
		const wrapper = await mountSecret({ typeId: 'type-note' })
		expect(has(wrapper, 'secret-detail-login-totp')).toBe(true)
		expect(wrapper.findAll('[data-testid="passkey-list-delete"]')).toHaveLength(
			2,
		)
	})

	it('shows them on a parsed identity, whose credential box is hidden', async () => {
		useSecretTypeStore().types.push({
			id: 'type-identity',
			name: 'identity',
			label: 'Identity',
		})
		const wrapper = await mountSecret({
			typeId: 'type-identity',
			login: '',
			key: JSON.stringify({ firstName: 'Alice' }),
		})
		expect(has(wrapper, 'secret-detail-login-totp')).toBe(true)
		expect(has(wrapper, 'secret-detail-login-passkeys')).toBe(true)
	})

	it('never puts the seed in the page through the real code display', async () => {
		useSessionStore().cryptoKey = { fake: true }
		const { TotpDisplay: _stubbed, ...realTotp } = stubs
		const wrapper = await mountSecret({}, undefined, realTotp)
		const display = wrapper.findComponent(TotpDisplay)
		for (let i = 0; i < 50 && display.vm.code === ''; i++) {
			await new Promise((resolve) => setTimeout(resolve, 5))
		}
		await wrapper.vm.$nextTick()

		expect(wrapper.find('[data-testid="totp-code"]').text()).toMatch(
			/^\d{3} \d{3}$/,
		)
		const html = wrapper.html()
		expect(html).not.toContain('GEZDGNBVGY3TQOJQ')
		expect(html).not.toContain('otpauth')
		expect(html).not.toContain('secret=')
	})
})

describe('copies that may not be changed', () => {
	it('a use-only copy shows the code and lists the passkeys, without Delete', async () => {
		const wrapper = await mountSecret(
			{ useOnly: true, ownerId: 'carol' },
			{ sourceSecretId: 'src', effectiveGrade: 'read' },
		)

		expect(has(wrapper, 'secret-detail-login-totp')).toBe(true)
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(has(wrapper, 'passkey-list-delete')).toBe(false)
		expect(wrapper.html()).not.toContain('GEZDGNBVGY3TQOJQ')
		expect(wrapper.html()).not.toContain('PRIVATE-KEY')
	})

	it('a view-only copy has no Delete', async () => {
		const wrapper = await mountSecret(
			{ ownerId: 'carol' },
			{ sourceSecretId: 'src', effectiveGrade: 'read' },
		)
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(has(wrapper, 'passkey-list-delete')).toBe(false)
	})

	it('a read-only copy from another organisation has no Delete', async () => {
		const wrapper = await mountSecret({ readOnly: true, ownerId: 'carol' }, null)
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(has(wrapper, 'passkey-list-delete')).toBe(false)
	})
})

/**
 * Delete the first listed passkey through its confirmation.
 *
 * @param {object} wrapper The sidebar.
 * @return {Promise<void>}
 */
async function deleteFirst(wrapper) {
	await wrapper.findAll('[data-testid="passkey-list-delete"]')[0].trigger('click')
	await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
	await flush()
	await flush()
}

describe('deleting a passkey', () => {
	it('is not offered when the server does not store the field', async () => {
		vi.mocked(valueFieldsSupported).mockReturnValue(false)
		const wrapper = await mountSecret()
		expect(wrapper.findAll('[data-testid="passkey-list-item"]')).toHaveLength(2)
		expect(has(wrapper, 'passkey-list-delete')).toBe(false)
	})

	it('on failure says so, frees the list and does not reload', async () => {
		const wrapper = await mountSecret()
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		vi.spyOn(store, 'updateSecret').mockRejectedValue(new Error('boom'))
		store.fetchSecrets = vi.fn().mockResolvedValue()

		await deleteFirst(wrapper)

		expect(showError).toHaveBeenCalledWith('Could not delete the passkey')
		expect(wrapper.vm.passkeySaving).toBe(false)
		expect(store.fetchSecret).toHaveBeenCalledTimes(1)
		expect(store.fetchSecrets).not.toHaveBeenCalled()
		expect(
			wrapper
				.find('[data-testid="passkey-list-delete"]')
				.attributes('disabled'),
		).toBeUndefined()
	})

	it('words an unreachable partner without offering to save without them', async () => {
		const wrapper = await mountSecret()
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		vi.spyOn(store, 'updateSecret').mockRejectedValue(
			Object.assign(new Error('unreachable'), {
				code: 'partner_unreachable',
				partners: [{ shareId: 'p1' }],
			}),
		)

		await deleteFirst(wrapper)

		const message = vi.mocked(showError).mock.calls[0][0]
		expect(message).toBe(
			'A partner organisation this secret is shared with cannot be reached, so the passkey was not deleted. Try again later.',
		)
		expect(message).not.toMatch(/without/)
	})

	it('moves focus to the list once the row is gone', async () => {
		const wrapper = await mountSecret()
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		vi.spyOn(store, 'updateSecret').mockResolvedValue({})
		store.fetchSecrets = vi.fn().mockResolvedValue()
		const remaining = await store.fetchSecret('s1')
		store.fetchSecret.mockResolvedValueOnce({
			...remaining,
			passkeys: JSON.stringify([BANK_APP]),
		})

		await deleteFirst(wrapper)

		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="passkey-list-count"]').element,
		)
	})

	it('moves focus to Edit once the last passkey is gone', async () => {
		const wrapper = await mountSecret({ passkeys: JSON.stringify([BANK]) })
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		vi.spyOn(store, 'updateSecret').mockResolvedValue({})
		store.fetchSecrets = vi.fn().mockResolvedValue()
		const current = await store.fetchSecret('s1')
		store.fetchSecret.mockResolvedValueOnce({ ...current, passkeys: '' })

		await deleteFirst(wrapper)

		expect(has(wrapper, 'secret-detail-login-passkeys')).toBe(false)
		expect(document.activeElement).toBe(
			wrapper.find('[data-testid="secret-detail-edit"]').element,
		)
	})

	it('saves the remaining passkeys with the base versions', async () => {
		const wrapper = await mountSecret()
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		const update = vi.spyOn(store, 'updateSecret').mockResolvedValue({})
		store.fetchSecrets = vi.fn().mockResolvedValue()

		await wrapper
			.findAll('[data-testid="passkey-list-delete"]')[0]
			.trigger('click')
		await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
		await flush()

		expect(update).toHaveBeenCalledWith(
			's1',
			{ passkeys: JSON.stringify([BANK_APP]) },
			{ baseUpdatedAt: BASE, sourceBaseUpdatedAt: null },
		)
		expect(store.fetchSecret).toHaveBeenCalledTimes(2)
	})

	it('on a copy with a write grade, saves from the source version it read', async () => {
		const wrapper = await mountSecret(
			{ ownerId: 'carol' },
			{
				sourceSecretId: 'src',
				effectiveGrade: 'write',
				sourceUpdatedAt: '2026-09-30T08:00:00+00:00',
			},
		)
		const store = useSecretStore()
		vi.spyOn(store, 'writeBackSettled').mockResolvedValue(null)
		const update = vi.spyOn(store, 'updateSecret').mockResolvedValue({})
		store.fetchSecrets = vi.fn().mockResolvedValue()

		await wrapper
			.findAll('[data-testid="passkey-list-delete"]')[1]
			.trigger('click')
		await wrapper.find('[data-testid="passkey-delete-confirm"]').trigger('click')
		await flush()

		expect(update.mock.calls[0][1]).toEqual({
			passkeys: JSON.stringify([BANK]),
		})
		expect(update.mock.calls[0][2].sourceBaseUpdatedAt).toBe(
			'2026-09-30T08:00:00+00:00',
		)
	})
})

describe('standalone Authenticator and Passkey items', () => {
	it('keep their own code and passkey rows', async () => {
		const totp = await mountSecret({
			typeId: 'type-totp',
			key: 'JBSWY3DPEHPK3PXP',
			totp: '',
			passkeys: '',
		})
		expect(
			totp.findComponent('[data-testid="secret-detail-totp"]').props('seed'),
		).toBe('JBSWY3DPEHPK3PXP')
		expect(has(totp, 'secret-detail-login-totp')).toBe(false)

		const passkey = await mountSecret({
			typeId: 'type-passkey',
			key: JSON.stringify(BANK),
			totp: '',
			passkeys: '',
		})
		expect(has(passkey, 'secret-detail-passkey')).toBe(true)
		expect(has(passkey, 'passkey-reveal')).toBe(true)
	})
})
