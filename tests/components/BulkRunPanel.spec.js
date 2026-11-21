/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The shared bulk-run panel: a cancellable progress bar while the runner is
 * active, then a per-item report named by the secrets' own names, with a
 * retry button only when something failed.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkRunPanel from '../../src/components/BulkRunPanel.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

const substitutingT = {
	methods: {
		t: (_app, key, vars) =>
			Object.entries(vars || {}).reduce(
				(acc, [name, value]) => acc.replace(`{${name}}`, value),
				key,
			),
	},
}

const stubs = {
	NcButton: {
		emits: ['click'],
		template: '<button @click="$emit(\'click\')"><slot /></button>',
	},
}

describe('BulkRunPanel', () => {
	let bulk

	beforeEach(() => {
		setActivePinia(createPinia())
		bulk = useBulkStore()
		bulk.cancel = vi.fn()
		useSecretStore().secrets = [{ id: 's-1', name: 'Mailserver' }]
	})

	function mountPanel() {
		return mount(BulkRunPanel, { global: { stubs, mixins: [substitutingT] } })
	}

	it('shows the progress while running and cancels through the store', async () => {
		bulk.progress = {
			running: true,
			done: 2,
			total: 5,
			label: 'Moving',
			cancelled: false,
		}
		const wrapper = mountPanel()

		expect(wrapper.find('[data-testid="bulk-progress-label"]').text()).toBe(
			'Moving — 2 / 5',
		)
		expect(wrapper.find('[data-testid="bulk-report"]').exists()).toBe(false)
		await wrapper.find('[data-testid="bulk-cancel"]').trigger('click')
		expect(bulk.cancel).toHaveBeenCalledTimes(1)
	})

	it('names each reported secret, falling back to its id when it is not loaded', () => {
		bulk.report = [
			{ secretId: 's-1', status: 'ok' },
			{ secretId: 's-gone', status: 'skipped', reason: 'cancelled' },
		]
		const wrapper = mountPanel()

		const cells = wrapper
			.findAll('[data-testid="bulk-report"] tbody tr')
			.map((row) => row.findAll('td').map((td) => td.text()))
		expect(cells).toEqual([
			['Mailserver', 'ok', ''],
			['s-gone', 'skipped', 'cancelled'],
		])
		expect(wrapper.find('[data-testid="bulk-retry-failed"]').exists()).toBe(
			false,
		)
	})

	it('offers a retry of the failed items only, and emits it', async () => {
		bulk.report = [
			{ secretId: 's-1', status: 'failed', reason: 'forbidden' },
			{ secretId: 's-2', status: 'failed', reason: 'gone' },
			{ secretId: 's-3', status: 'ok' },
		]
		const wrapper = mountPanel()

		const retry = wrapper.find('[data-testid="bulk-retry-failed"]')
		expect(retry.text()).toBe('Retry 2 failed')
		await retry.trigger('click')
		expect(wrapper.emitted('retry')).toHaveLength(1)
	})

	it('renders neither progress nor report before a run', () => {
		const wrapper = mountPanel()

		expect(wrapper.find('[data-testid="bulk-cancel"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="bulk-report"]').exists()).toBe(false)
	})
})
