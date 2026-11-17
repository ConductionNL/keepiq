/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What `src/App.vue` renders into CnAppRoot's slots (the user-settings
 * dialog body, the menu and the detail sidebar), plus the store
 * passthroughs, the Integrations header-action handler and the parts of
 * created() the other shell specs leave open.
 *
 * The shell is mounted shallowly with created() replaced, so no store
 * initialises over HTTP; CnAppRoot is stubbed to render its named slots.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-session-mechanism
 * @spec openspec/specs/vault-session-lock/spec.md#requirement-saved-session-timeout
 * @spec openspec/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-004-an-admin-reads-the-connections-on-an-integrations-page
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../src/App.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useOfflineStore } from '../../src/store/modules/offline.js'
import { useSessionStore } from '../../src/store/modules/session.js'

const initializeStores = vi.fn()

vi.mock('../../src/store/store.js', () => ({
	initializeStores: (...args) => initializeStores(...args),
}))

const CnAppRootStub = {
	name: 'CnAppRoot',
	template: '<div class="cn-app-root"><slot name="menu" /><slot name="sidebar" /><slot name="user-settings" /></div>',
}

const NcAppSettingsSectionStub = {
	name: 'NcAppSettingsSection',
	props: ['id', 'name'],
	template: '<section :data-section="id"><h3>{{ name }}</h3><slot name="icon" /><slot /></section>',
}

const NcButtonStub = {
	name: 'NcButton',
	props: ['variant', 'disabled'],
	emits: ['click'],
	template: '<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
}

const NcNoteCardStub = {
	name: 'NcNoteCard',
	props: ['type'],
	template: '<div :data-note="type"><slot /></div>',
}

/**
 * Mount the shell with created() switched off.
 *
 * @param {object} state Data to set after mount.
 * @param {string} secretId Route param that opens the detail sidebar.
 * @return {Promise<import('@vue/test-utils').VueWrapper>} The wrapper.
 */
async function mountShell(state = {}, secretId = undefined) {
	const wrapper = mount({ ...App, created() {} }, {
		shallow: true,
		global: {
			stubs: {
				CnAppRoot: CnAppRootStub,
				NcAppSettingsSection: NcAppSettingsSectionStub,
				NcButton: NcButtonStub,
				NcNoteCard: NcNoteCardStub,
			},
			mocks: {
				$route: { name: 'SecretList', params: secretId ? { id: secretId } : {}, query: {}, path: '/secrets' },
				$router: { push: vi.fn(), replace: vi.fn() },
			},
		},
	})
	await wrapper.setData(state)
	return wrapper
}

