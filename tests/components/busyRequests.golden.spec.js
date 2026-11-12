/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for the admin requests that show a busy state: while the request
 * runs the section is busy with no error (and, where it has one, no notice
 * or summary); afterwards it is not busy, and a refusal or failure shows the
 * server's message, else the error's own, and keeps the state.
 *
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-forwarded-payload-carries-no-secret-material
 */

import axios from '@nextcloud/axios'
import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ComplianceSection from '../../src/components/settings/ComplianceSection.vue'
import OffboardingSection from '../../src/components/settings/OffboardingSection.vue'
import SiemSection from '../../src/components/settings/SiemSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const REFUSED = { response: { status: 403, data: { message: 'Administrators only' } } }
const SINK = { id: 's1', name: 'Syslog', type: 'syslog', format: 'cef' }

/**
 * @param {object} component The section.
 * @param {*} listed What every GET answers.
 * @return {Promise<object>} The mounted section with a stale error.
 */
async function mountSection(component, listed) {
	vi.spyOn(axios, 'get').mockResolvedValue({ data: listed })
	const wrapper = shallowMount(component)
	await flush()
	wrapper.vm.error = 'stale'
	return wrapper
}

/**
 * Record the section's state at the moment a request goes out.
 *
 * @param {object} wrapper The mounted section.
 * @param {Array<string>} keys The state to record.
 * @return {object} The record, filled during the request.
 */
function during(wrapper, keys) {
	const seen = {}
	seen.capture = () => {
		for (const key of keys) {
			seen[key] = wrapper.vm[key]
		}
	}
	return seen
}

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('SIEM test-fire', () => {
	it('is busy with no error or notice while it runs, then reports delivery', async () => {
		const wrapper = await mountSection(SiemSection, [SINK])
		wrapper.vm.notice = 'stale'
		const seen = during(wrapper, ['busy', 'error', 'notice'])
		const post = vi.spyOn(axios, 'post').mockImplementation(async () => {
			seen.capture()
			return { data: { ok: true } }
		})
		await wrapper.vm.onTest(SINK)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/siem/sinks/s1/test')
		expect(seen).toMatchObject({ busy: true, error: null, notice: null })
		expect(wrapper.vm.notice).toBe('Test event delivered to "{name}".')
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reports an undelivered test with the reason, or an unknown error', async () => {
		const wrapper = await mountSection(SiemSection, [SINK])
		vi.spyOn(axios, 'post')
			.mockResolvedValueOnce({ data: { ok: false, error: 'TLS handshake' } })
			.mockResolvedValueOnce({ data: { ok: false } })
		await wrapper.vm.onTest(SINK)
		expect(wrapper.vm.error).toBe('Test delivery to "{name}" failed: {error}')
		await wrapper.vm.onTest(SINK)
		expect(wrapper.vm.error).toBe('Test delivery to "{name}" failed: {error}')
		expect(wrapper.vm.notice).toBe(null)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('shows a refusal and the plain error of a failure', async () => {
		const wrapper = await mountSection(SiemSection, [SINK])
		vi.spyOn(axios, 'post')
			.mockRejectedValueOnce(REFUSED)
			.mockRejectedValueOnce(new Error('Network down'))
		await wrapper.vm.onTest(SINK)
		expect(wrapper.vm.error).toBe('Administrators only')
		await wrapper.vm.onTest(SINK)
		expect(wrapper.vm.error).toBe('Network down')
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('SIEM sink delete', () => {
	it('is busy with no error while it runs, then drops the row', async () => {
		const wrapper = await mountSection(SiemSection, [SINK, { ...SINK, id: 's2' }])
		const seen = during(wrapper, ['busy', 'error'])
		const del = vi.spyOn(axios, 'delete').mockImplementation(async () => {
			seen.capture()
			return { data: null }
		})
		await wrapper.vm.onDelete(SINK)
		expect(del).toHaveBeenCalledWith('/apps/keepiq/api/v1/siem/sinks/s1')
		expect(seen).toMatchObject({ busy: true, error: null })
		expect(wrapper.vm.sinks.map((s) => s.id)).toEqual(['s2'])
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps the row on a refusal', async () => {
		const wrapper = await mountSection(SiemSection, [SINK])
		vi.spyOn(axios, 'delete').mockRejectedValue(REFUSED)
		await wrapper.vm.onDelete(SINK)
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(wrapper.vm.sinks).toEqual([SINK])
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('compliance report generation', () => {
	it('is busy with no error while it runs, then lists and opens the report', async () => {
		const wrapper = await mountSection(ComplianceSection, [])
		wrapper.vm.reports = [{ id: 'r0' }]
		const seen = during(wrapper, ['busy', 'error'])
		const post = vi.spyOn(axios, 'post').mockImplementation(async () => {
			seen.capture()
			return { data: { id: 'r1' } }
		})
		await wrapper.vm.onGenerate()
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/compliance/reports')
		expect(seen).toMatchObject({ busy: true, error: null })
		expect(wrapper.vm.reports).toEqual([{ id: 'r1' }, { id: 'r0' }])
		expect(wrapper.vm.detail).toEqual({ id: 'r1' })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('keeps the list on a refusal', async () => {
		const wrapper = await mountSection(ComplianceSection, [])
		wrapper.vm.reports = [{ id: 'r0' }]
		vi.spyOn(axios, 'post').mockRejectedValue(REFUSED)
		await wrapper.vm.onGenerate()
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(wrapper.vm.reports).toEqual([{ id: 'r0' }])
		expect(wrapper.vm.detail).toBe(null)
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('offboarding run', () => {
	/**
	 * @return {Promise<object>} The section with a leaving user and a successor picked.
	 */
	async function picked() {
		const wrapper = await mountSection(OffboardingSection, [])
		wrapper.vm.leavingUser = { userId: 'ada' }
		wrapper.vm.successorUser = { userId: 'bob' }
		wrapper.vm.confirmOpen = true
		wrapper.vm.summary = { revoked: 0, transferred: 0, skipped: [] }
		return wrapper
	}

	it('closes the confirm, is busy with no error or summary while it runs, then keeps the summary', async () => {
		const wrapper = await picked()
		const seen = during(wrapper, ['busy', 'error', 'summary', 'confirmOpen'])
		const post = vi.spyOn(axios, 'post').mockImplementation(async () => {
			seen.capture()
			return { data: { revoked: 1, transferred: 2, skipped: [] } }
		})
		await wrapper.vm.run()
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/team-folders/offboard', {
			leavingUserId: 'ada',
			successorUserId: 'bob',
		})
		expect(seen).toMatchObject({ busy: true, error: null, summary: null, confirmOpen: false })
		expect(wrapper.vm.summary).toEqual({ revoked: 1, transferred: 2, skipped: [] })
		expect(wrapper.vm.error).toBe(null)
		expect(wrapper.vm.busy).toBe(false)
	})

	it('shows a refusal and leaves no summary', async () => {
		const wrapper = await picked()
		vi.spyOn(axios, 'post').mockRejectedValue(REFUSED)
		await wrapper.vm.run()
		expect(wrapper.vm.error).toBe('Administrators only')
		expect(wrapper.vm.summary).toBe(null)
		expect(wrapper.vm.busy).toBe(false)
	})
})
