/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin audit trail section: retention window with its 30-day floor,
 * filters that reload from page 1, paging, and the client-side CSV export of
 * the whole filtered result.
 *
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.4
 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-5.5
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('../../src/utils/csv.js', async (importOriginal) => ({
	...(await importOriginal()),
	downloadCsv: vi.fn(),
}))

import AdminAuditSection from '../../src/components/settings/AdminAuditSection.vue'
import { useAuditStore } from '../../src/store/modules/audit.js'
import { downloadCsv } from '../../src/utils/csv.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

const URL = '/apps/keepiq/api/settings/admin/audit'

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcSelect: {
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		template: '<div class="nc-select" />',
	},
	NcEmptyContent: { template: '<div v-bind="$attrs"><slot /></div>' },
	NcLoadingIcon: { template: '<div data-testid="audit-loading" />' },
	History: true,
}

describe('AdminAuditSection', () => {
	let store

	beforeEach(() => {
		vi.restoreAllMocks()
		downloadCsv.mockClear()
		setActivePinia(createPinia())
		store = useAuditStore()
		store.fetchAdminAudit = vi.fn().mockResolvedValue()
		store.applyAdminFilters = vi.fn().mockResolvedValue()
		store.fetchAllAdminForExport = vi.fn().mockResolvedValue([])
	})

	async function mountSection() {
		const wrapper = mount(AdminAuditSection, { global: { stubs } })
		await flush()
		return wrapper
	}

	it('loads the retention window and the first page', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { audit_retention_days: 90 } })
		const wrapper = await mountSection()

		expect(get).toHaveBeenCalledWith(URL)
		expect(wrapper.vm.retentionDays).toBe(90)
		expect(store.fetchAdminAudit).toHaveBeenCalledWith(1)
	})

	it('falls back to 365 days when the window is missing or cannot be loaded', async () => {
		vi.spyOn(axios, 'get').mockResolvedValueOnce({ data: {} })
		let wrapper = await mountSection()
		expect(wrapper.vm.retentionDays).toBe(365)

		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('500'))
		wrapper = await mountSection()
		expect(wrapper.vm.retentionDays).toBe(365)
	})

	it('saves a changed retention window', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: { audit_retention_days: 365 } })
		const put = vi.spyOn(axios, 'put').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="audit-retention-input"]').setValue('60')
		await flush()

		expect(put).toHaveBeenCalledWith(URL, { audit_retention_days: 60 })
		expect(wrapper.vm.retentionError).toBe('')
	})

	it('shows the refusal and reloads the stored window when the save is refused', async () => {
		const get = vi.spyOn(axios, 'get').mockResolvedValue({ data: { audit_retention_days: 120 } })
		vi.spyOn(axios, 'put').mockRejectedValue({ response: { data: { message: 'Below the floor' } } })
		const wrapper = await mountSection()

		wrapper.vm.retentionDays = 5
		await wrapper.vm.saveRetention()
		await flush()

		expect(wrapper.vm.retentionError).toBe('Below the floor')
		expect(wrapper.vm.retentionDays).toBe(120)
		expect(get).toHaveBeenCalledTimes(2)
		expect(wrapper.text()).toContain('Below the floor')
	})

	it('names the 30-day floor when the refusal carries no message', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		vi.spyOn(axios, 'put').mockRejectedValue(new Error('400'))
		const wrapper = await mountSection()

		await wrapper.vm.saveRetention()

		expect(wrapper.vm.retentionError).toBe('Retention must be at least 30 days')
	})

	it('applies every filter, with an empty event type when none is selected', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		wrapper.vm.filterActor = 'alice'
		wrapper.vm.filterFrom = '2026-01-01'
		wrapper.vm.filterTo = '2026-02-01'
		await wrapper.vm.onFilterChange()

		expect(store.applyAdminFilters).toHaveBeenLastCalledWith({
			eventType: '',
			actor: 'alice',
			from: '2026-01-01',
			to: '2026-02-01',
		})

		wrapper.vm.selectedEventType = { id: 'secret.read' }
		await wrapper.vm.onFilterChange()

		expect(store.applyAdminFilters).toHaveBeenLastCalledWith(
			expect.objectContaining({ eventType: 'secret.read' }),
		)
	})

	it('shows the empty state when no entry matches', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="audit-empty"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="audit-table"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="audit-pagination"]').exists()).toBe(false)
	})

	it('shows the loading icon instead of the table while loading', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		store.loading = true
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="audit-loading"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="audit-empty"]').exists()).toBe(false)
	})

	it('renders a row per entry and pages forward and back', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		store.adminEntries = [
			{ id: 1, eventType: 'x.custom', actorType: 'user', actorId: 'bob', occurredAt: '2026-01-01T00:00:00Z' },
			{ id: 2, eventType: 'x.other', actorType: 'system', actorId: null, occurredAt: null },
		]
		store.adminPage = 2
		store.adminTotal = 150
		const wrapper = await mountSection()

		expect(wrapper.findAll('[data-testid="audit-row"]')).toHaveLength(2)
		const buttons = wrapper.find('[data-testid="audit-pagination"]').findAll('button')
		await buttons[0].trigger('click')
		expect(store.fetchAdminAudit).toHaveBeenLastCalledWith(1)
		await buttons[buttons.length - 1].trigger('click')
		expect(store.fetchAdminAudit).toHaveBeenLastCalledWith(3)
	})

	it('formats missing timestamps as empty and resolves actor labels', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		const wrapper = await mountSection()

		expect(wrapper.vm.formatTime('')).toBe('')
		expect(wrapper.vm.formatTime('2026-01-01T00:00:00Z')).toBe(
			new Date('2026-01-01T00:00:00Z').toLocaleString(),
		)
		expect(wrapper.vm.actor({ actorType: 'system' })).toBe('System')
		expect(wrapper.vm.actor({ actorType: 'user', actorId: 'carol' })).toBe('carol')
		expect(wrapper.vm.label('x.unknown')).toBe('x.unknown')
	})

	it('exports the whole filtered result as CSV', async () => {
		vi.spyOn(axios, 'get').mockResolvedValue({ data: {} })
		store.fetchAllAdminForExport = vi.fn().mockResolvedValue([
			{ occurredAt: '2026-01-01', eventType: 'x.a', actorType: 'user', actorId: 'dave', objectType: 'secret', objectName: 'Mail' },
			{ occurredAt: '2026-01-02', eventType: 'x.b', actorType: 'link_visitor', actorId: null, objectType: 'link', objectId: 'l-9' },
			{ occurredAt: '2026-01-03', eventType: 'x.c', actorType: 'user', actorId: 'erin', objectType: 'vault' },
		])
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="audit-export-csv"]').trigger('click')
		await flush()

		expect(store.fetchAllAdminForExport).toHaveBeenCalledTimes(1)
		expect(downloadCsv).toHaveBeenCalledTimes(1)
		const [filename, csv] = downloadCsv.mock.calls[0]
		expect(filename).toBe('keepiq-audit.csv')
		const lines = csv.trim().split(/\r?\n/)
		expect(lines[0]).toBe('When,Event,Actor,Object type,Object')
		expect(lines[1]).toBe('2026-01-01,x.a,dave,secret,Mail')
		expect(lines[2]).toBe('2026-01-02,x.b,Anonymous visitor,link,l-9')
		expect(lines[3]).toBe('2026-01-03,x.c,erin,vault,')
	})
})