describe('App.vue user-settings render', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		window.history.replaceState(null, '', '/apps/keepiq/')
	})

	it('renders every settings section into the dialog slot', async () => {
		const wrapper = await mountShell()

		const ids = wrapper.findAll('section').map((s) => s.attributes('data-section'))
		expect(ids).toEqual(['session', 'defaults', 'expiry-rules', 'other-organisations', 'security', 'encryption'])
		expect(wrapper.find('[data-section="session"] h3').text()).toBe('Session')
	})

	it('shows the compromise form only after the button is pressed, and hides it again', async () => {
		const wrapper = await mountShell()
		const button = wrapper.findAll('[data-section="security"] button').find((b) => b.text() === 'My master password was compromised')

		expect(wrapper.findComponent({ name: 'CompromiseRecoveryForm' }).exists()).toBe(false)
		await button.trigger('click')
		expect(wrapper.findComponent({ name: 'CompromiseRecoveryForm' }).exists()).toBe(true)
		await button.trigger('click')
		expect(wrapper.findComponent({ name: 'CompromiseRecoveryForm' }).exists()).toBe(false)
	})

	it('shows the empty state when there is no encryption suite', async () => {
		const wrapper = await mountShell()

		expect(wrapper.find('.user-settings__suite-info').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe('No encryption suite')
	})

	it('shows the active suite and opens the revoke confirmation from its button', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 'suite-1', status: 'active', createdAt: '2026-01-02' }
		const wrapper = await mountShell()
		const info = wrapper.find('.user-settings__suite-info')

		expect(info.text()).toContain('active')
		expect(info.text()).toContain('2026-01-02')
		expect(info.text()).toContain('suite-1')
		expect(wrapper.findComponent({ name: 'VaultEncryptionDetails' }).props('suiteId')).toBe('suite-1')
		expect(wrapper.find('[data-testid="revoke-confirm"]').exists()).toBe(false)

		await info.findAll('button').find((b) => b.text() === 'Revoke encryption suite').trigger('click')

		expect(wrapper.vm.revokeConfirm).toBe(true)
		expect(wrapper.find('[data-note="warning"]').text()).toContain('Revoking your encryption suite')
	})

	it('keeps confirm disabled until a reason and a password are given', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 's', status: 'active', createdAt: '' }
		const wrapper = await mountShell({ revokeConfirm: true })

		expect(wrapper.find('[data-testid="revoke-confirm"]').attributes('disabled')).toBeDefined()
		await wrapper.setData({ revokeReason: 'lost', revokePassword: 'pw' })
		expect(wrapper.find('[data-testid="revoke-confirm"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-testid="revoke-confirm"]').text()).toBe('Confirm revocation')
		await wrapper.setData({ revoking: true })
		expect(wrapper.find('[data-testid="revoke-confirm"]').text()).toBe('Revoking…')
		expect(wrapper.find('[data-testid="revoke-confirm"]').attributes('disabled')).toBeDefined()
	})

	it('names the emergency-access loss and revokes with it accepted', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 's', status: 'active', createdAt: '' }
		const wrapper = await mountShell({ revokeConfirm: true, revokeReason: 'lost', revokePassword: 'pw', revokeEmergencyCount: 2 })
		const handleRevoke = vi.spyOn(wrapper.vm, 'handleRevoke').mockResolvedValue(undefined)

		expect(wrapper.find('[data-testid="revoke-emergency-warning"]').text()).toContain('contacts')
		const confirm = wrapper.find('[data-testid="revoke-confirm"]')
		expect(confirm.text()).toBe('Revoke and delete emergency access')
		await confirm.trigger('click')
		expect(handleRevoke).toHaveBeenCalledWith(true)
	})

	it('closes the confirmation on cancel', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 's', status: 'active', createdAt: '' }
		const wrapper = await mountShell({ revokeConfirm: true, revokeReason: 'lost', revokePassword: 'pw' })

		await wrapper.findAll('button').find((b) => b.text() === 'Cancel').trigger('click')

		expect(wrapper.vm.revokeConfirm).toBe(false)
		expect(wrapper.find('[data-testid="revoke-confirm"]').exists()).toBe(false)
	})

	it('offers no revocation for a suite that is not active', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 's', status: 'revoked', createdAt: '' }
		const wrapper = await mountShell()

		expect(wrapper.findAll('button').some((b) => b.text() === 'Revoke encryption suite')).toBe(false)
	})

	it('shows the revocation outcome notes', async () => {
		useEncryptionSuiteStore().currentSuite = { id: 's', status: 'revoked', createdAt: '' }
		const wrapper = await mountShell({ revokeSuccess: true, revokeError: 'refused' })

		expect(wrapper.find('[data-note="success"]').text()).toContain('Encryption suite revoked')
		expect(wrapper.find('[data-note="error"]').text()).toBe('refused')
	})

	it('saves the timeout picked in the session section', async () => {
		const wrapper = await mountShell()
		const save = vi.spyOn(useSessionStore(), 'saveTimeoutPreference').mockResolvedValue(undefined)
		expect(wrapper.find('[data-testid="session-timeout-select"]').exists()).toBe(true)
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', '30min')

		expect(save).toHaveBeenCalledWith('30min')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('reduce')({ value: '10min', label: 'x' })).toBe('10min')
	})

	it('renders the detail sidebar for the routed secret and closes it on its event', async () => {
		const wrapper = await mountShell({}, 'abc')

		const sidebar = wrapper.findComponent({ name: 'SecretDetailSidebar' })
		expect(sidebar.props('secretId')).toBe('abc')
		sidebar.vm.$emit('close')
		expect(wrapper.vm.$router.push).toHaveBeenCalledWith({ name: 'SecretList' })
	})

	it('renders the app nav into the menu slot with the manifest', async () => {
		const wrapper = await mountShell()

		expect(wrapper.findComponent({ name: 'KeepiqAppNav' }).props('manifest')).toBe(wrapper.vm.manifest)
	})
})

describe('App.vue store passthroughs and integrations handler', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('returns the active pinia stores', () => {
		expect(App.computed.sessionStore()).toBe(useSessionStore())
		expect(App.computed.suiteStore()).toBe(useEncryptionSuiteStore())
		expect(App.computed.offlineStore()).toBe(useOfflineStore())
	})

	it('keeps the page components and adds the Integrations header action', () => {
		const page = { name: 'Page' }

		const components = App.computed.shellCustomComponents.call({ customComponents: { Page: page } })

		expect(Object.keys(components)).toEqual(['Page', 'openIntegriqConnections'])
		expect(components.Page).toBe(page)
		expect(typeof components.openIntegriqConnections).toBe('function')
	})
})

describe('App.vue created() timers and sync', () => {
	let context

	beforeEach(() => {
		vi.useFakeTimers()
		initializeStores.mockReset().mockResolvedValue(undefined)
		context = {
			sessionStore: { loadTimeoutPreference: vi.fn(), checkTimeout: vi.fn(), isLocked: false },
			offlineStore: { bindConnectivity: vi.fn(), ensureLockHook: vi.fn(), online: true, syncNow: vi.fn() },
			registerServiceWorker: vi.fn(),
			handleActivity: () => {},
			handleVisibilityChange: () => {},
			handleBeforeUnload: () => {},
		}
	})

	afterEach(() => {
		App.beforeUnmount.call(context)
		vi.useRealTimers()
	})

	it('warms the snapshot when unlocked and online, swallowing a failed sync', async () => {
		context.offlineStore.syncNow.mockRejectedValue(new Error('offline'))

		await expect(App.created.call(context)).resolves.toBeUndefined()
		await Promise.resolve()

		expect(context.storesReady).toBe(true)
		expect(context.offlineStore.syncNow).toHaveBeenCalledTimes(1)
	})

	it('does not sync while the vault is locked', async () => {
		context.sessionStore.isLocked = true

		await App.created.call(context)

		expect(context.offlineStore.syncNow).not.toHaveBeenCalled()
	})

	it('checks the session timeout every ten seconds', async () => {
		context.offlineStore.online = false

		await App.created.call(context)
		expect(context.sessionStore.checkTimeout).not.toHaveBeenCalled()
		vi.advanceTimersByTime(9999)
		expect(context.sessionStore.checkTimeout).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(context.sessionStore.checkTimeout).toHaveBeenCalledTimes(1)
		vi.advanceTimersByTime(20000)
		expect(context.sessionStore.checkTimeout).toHaveBeenCalledTimes(3)
	})
})
