/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bulk share dialog's controls: the recipient and restriction fields,
 * closing, the recipient certificate lookup, the run, and retrying the
 * failed subset behind a fresh master password.
 *
 * @spec openspec/changes/bulk-actions/specs/bulk-actions/spec.md#requirement-bulk-share
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import BulkShareDialog from '../../src/dialogs/BulkShareDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useKeyProofPromptStore } from '../../src/store/modules/keyProofPrompt.js'

const STUBS = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		emits: ['update:open'],
		template: '<div class="dialog" :data-name="name"><button class="dialog-close" @click="$emit(\'update:open\', false)" /><slot /><slot name="actions" /></div>',
	},
	NcButton: {
		props: ['disabled', 'variant'],
		emits: ['click'],
		template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	RecipientPicker: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input class="recipient" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
	},
	ShareRestrictionFields: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		template: '<button class="restrict" @click="$emit(\'update:modelValue\', { useOnly: true, endDate: \'\' })" />',
	},
	BulkRunPanel: {
		emits: ['retry'],
		template: '<button class="retry" @click="$emit(\'retry\')" />',
	},
}

describe('BulkShareDialog controls', () => {
	let wrapper
	let bulk
	let prompt

	beforeEach(() => {
		setActivePinia(createPinia())
		bulk = useBulkStore()
		prompt = useKeyProofPromptStore()
		wrapper = mount(BulkShareDialog, { props: { open: true }, global: { stubs: STUBS } })
	})

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('takes the recipient and the restriction from their fields and closes from the dialog', async () => {
		await wrapper.find('input.recipient').setValue('bob')
		await wrapper.find('button.restrict').trigger('click')
		await wrapper.find('.dialog-close').trigger('click')

		expect(wrapper.vm.targetUserId).toBe('bob')
		expect(wrapper.vm.restriction).toEqual({ useOnly: true, endDate: '' })
		expect(wrapper.emitted('close')).toEqual([[]])
	})

	it('keeps Share disabled until a recipient is chosen', async () => {
		const share = wrapper.find('[data-testid="bulk-share-run"]')
		expect(share.attributes('disabled')).toBeDefined()

		await wrapper.find('input.recipient').setValue('bob')

		expect(wrapper.find('[data-testid="bulk-share-run"]').attributes('disabled')).toBeUndefined()
	})

	it('names a recipient without an active suite and never asks for the password', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 404 } })
		const ask = vi.spyOn(prompt, 'ask')
		await wrapper.find('input.recipient').setValue('bob')

		await wrapper.vm.onRun()
		await wrapper.vm.$nextTick()

		expect(axios.get).toHaveBeenCalledWith('/apps/keepiq/api/v1/shares/recipient-certificate', { params: { userId: 'bob' } })
		expect(wrapper.find('[data-testid="bulk-share-error"]').text()).toBe('Recipient has no active encryption suite')
		expect(ask).not.toHaveBeenCalled()
		expect(wrapper.vm.ran).toBe(false)
	})

	it('shows the server message for any other lookup failure', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { status: 500, data: { message: 'Suite store down' } } })

		await wrapper.vm.onRun()

		expect(wrapper.vm.error).toBe('Suite store down')
	})

	it('stops before running when the password prompt is cancelled', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { certificate: 'PEM' } })
		vi.spyOn(prompt, 'ask').mockRejectedValue(new Error('cancelled'))
		const run = vi.spyOn(bulk, 'run')

		await wrapper.vm.onRun()

		expect(wrapper.vm.certificate).toBe('PEM')
		expect(run).not.toHaveBeenCalled()
		expect(wrapper.vm.ran).toBe(false)
		expect(wrapper.emitted('done')).toBeUndefined()
	})

	it('runs over the selection, forgets the password and reports done', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(prompt, 'ask').mockResolvedValue('pw')
		const run = vi.spyOn(bulk, 'run').mockResolvedValue(undefined)

		await wrapper.vm.onRun()

		expect(wrapper.vm.certificate).toBe('')
		expect(run).toHaveBeenCalledWith(bulk.selectedIds, expect.any(Function), 'Sharing secrets')
		expect(wrapper.vm.runPassword).toBe('')
		expect(wrapper.vm.ran).toBe(true)
		expect(wrapper.emitted('done')).toEqual([[]])
	})

	it('retries the failed subset from the report panel behind a fresh password', async () => {
		wrapper.vm.ran = true
		vi.spyOn(prompt, 'ask').mockResolvedValue('pw')
		const retry = vi.spyOn(bulk, 'retryFailed').mockImplementation(async (perItem) => {
			expect(wrapper.vm.runPassword).toBe('pw')
			vi.spyOn(wrapper.vm, 'shareOne').mockResolvedValue({ status: 'ok' })
			await perItem('s-1')
		})
		await wrapper.vm.$nextTick()

		await wrapper.find('button.retry').trigger('click')
		await vi.waitFor(() => expect(wrapper.emitted('done')).toEqual([[]]))

		expect(retry).toHaveBeenCalledWith(expect.any(Function), 'Retrying share')
		expect(wrapper.vm.runPassword).toBe('')
	})

	it('does not retry when the password prompt is cancelled', async () => {
		vi.spyOn(prompt, 'ask').mockRejectedValue(new Error('cancelled'))
		const retry = vi.spyOn(bulk, 'retryFailed')

		await wrapper.vm.onRetry()

		expect(retry).not.toHaveBeenCalled()
		expect(wrapper.emitted('done')).toBeUndefined()
	})

	it('titles the report with the outcome once the run finished', async () => {
		wrapper.vm.ran = true
		bulk.report = [{ status: 'ok' }, { status: 'skipped' }]
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Shared {ok} of {total} secrets')
		expect(wrapper.vm.reportCounts).toEqual({ ok: 1, total: 2 })
		expect(wrapper.find('[data-testid="bulk-share-run"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="bulk-share-recipient"]').exists()).toBe(false)
	})
})
