/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template wiring of four admin settings sections: each field and button
 * reaches its method with the value typed or picked, the dialogs close from
 * their own events, the icon slots render, and a failed load or save shows
 * its message. The stores are real Pinia stores with their server calls
 * doubled; axios is spied.
 *
 * @spec openspec/specs/admin-secret-types/spec.md#requirement-item-type-definitions
 * @spec openspec/specs/vault-backups/spec.md#requirement-administrator-schedules-vault-backups
 * @spec openspec/specs/rotation-expiry-policies/spec.md#requirement-expiry-policies-with-admin-default-and-user-override
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const errors = []
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => errors.push(m), showSuccess: vi.fn() }))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'ann' }),
	getRequestToken: () => 'token',
	onRequestTokenUpdate: () => {},
}))

const { default: ItemTypesSection } = await import('../../src/components/settings/ItemTypesSection.vue')
const { default: VaultBackupSection } = await import('../../src/components/settings/VaultBackupSection.vue')
const { default: ExpiryPoliciesSection } = await import('../../src/components/settings/ExpiryPoliciesSection.vue')
const { default: AdminAuditSection } = await import('../../src/components/settings/AdminAuditSection.vue')
const { useSecretTypeStore } = await import('../../src/store/modules/secretType.js')
const { useFolderStore } = await import('../../src/store/modules/folder.js')
const { useRotationStore } = await import('../../src/store/modules/rotation.js')
const { useAuditStore } = await import('../../src/store/modules/audit.js')

/** A field stand-in that reports the props it was given and emits a picked value. */
function Field (name) {
  return {
	name,
	props: ['modelValue', 'options', 'reduce', 'label', 'inputLabel'],
	emits: ['update:modelValue'],
	template: '<div :class="$options.name" />',
}
}

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { props: ['type'], template: '<div class="note"><slot /></div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>',
	},
	NcEmptyContent: { template: '<div v-bind="$attrs"><slot name="icon" /><slot /></div>' },
	NcLoadingIcon: { template: '<div class="loading" />' },
	NcSelect: Field('NcSelect'),
	NcTextField: Field('NcTextField'),
	Plus: { template: '<i class="icon-plus" />' },
	History: { template: '<i class="icon-history" />' },
	SecretTypeEditorDialog: { name: 'SecretTypeEditorDialog', props: ['open', 'type'], emits: ['update:open'], template: '<div class="editor" />' },
	SecretTypeDeleteDialog: { name: 'SecretTypeDeleteDialog', props: ['open', 'type'], emits: ['update:open'], template: '<div class="deleter" />' },
}

