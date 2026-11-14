/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The migration banner as a user drives it: the Resume button opens the two
 * forms, submitting them resumes or aborts with the typed password, a running
 * resume shows its progress, and a refused abort says why.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MigrationResumeBanner from '../../src/components/MigrationResumeBanner.vue'
import { useEncryptionSuiteStore } from '../../src/store/modules/encryptionSuite.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('@nextcloud/dialogs', () => ({
	showWarning: vi.fn(),
	TOAST_PERMANENT_TIMEOUT: -1,
}))

/**
 * Mount with the Nextcloud surface stubbed and an interpolating t/n.
 *
 * @return {object} The mounted wrapper.
 */
function mountBanner() {
	return mount(MigrationResumeBanner, {
		global: {
			mixins: [
				{
					methods: {
						t: (app, text, vars) =>
							vars
								? Object.keys(vars).reduce((out, k) => out.replace(`{${k}}`, String(vars[k])), text)
								: text,
						n: (app, singular, plural, count) =>
							(count === 1 ? singular : plural).replace('%n', String(count)),
					},
				},
			],
			stubs: {
				NcButton: { template: '<button><slot /></button>' },
				NcPasswordField: {
					props: ['label', 'modelValue'],
					emits: ['update:modelValue'],
					template:
						'<label><span>{{ label }}</span><input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
				},
				AlertOutline: true,
			},
		},
	})
}

describe('MigrationResumeBanner flow', () => {
	let store

	beforeEach(() => {
		setActivePinia(createPinia())
		vi.restoreAllMocks()
		store = useEncryptionSuiteStore()
		vi.spyOn(store, 'fetchMigrationStatus').mockResolvedValue(undefined)
		vi.spyOn(store, 'fetchMigrationRemaining').mockResolvedValue(null)
		store.migrationStatus = { id: 'migration-1' }
		useSessionStore().cryptoKey = {}
	})

	it('opens both forms from the Resume button, which then goes away', async () => {
		const wrapper = mountBanner()
		await wrapper.vm.$nextTick()
		expect(wrapper.findAll('form')).toHaveLength(0)

		await wrapper.find('[data-testid="migration-resume-open"]').trigger('click')

		expect(wrapper.findAll('form')).toHaveLength(2)
		expect(wrapper.find('[data-testid="migration-resume-open"]').exists()).toBe(false)
	})

	it('resumes with the password typed into the resume form', async () => {
		const resume = vi.spyOn(store, 'resumeMigration').mockResolvedValue({ migrated: 3, failed: 0 })
		const wrapper = mountBanner()
		await wrapper.find('[data-testid="migration-resume-open"]').trigger('click')

		const [resumeForm] = wrapper.findAll('form')
		await resumeForm.find('input').setValue('previous-master-password')
		await resumeForm.trigger('submit')
		await wrapper.vm.$nextTick()

		expect(resume).toHaveBeenCalledWith('previous-master-password')
		expect(store.fetchMigrationRemaining).toHaveBeenCalled()
		expect(wrapper.vm.oldPassword).toBe('')
	})

	it('aborts with the password typed into the abort form', async () => {
		const abort = vi.spyOn(store, 'abortMigration').mockResolvedValue({ aborted: true })
		const wrapper = mountBanner()
		await wrapper.find('[data-testid="migration-resume-open"]').trigger('click')

		const abortForm = wrapper.findAll('form')[1]
		await abortForm.find('input').setValue('current-master-password')
		await abortForm.trigger('submit')
		await wrapper.vm.$nextTick()

		expect(abort).toHaveBeenCalledWith('current-master-password')
		expect(wrapper.vm.abortPassword).toBe('')
	})

	it.each([
		[{ response: { data: { message: 'Records already moved; resume instead.' } } }, 'Records already moved; resume instead.'],
		[new Error('Network down'), 'Network down'],
		[{}, 'Could not abort the rotation.'],
	])('says why an abort was refused (%#)', async (failure, message) => {
		vi.spyOn(store, 'abortMigration').mockRejectedValue(failure)
		const wrapper = mountBanner()
		wrapper.vm.expanded = true
		wrapper.vm.abortPassword = 'current-master-password'

		await wrapper.vm.onAbort()
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.keepiq-migration-banner__error').text()).toBe(message)
		expect(wrapper.vm.abortPassword).toBe('')
		expect(wrapper.vm.busy).toBe(false)
		expect(wrapper.find('[data-testid="migration-resume-banner"]').exists()).toBe(true)
	})

	it('shows the progress of a running resume, never fewer in total than done', async () => {
		const wrapper = mountBanner()
		store.migrationProgress = { done: 12, total: 10 }
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).not.toContain('records re-encrypted')

		wrapper.vm.busy = true
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).toContain('12 of 12 records re-encrypted')

		store.migrationProgress = { done: 0, total: 0 }
		await wrapper.vm.$nextTick()
		expect(wrapper.text()).not.toContain('records re-encrypted')
	})
})
