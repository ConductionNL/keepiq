/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The privileged login panel: the owner marks the login and answers a
 * request, narrowed to address ranges; a recipient asks for a window with a
 * reason; a server refusal is shown.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/specs/user-sharing/spec.md#requirement-use-of-a-privileged-login-needs-an-approved-time-window
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import PrivilegedAccessPanel from '../../src/components/PrivilegedAccessPanel.vue'

const stubs = {
	NcNoteCard: { props: ['type'], template: '<div class="note" v-bind="$attrs"><slot /></div>' },
	NcButton: {
		props: ['disabled', 'type', 'variant'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :type="type || \'button\'" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<button v-bind="$attrs" :aria-pressed="String(modelValue)" @click="$emit(\'update:modelValue\', !modelValue)"><slot /></button>',
	},
	NcTextField: {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input v-bind="$attrs" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
	},
}

const pending = {
	id: 'r1',
	requesterId: 'bob',
	reason: 'incident 4711',
	status: 'pending',
	requestedStart: '2026-11-16T14:00:00+00:00',
	requestedEnd: '2026-11-16T16:00:00+00:00',
	approvedStart: null,
	approvedEnd: null,
	ranges: [],
}

async function mountPanel(status) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: status })
	const wrapper = mount(PrivilegedAccessPanel, { props: { secretId: 's1' }, global: { stubs } })
	await flushPromises()
	return wrapper
}

const find = (wrapper, testid) => wrapper.find(`[data-testid="${testid}"]`)

describe('PrivilegedAccessPanel', () => {
	afterEach(() => vi.restoreAllMocks())

	it('lets the owner mark the login', async () => {
		const wrapper = await mountPanel({ role: 'owner', privileged: false, requests: [] })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: { privileged: true } })

		await find(wrapper, 'privileged-toggle').trigger('click')
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith(expect.stringContaining('/apps/keepiq/api/v1/secrets/s1/privileged'))
		expect(put).toHaveBeenCalledWith(expect.stringContaining('/secrets/s1/privileged'), { privileged: true })
		expect(find(wrapper, 'privileged-toggle').attributes('aria-pressed')).toBe('true')
	})

	it('lets the owner approve a request for an address range', async () => {
		const wrapper = await mountPanel({ role: 'owner', privileged: true, requests: [pending] })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		expect(find(wrapper, 'privileged-request-r1').text()).toContain('bob')
		expect(find(wrapper, 'privileged-request-r1').text()).toContain('incident 4711')
		await find(wrapper, 'privileged-approve-r1').trigger('click')
		await find(wrapper, 'privileged-approve-ranges-r1').setValue('192.0.2.0/24')
		await find(wrapper, 'privileged-approve-confirm-r1').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/access-requests/r1/approve'), {
			startsAt: '',
			endsAt: '',
			ranges: ['192.0.2.0/24'],
		})
		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('lets the owner deny a request', async () => {
		const wrapper = await mountPanel({ role: 'owner', privileged: true, requests: [pending] })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		await find(wrapper, 'privileged-deny-r1').trigger('click')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/access-requests/r1/deny'))
	})

	it('offers the owner to rotate after a window ended', async () => {
		const ended = { ...pending, status: 'approved', approvedStart: '2020-01-01T10:00:00Z', approvedEnd: '2020-01-01T12:00:00Z' }
		const wrapper = await mountPanel({ role: 'owner', privileged: true, requests: [ended] })

		expect(find(wrapper, 'privileged-rotate').exists()).toBe(true)
		expect(find(wrapper, 'privileged-state-r1').text()).toBe('Ended')
	})

	it('lets a recipient ask for a window with a reason', async () => {
		const wrapper = await mountPanel({ role: 'recipient', privileged: true, requests: [] })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })

		expect(find(wrapper, 'privileged-request-submit').attributes('disabled')).toBeDefined()
		await find(wrapper, 'privileged-request-start').setValue('2026-11-16T14:00')
		await find(wrapper, 'privileged-request-end').setValue('2026-11-16T16:00')
		await find(wrapper, 'privileged-request-reason').setValue(' incident 4711 ')
		await find(wrapper, 'privileged-request-form').trigger('submit')
		await flushPromises()

		expect(post).toHaveBeenCalledWith(expect.stringContaining('/secrets/s1/access-requests'), {
			startsAt: new Date('2026-11-16T14:00').toISOString(),
			endsAt: new Date('2026-11-16T16:00').toISOString(),
			reason: 'incident 4711',
		})
		expect(find(wrapper, 'privileged-toggle').exists()).toBe(false)
	})

	it('tells a recipient when no approval is needed', async () => {
		const wrapper = await mountPanel({ role: 'recipient', privileged: false, requests: [] })

		expect(find(wrapper, 'privileged-not-needed').exists()).toBe(true)
		expect(find(wrapper, 'privileged-request-form').exists()).toBe(false)
	})

	it('shows the server refusal', async () => {
		const wrapper = await mountPanel({ role: 'recipient', privileged: true, requests: [] })
		vi.spyOn(axios, 'post').mockRejectedValue({ response: { data: { message: 'The window must end in the future' } } })

		await find(wrapper, 'privileged-request-start').setValue('2020-01-01T10:00')
		await find(wrapper, 'privileged-request-end').setValue('2020-01-01T12:00')
		await find(wrapper, 'privileged-request-reason').setValue('x')
		await find(wrapper, 'privileged-request-form').trigger('submit')
		await flushPromises()

		expect(find(wrapper, 'privileged-error').text()).toBe('The window must end in the future')
	})
})