/**
 * Mount a section and let it load.
 *
 * @param {object} component The section.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const wrapper = mount(component, { global: { stubs } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.restoreAllMocks()
	errors.length = 0
	setActivePinia(createPinia())
})

describe('ItemTypesSection wiring', () => {
	const serverType = { id: 'type-server', name: 'server', label: 'Server', scope: 'global', fields: [] }

	beforeEach(() => {
		const store = useSecretTypeStore()
		store.types = [serverType]
		store.fetchTypes = vi.fn().mockResolvedValue()
	})

	it('shows the load failure and the new-type button with its icon', async () => {
		useSecretTypeStore().fetchTypes = vi.fn().mockRejectedValue(new Error('down'))
		const wrapper = await mountIt(ItemTypesSection)
		expect(wrapper.find('.note').text()).toBe('Could not load the item types.')
		expect(wrapper.find('[data-testid="item-types-new"] .icon-plus').exists()).toBe(true)
	})

	it('opens the editor and the delete confirmation for a row, each closing on its own event', async () => {
		const wrapper = await mountIt(ItemTypesSection)
		const [edit, remove] = wrapper.find('[data-testid="item-type-server"]').findAll('button')

		await edit.trigger('click')
		const editor = wrapper.findComponent({ name: 'SecretTypeEditorDialog' })
		expect(editor.props()).toEqual({ open: true, type: serverType })
		await editor.vm.$emit('update:open', true)
		expect(wrapper.findComponent({ name: 'SecretTypeEditorDialog' }).exists()).toBe(true)
		await editor.vm.$emit('update:open', false)
		expect(wrapper.findComponent({ name: 'SecretTypeEditorDialog' }).exists()).toBe(false)

		await remove.trigger('click')
		const deleter = wrapper.findComponent({ name: 'SecretTypeDeleteDialog' })
		expect(deleter.props('type')).toEqual(serverType)
		await deleter.vm.$emit('update:open', false)
		expect(wrapper.findComponent({ name: 'SecretTypeDeleteDialog' }).exists()).toBe(false)
	})

	it('closes the new-type editor from its event', async () => {
		const wrapper = await mountIt(ItemTypesSection)
		await wrapper.find('[data-testid="item-types-new"]').trigger('click')
		const editor = wrapper.findComponent({ name: 'SecretTypeEditorDialog' })
		expect(editor.props('type')).toBeUndefined()
		await editor.vm.$emit('update:open', false)
		expect(wrapper.findComponent({ name: 'SecretTypeEditorDialog' }).exists()).toBe(false)
	})
})

describe('VaultBackupSection wiring', () => {
	const settings = { backup_enabled: false, backup_interval_hours: 24, backup_retention_count: 7, backup_recipient_public_key: '' }

	it('shows why the settings could not be read', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Admins only' } } })
		const wrapper = await mountIt(VaultBackupSection)
		expect(wrapper.find('[data-testid="vault-backup-error"]').text()).toBe('Admins only')
	})

	it('saves each field as it changes, with the typed values', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings, status: {}, archives: [] } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountIt(VaultBackupSection)

		await wrapper.find('[data-testid="vault-backup-enabled"]').setValue(true)
		await wrapper.find('[data-testid="vault-backup-interval"]').setValue('6')
		await wrapper.find('[data-testid="vault-backup-retention"]').setValue('3')
		await wrapper.find('[data-testid="vault-backup-public-key"]').setValue('  PEM  ')
		await flushPromises()

		expect(put).toHaveBeenCalledTimes(4)
		expect(put).toHaveBeenLastCalledWith('/apps/keepiq/api/settings/admin/backups', {
			backup_enabled: true,
			backup_interval_hours: 6,
			backup_retention_count: 3,
			backup_recipient_public_key: 'PEM',
		})
	})

	it('shows why a backup could not be requested', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { settings, status: {}, archives: [] } })
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('Cron is off'))
		const wrapper = await mountIt(VaultBackupSection)
		await wrapper.vm.runNow()
		expect(wrapper.vm.requested).toBe(false)
		expect(wrapper.find('[data-testid="vault-backup-error"]').text()).toBe('Cron is off')
	})
})

describe('ExpiryPoliciesSection wiring', () => {
	beforeEach(() => {
		const types = useSecretTypeStore()
		types.types = [{ id: 'type-login', name: 'login', label: 'Login' }]
		const folders = useFolderStore()
		folders.folders = [{ id: 'f-ops', name: 'Ops' }]
		const rotation = useRotationStore()
		rotation.fetchPolicies = vi.fn().mockResolvedValue()
		rotation.policies = [{ id: 'p1', ownerId: 'ann', scope: 'type', scopeId: 'type-login', maxAgeDays: 90 }]
	})

	it('fills the draft from the fields, reducing each option to its value, and saves it', async () => {
		const rotation = useRotationStore()
		rotation.upsertPolicy = vi.fn().mockResolvedValue()
		const wrapper = await mountIt(ExpiryPoliciesSection)
		const [scope, target] = wrapper.findAllComponents({ name: 'NcSelect' })
		const [maxAge, reminders] = wrapper.findAllComponents({ name: 'NcTextField' })
		expect(scope.props('reduce')({ value: 'folder', label: 'A folder' })).toBe('folder')
		expect(target.props('reduce')({ value: 'f-ops', label: 'Ops' })).toBe('f-ops')

		await scope.vm.$emit('update:modelValue', 'folder')
		expect(target.props('options')).toEqual([{ value: 'f-ops', label: 'Ops' }])
		await target.vm.$emit('update:modelValue', 'f-ops')
		await maxAge.vm.$emit('update:modelValue', '30')
		await reminders.vm.$emit('update:modelValue', '3, 14')
		await wrapper.find('[data-testid="expiry-policy-save"]').trigger('click')
		await flushPromises()

		expect(rotation.upsertPolicy).toHaveBeenCalledWith({ scope: 'folder', scopeId: 'f-ops', maxAgeDays: 30, reminderDays: [14, 3] })
		expect(wrapper.vm.draft).toEqual({ scope: 'folder', scopeId: null, maxAgeDays: '', reminderDays: '' })
	})

	it('says so when a rule cannot be saved or deleted', async () => {
		const rotation = useRotationStore()
		rotation.upsertPolicy = vi.fn().mockRejectedValue(new Error('down'))
		rotation.deletePolicy = vi.fn().mockRejectedValue(new Error('down'))
		const wrapper = await mountIt(ExpiryPoliciesSection)
		wrapper.vm.draft.scopeId = 'type-login'
		wrapper.vm.draft.maxAgeDays = '10'
		await wrapper.vm.onSave()
		expect(wrapper.vm.saving).toBe(false)
		expect(wrapper.vm.draft.scopeId).toBe('type-login')
		await wrapper.find('[data-testid="expiry-policy-delete"]').trigger('click')
		await flushPromises()
		expect(rotation.deletePolicy).toHaveBeenCalledWith('p1')
		expect(errors).toEqual(['Could not save the expiry rule.', 'Could not delete the expiry rule.'])
	})
})

describe('AdminAuditSection wiring', () => {
	beforeEach(() => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { audit_retention_days: 90 } })
		const store = useAuditStore()
		store.fetchAdminAudit = vi.fn().mockResolvedValue()
		store.applyAdminFilters = vi.fn().mockResolvedValue()
	})

	it('shows the empty state with its icon', async () => {
		const wrapper = await mountIt(AdminAuditSection)
		expect(wrapper.find('[data-testid="audit-empty"] .icon-history').exists()).toBe(true)
	})

	it('reloads with every filter as it is picked or typed', async () => {
		const store = useAuditStore()
		const wrapper = await mountIt(AdminAuditSection)
		const option = wrapper.findComponent({ name: 'NcSelect' }).props('options')[0]
		await wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', option)
		await wrapper.find('[data-testid="audit-filter-actor"]').setValue('bob')
		await wrapper.find('[data-testid="audit-filter-from"]').setValue('2026-01-01')
		await wrapper.find('[data-testid="audit-filter-to"]').setValue('2026-02-01')
		await flushPromises()

		expect(store.applyAdminFilters).toHaveBeenCalledTimes(4)
		expect(store.applyAdminFilters).toHaveBeenLastCalledWith({ eventType: option.id, actor: 'bob', from: '2026-01-01', to: '2026-02-01' })
	})
})
