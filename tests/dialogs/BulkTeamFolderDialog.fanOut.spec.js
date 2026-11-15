/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The bulk team-folder dialog fans out once after the moves, both on a run
 * and on a retry of the failed moves: the moves first, then the membership
 * fan-out for the target folder, then `done`; the copies the server refused
 * are named, and a failed fan-out is left to the store and the next retry.
 *
 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
 * @spec openspec/specs/team-folder-sharing/spec.md#requirement-inherited-access-on-add-revoked-on-removal
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BulkTeamFolderDialog from '../../src/dialogs/BulkTeamFolderDialog.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useTeamFolderStore } from '../../src/store/modules/teamFolder.js'

const STUBS = {
	NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	NcButton: { template: '<button v-bind="$attrs"><slot /></button>' },
	NcSelect: { template: '<div class="stub-select" />' },
}

let events

/**
 * @return {object} The dialog armed with a target, every step recorded.
 */
function mountArmed() {
	events = []
	const bulk = useBulkStore()
	bulk.run = vi.fn(async () => events.push('run'))
	bulk.retryFailed = vi.fn(async () => events.push('retry'))
	const wrapper = mount(BulkTeamFolderDialog, {
		propsData: { open: true },
		global: { stubs: STUBS },
	})
	wrapper.vm.target = { id: 'tf-1', folderId: 'folder-b' }
	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	useTeamFolderStore().fetchTeamFolders = vi.fn().mockResolvedValue()
})

describe.each([
	['onRun', 'run'],
	['onRetry', 'retry'],
])('BulkTeamFolderDialog.%s', (method, step) => {
	it('moves, fans out the target folder, names the refused copies, then is done', async () => {
		const wrapper = mountArmed()
		useTeamFolderStore().runFanOut = vi.fn(async (id) => {
			events.push(['fanOut', id])
			return { created: 1, refused: ['Wiki'] }
		})
		await wrapper.vm[method]()
		expect(events).toEqual([step, ['fanOut', 'tf-1']])
		expect(wrapper.vm.refused).toEqual(['Wiki'])
		expect(wrapper.emitted('done')).toHaveLength(1)
	})

	it('names nothing when the fan-out reports no refusals', async () => {
		const wrapper = mountArmed()
		wrapper.vm.refused = ['old']
		useTeamFolderStore().runFanOut = vi.fn().mockResolvedValue(undefined)
		await wrapper.vm[method]()
		expect(wrapper.vm.refused).toEqual([])
		expect(wrapper.emitted('done')).toHaveLength(1)
	})

	it('is done, keeping the earlier names, when the fan-out fails', async () => {
		const wrapper = mountArmed()
		wrapper.vm.refused = ['old']
		useTeamFolderStore().runFanOut = vi.fn().mockRejectedValue(new Error('Fan-out failed'))
		await wrapper.vm[method]()
		expect(wrapper.vm.refused).toEqual(['old'])
		expect(wrapper.emitted('done')).toHaveLength(1)
	})
})
