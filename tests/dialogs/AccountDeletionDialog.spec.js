/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/dialogs/AccountDeletionDialog.vue`: the delete
 * button stays disabled until a master password and the exact confirmation
 * phrase are given, the export-first suggestion is passed up, a finished
 * deletion shows its report, and closing forgets everything typed.
 *
 * @spec openspec/specs/gdpr-compliance/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountDeletionDialog from '../../src/dialogs/AccountDeletionDialog.vue'
import { useExportStore } from '../../src/store/modules/export.js'

const stubs = {
	NcDialog: {
		props: ['open'],
		emits: ['update:open'],
		template: '<div><slot /><slot name="actions" /></div>',
	},
	NcNoteCard: { props: ['type'], template: '<div :class="`note--${type}`"><slot /></div>' },
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :data-variant="variant" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcPasswordField: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<input data-testid="password" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input data-testid="confirmation" :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

function mountDialog() {
	return mount(AccountDeletionDialog, {
		props: { open: true },
		global: {
			stubs,
			// The setup's global t() returns the raw template; this one fills it in.
			mixins: [{ methods: { t: (app, s, vars) => s.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m)) } }],
		},
	})
}

const deleteButton = (wrapper) => wrapper.find('[data-variant="error"]')

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe('AccountDeletionDialog', () => {
	it('asks for the exact confirmation phrase', () => {
		expect(mountDialog().find('[data-testid="confirmation"]').attributes('aria-label'))
			.toBe('Type "DELETE MY KEEPIQ DATA" to confirm')
	})

	it('enables delete only with a password and the exact phrase', async () => {
		const wrapper = mountDialog()
		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="password"]').setValue('pw')
		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="confirmation"]').setValue('delete my keepiq data')
		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()

		await wrapper.find('[data-testid="confirmation"]').setValue('DELETE MY KEEPIQ DATA')
		expect(deleteButton(wrapper).attributes('disabled')).toBeUndefined()

		await wrapper.find('[data-testid="password"]').setValue('')
		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('keeps delete disabled while the store is busy', async () => {
		const wrapper = mountDialog()
		wrapper.vm.masterPassword = 'pw'
		wrapper.vm.confirmation = 'DELETE MY KEEPIQ DATA'
		useExportStore().loading = true
		await wrapper.vm.$nextTick()

		expect(deleteButton(wrapper).attributes('disabled')).toBeDefined()
	})

	it('passes the export-first suggestion up', async () => {
		const wrapper = mountDialog()

		await wrapper.find('a').trigger('click')

		expect(wrapper.emitted('export-first')).toHaveLength(1)
	})

	it('shows the report with a close button and no delete button', async () => {
		const wrapper = mountDialog()
		wrapper.vm.report = {
			secretsDeleted: 4,
			secretsTransferred: 1,
			sharesDetached: 2,
			sharesRemoved: 3,
			suitesDeleted: 1,
		}
		await wrapper.vm.$nextTick()

		const items = wrapper.findAll('li').map((li) => li.text().replace(/\s+/g, ' '))
		expect(items).toEqual([
			'Secrets deleted: 4',
			'Secrets transferred to a delegate: 1',
			'Shares detached: 2',
			'Received shares removed: 3',
			'Encryption suites removed: 1',
		])
		expect(deleteButton(wrapper).exists()).toBe(false)
		expect(wrapper.find('button').text()).toBe('Close')
		expect(wrapper.find('[data-testid="password"]').exists()).toBe(false)
	})

	it('forgets everything typed when closed', async () => {
		const wrapper = mountDialog()
		Object.assign(wrapper.vm, {
			masterPassword: 'pw',
			confirmation: 'DELETE MY KEEPIQ DATA',
			report: { secretsDeleted: 1 },
			error: 'boom',
		})
		await wrapper.vm.$nextTick()

		await wrapper.find('button').trigger('click')

		expect({
			masterPassword: wrapper.vm.masterPassword,
			confirmation: wrapper.vm.confirmation,
			report: wrapper.vm.report,
			error: wrapper.vm.error,
		}).toEqual({ masterPassword: '', confirmation: '', report: null, error: null })
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})
})
