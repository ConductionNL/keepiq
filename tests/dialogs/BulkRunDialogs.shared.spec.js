/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the bulk delete, state and move dialogs share: the run and retry
 * they hand the bulk store (the selection, the per-item step and the
 * progress label), the `done` event after each, the outcome counts in the
 * title, and the per-item rule that a secret already gone is skipped while
 * any other failure is passed on.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-chunked-execution-with-a-per-item-report
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkDeleteDialog from '../../src/dialogs/BulkDeleteDialog.vue'
import BulkMoveDialog from '../../src/dialogs/BulkMoveDialog.vue'
import BulkStateDialog from '../../src/dialogs/BulkStateDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useSecretStore } from '../../src/store/modules/secret.js'

const STUBS = {
	NcDialog: { props: ['name'], template: '<div><h1 data-testid="title">{{ name }}</h1><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcNoteCard: { template: '<div><slot /></div>' },
	DestinationSelect: { template: '<div />' },
	BulkRunPanel: { template: '<div />' },
}

// The test translate returns its source text; the counts themselves are pinned in BulkDeleteDialog.spec.js.
const GLOBAL = { stubs: STUBS }

const CASES = [
	{ name: 'delete', component: BulkDeleteDialog, props: {}, step: 'deleteOne', store: 'deleteSecret', run: 'Deleting secrets', retry: 'Retrying delete', done: 'Deleted {ok} of {total} secrets' },
	{ name: 'state', component: BulkStateDialog, props: { action: 'archive' }, step: 'changeOne', store: 'changeSecretState', run: 'Archive {count} secrets', retry: 'Archive {count} secrets', done: 'Done for {ok} of {total} secrets' },
	{ name: 'move', component: BulkMoveDialog, props: {}, step: 'moveOne', store: 'updateSecret', run: 'Moving secrets', retry: 'Retrying move', done: 'Moved {ok} of {total} secrets' },
]

describe('bulk run dialogs, shared behaviour', () => {
	let bulk

	beforeEach(() => {
		setActivePinia(createPinia())
		bulk = useBulkStore()
		bulk.selectedIds = ['a', 'b']
	})

	for (const c of CASES) {
		it(`${c.name}: hands the run and retry to the store and emits done after each`, async () => {
			const run = vi.spyOn(bulk, 'run').mockImplementation(async (ids, step) => {
				await step(ids[0])
			})
			const retry = vi.spyOn(bulk, 'retryFailed').mockResolvedValue(undefined)
			const write = vi.spyOn(useSecretStore(), c.store).mockResolvedValue({})
			const wrapper = mount(c.component, { propsData: { open: true, ...c.props }, global: GLOBAL })

			expect(wrapper.vm.ran).toBe(false)
			await wrapper.vm.onRun()
			expect(wrapper.vm.ran).toBe(true)
			expect(run.mock.calls[0][0]).toEqual(['a', 'b'])
			expect(run.mock.calls[0][2]).toBe(c.run)
			expect(write.mock.calls[0][0]).toBe('a')
			expect(wrapper.emitted('done')).toHaveLength(1)

			await wrapper.vm.onRetry()
			expect(retry.mock.calls[0][1]).toBe(c.retry)
			await retry.mock.calls[0][0]('b')
			expect(write.mock.calls[1][0]).toBe('b')
			expect(wrapper.emitted('done')).toHaveLength(2)
		})

		it(`${c.name}: counts the outcome off the report once finished`, async () => {
			const wrapper = mount(c.component, { propsData: { open: true, ...c.props }, global: GLOBAL })
			bulk.report = [{ status: 'ok' }, { status: 'failed' }]
			wrapper.vm.ran = true
			await wrapper.vm.$nextTick()
			expect(wrapper.vm.finished).toBe(true)
			expect(wrapper.find('[data-testid="title"]').text()).toBe(c.done)
			bulk.progress.running = true
			expect(wrapper.vm.finished).toBe(false)
		})
	}

	it('delete and state skip a secret already gone and pass any other failure on', async () => {
		for (const c of CASES.slice(0, 2)) {
			const wrapper = mount(c.component, { propsData: { open: true, ...c.props }, global: GLOBAL })
			const write = vi.spyOn(useSecretStore(), c.store)
			write.mockResolvedValueOnce({})
			expect(await wrapper.vm[c.step]('a')).toEqual({ status: 'ok' })
			write.mockRejectedValueOnce({ response: { status: 404 } })
			expect(await wrapper.vm[c.step]('a')).toEqual({ status: 'skipped', reason: 'already deleted' })
			const failure = { response: { status: 500 } }
			write.mockRejectedValueOnce(failure)
			await expect(wrapper.vm[c.step]('a')).rejects.toBe(failure)
		}
	})

	it('move passes every failure on', async () => {
		const wrapper = mount(BulkMoveDialog, { propsData: { open: true }, global: GLOBAL })
		wrapper.vm.targetFolderId = 'f-1'
		const write = vi.spyOn(useSecretStore(), 'updateSecret')
		write.mockResolvedValueOnce({})
		expect(await wrapper.vm.moveOne('a')).toEqual({ status: 'ok' })
		expect(write).toHaveBeenCalledWith('a', { folderId: 'f-1' })
		const gone = { response: { status: 404 } }
		write.mockRejectedValueOnce(gone)
		await expect(wrapper.vm.moveOne('a')).rejects.toBe(gone)
	})
})
