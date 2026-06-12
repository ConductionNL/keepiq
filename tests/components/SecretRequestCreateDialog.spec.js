/**
 * SPDX-FileCopyrightText: 2026 Conduction / Doriath Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Component test for `src/components/secretRequest/SecretRequestCreateDialog.vue`.
 *
 * What these lock down:
 *  - the available-fields list grows from the secret's `additional_fields_keys`
 *    (the key/login defaults are always present);
 *  - submitting the form delegates to `useSecretRequestStore().createRequest`
 *    with the selected `requested_fields` + ISO `expires_at`;
 *  - the re-request mode dispatches the `createReRequest` action instead and
 *    forwards the secret's encryption_suite_id;
 *  - on success the dialog populates a `fillUrl` from the returned token and
 *    emits a `created` event with the store's response;
 *  - copyUrl() writes the fill URL to the clipboard and flips `copied`;
 *  - submit failures surface in the `.secret-request-create-dialog__error`
 *    pane and clear the `submitting` flag.
 *
 * @spec openspec/changes/implement-secret-requests/tasks.md#13.3
 */

import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

import SecretRequestCreateDialog from '../../src/components/secretRequest/SecretRequestCreateDialog.vue'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'

const ncStubs = {
	NcDialog: {
		props: ['name', 'open', 'size'],
		template: '<div class="nc-dialog-stub"><slot /><slot name="actions" /></div>',
	},
}

describe('SecretRequestCreateDialog', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		Object.defineProperty(global.navigator, 'clipboard', {
			value: { writeText: vi.fn().mockResolvedValue() },
			configurable: true,
		})
	})

	it('renders the default key + login fields plus any additional_fields_keys', () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: {
					id: 'secret-1',
					additional_fields_keys: ['totp', 'pin'],
				},
			},
			stubs: ncStubs,
		})

		const fields = wrapper.vm.availableFields.map(f => f.key)
		expect(fields).toEqual(['key', 'login', 'totp', 'pin'])
	})

	it('submit(): delegates to createRequest with selected fields + ISO expiry', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockResolvedValue({
			id: 'req-1',
			token: 'tok-abc',
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			stubs: ncStubs,
		})

		wrapper.vm.requestedFields = ['key', 'login']
		wrapper.vm.expiresAt = '2026-12-31T23:59'

		await wrapper.vm.submit()

		expect(store.createRequest).toHaveBeenCalledTimes(1)
		const payload = store.createRequest.mock.calls[0][0]
		expect(payload).toMatchObject({
			secret_id: 'secret-1',
			requested_fields: ['key', 'login'],
			is_re_request: false,
		})
		expect(typeof payload.expires_at).toBe('string')
		expect(payload.expires_at.endsWith('Z')).toBe(true)

		// fillUrl is populated from the response token.
		expect(wrapper.vm.fillUrl).toContain('/apps/doriath/share/request/tok-abc')

		// `created` event is emitted with the store response.
		const events = wrapper.emitted('created')
		expect(events).toBeTruthy()
		expect(events[0][0]).toMatchObject({ id: 'req-1', token: 'tok-abc' })

		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.error).toBe('')
	})

	it('submit() in re-request mode calls createReRequest with the suite ID', async () => {
		const store = useSecretRequestStore()
		store.createReRequest = vi.fn().mockResolvedValue({
			id: 'req-2',
			token: 'tok-rerequest',
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				isReRequest: true,
				secret: {
					id: 'secret-1',
					encryption_suite_id: 'suite-9',
				},
			},
			stubs: ncStubs,
		})

		wrapper.vm.requestedFields = ['key']
		wrapper.vm.expiresAt = ''

		await wrapper.vm.submit()

		expect(store.createReRequest).toHaveBeenCalledWith(
			'secret-1',
			'suite-9',
			['key'],
			null, // no expiry → null (not empty string)
		)
		expect(wrapper.vm.fillUrl).toContain('/apps/doriath/share/request/tok-rerequest')
	})

	it('copyUrl(): writes the fillUrl to the clipboard and flips the copied flag', async () => {
		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			stubs: ncStubs,
		})

		wrapper.vm.fillUrl = 'http://nc.test/apps/doriath/share/request/tok-1'
		await wrapper.vm.copyUrl()

		expect(navigator.clipboard.writeText).toHaveBeenCalledWith(
			'http://nc.test/apps/doriath/share/request/tok-1',
		)
		expect(wrapper.vm.copied).toBe(true)
	})

	it('submit() surfaces server errors and clears the submitting flag', async () => {
		const store = useSecretRequestStore()
		store.createRequest = vi.fn().mockRejectedValue({
			response: { data: { message: 'Pending request already exists' } },
		})

		const wrapper = mount(SecretRequestCreateDialog, {
			propsData: {
				open: true,
				secret: { id: 'secret-1' },
			},
			stubs: ncStubs,
		})

		await wrapper.vm.submit()

		expect(wrapper.vm.error).toBe('Pending request already exists')
		expect(wrapper.vm.submitting).toBe(false)
		expect(wrapper.vm.fillUrl).toBe('')
		expect(wrapper.emitted('created')).toBeFalsy()
	})
})
