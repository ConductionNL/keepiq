/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compliance reporting section: the live posture card from the metrics
 * endpoint, the stored snapshot list, generating a snapshot (prepended and
 * opened) and opening a stored one.
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ComplianceSection from '../../src/components/settings/ComplianceSection.vue'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

// The global t() stub returns the key; these assertions need the substituted text.
const tWithVars = {
	methods: {
		t: (_app, key, vars) =>
			Object.entries(vars || {}).reduce(
				(acc, [name, value]) => acc.replace(`{${name}}`, value),
				key,
			),
	},
}

const stubs = {
	CnSettingsSection: { template: '<section><slot /></section>' },
	NcNoteCard: { template: '<div data-testid="compliance-error"><slot /></div>' },
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		template: '<button v-bind="$attrs" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	ComplianceSnapshotDialog: {
		props: ['report'],
		emits: ['close'],
		template: '<div data-testid="snapshot-dialog" :data-report="report ? report.id : \'\'" />',
	},
}

const METRICS = {
	adoption: { usersWithActiveSuite: 7 },
	secretsPerUser: { totalSecrets: 42 },
	shareHygiene: { userShares: 3, groupShares: 2, linkShares: 1 },
	rotationPosture: { openFlagsByReason: { age: 4, breach: 1 } },
	auditIntegrity: { totalEntries: 900, retentionDays: 365 },
}

function mockLoad(metrics = METRICS, reports = [{ id: 'r1', generatedAt: null, generatedBy: 'admin', appVersion: '1.0.0' }]) {
	return vi.spyOn(axios, 'get').mockImplementation((url) => {
		if (url.endsWith('/compliance/metrics')) {
			return Promise.resolve({ data: { metrics, computedAt: '2026-11-01 10:00' } })
		}
		if (url.endsWith('/compliance/reports')) {
			return Promise.resolve({ data: reports })
		}
		return Promise.resolve({ data: { id: url.split('/').pop(), detail: true } })
	})
}

async function mountSection() {
	const wrapper = mount(ComplianceSection, { global: { stubs, mixins: [tWithVars] } })
	await flush()
	return wrapper
}

describe('ComplianceSection', () => {
	beforeEach(() => {
		vi.restoreAllMocks()
	})

	it('renders the live posture and sums the open rotation flags', async () => {
		mockLoad()
		const wrapper = await mountSection()
		const card = wrapper.find('[data-testid="compliance-posture-card"]').text()

		expect(card).toContain('Live posture (2026-11-01 10:00)')
		expect(card).toContain('Users with an active vault: 7')
		expect(card).toContain('Total secrets: 42')
		expect(card).toContain('Outstanding shares (user/group/link): 3 / 2 / 1')
		expect(card).toContain('Open rotation flags: 5')
		expect(card).toContain('Audit entries retained: 900 (365 days)')
		expect(wrapper.findAll('[data-testid="compliance-report-list"] tbody tr')).toHaveLength(1)
	})

	it('shows zeros for missing metric groups', async () => {
		mockLoad({}, [])
		const wrapper = await mountSection()

		expect(wrapper.vm.openFlagTotal).toBe(0)
		expect(wrapper.find('[data-testid="compliance-posture-card"]').text()).toContain('Total secrets: 0')
		expect(wrapper.find('[data-testid="compliance-report-list"]').exists()).toBe(false)
	})

	it('shows the load error and no posture card', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Admins only' } } })
		const wrapper = await mountSection()

		expect(wrapper.find('[data-testid="compliance-error"]').text()).toBe('Admins only')
		expect(wrapper.find('[data-testid="compliance-posture-card"]').exists()).toBe(false)
	})

	it('generates a snapshot, prepends it and opens it', async () => {
		mockLoad()
		const post = vi.spyOn(axios, 'post').mockResolvedValue({
			data: { id: 'r2', generatedAt: null, generatedBy: 'admin', appVersion: '1.1.0' },
		})
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="compliance-generate"]').trigger('click')
		await flush()

		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/compliance/reports')
		expect(wrapper.vm.reports.map((r) => r.id)).toEqual(['r2', 'r1'])
		expect(wrapper.find('[data-testid="snapshot-dialog"]').attributes('data-report')).toBe('r2')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reports a failed generation and leaves the list alone', async () => {
		mockLoad()
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('timeout'))
		const wrapper = await mountSection()

		await wrapper.vm.onGenerate()

		expect(wrapper.vm.error).toBe('timeout')
		expect(wrapper.vm.reports.map((r) => r.id)).toEqual(['r1'])
		expect(wrapper.vm.detail).toBe(null)
	})

	it('opens a stored snapshot and closes it again', async () => {
		const get = mockLoad()
		const wrapper = await mountSection()

		await wrapper.find('[data-testid="compliance-open-r1"]').trigger('click')
		await flush()

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/compliance/reports/r1')
		expect(wrapper.vm.detail).toEqual({ id: 'r1', detail: true })

		wrapper.findComponent(stubs.ComplianceSnapshotDialog).vm.$emit('close')
		expect(wrapper.vm.detail).toBe(null)
	})

	it('reports a snapshot that cannot be opened', async () => {
		mockLoad()
		const wrapper = await mountSection()
		vi.spyOn(axios, 'get').mockRejectedValue({ response: { data: { message: 'Gone' } } })

		await wrapper.vm.openReport('r9')

		expect(wrapper.vm.error).toBe('Gone')
		expect(wrapper.vm.detail).toBe(null)
	})
})
