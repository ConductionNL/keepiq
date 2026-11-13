/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden for what the export, GDPR export and account deletion dialogs
 * share around their action: they read the export and session stores, show
 * the export store's loading flag, and on close clear what the user entered
 * (master password, confirmation, acknowledgements, report, error) before
 * telling the parent; opening clears nothing.
 *
 * @spec openspec/specs/secret-export/spec.md
 * @spec openspec/specs/gdpr-compliance/spec.md
 */

import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountDeletionDialog from '../../src/dialogs/AccountDeletionDialog.vue'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import GdprExportDialog from '../../src/dialogs/GdprExportDialog.vue'
import { useExportStore } from '../../src/store/modules/export.js'
import { useSessionStore } from '../../src/store/modules/session.js'

vi.mock('../../src/policy/policy.js', () => ({
	fetchPolicy: async () => ({}),
	resetPolicyCache: () => {},
}))

const stub = { template: '<div><slot /><slot name="actions" /></div>' }
const mountOpts = {
	global: {
		stubs: {
			NcDialog: stub,
			NcButton: stub,
			NcNoteCard: stub,
			NcSelect: stub,
			NcTextField: stub,
			NcPasswordField: stub,
			NcCheckboxRadioSwitch: stub,
			SkippedSecretsNotice: stub,
		},
		mocks: { t: (app, s) => s },
	},
}

/** What each dialog holds after the user worked in it, and after it is cleared. */
const CASES = [
	{
		name: 'ExportDialog',
		component: ExportDialog,
		entered: {
			mode: 'csv',
			passphrase: 'pass phrase',
			passphraseScore: 3,
			masterPassword: 'mp',
			warningAcknowledged: true,
			skippedAcknowledged: true,
			scopeFolder: 'f1',
			error: 'Export failed',
			cxfReport: { unmapped: [] },
		},
		cleared: {
			mode: 'encrypted-backup',
			passphrase: '',
			passphraseScore: 0,
			masterPassword: '',
			warningAcknowledged: false,
			skippedAcknowledged: false,
			scopeFolder: 'vault',
			error: null,
			cxfReport: null,
		},
	},
	{
		name: 'GdprExportDialog',
		component: GdprExportDialog,
		entered: { error: 'Export failed', skippedAcknowledged: true },
		cleared: { error: null, skippedAcknowledged: false },
	},
	{
		name: 'AccountDeletionDialog',
		component: AccountDeletionDialog,
		entered: {
			masterPassword: 'mp',
			confirmation: 'DELETE',
			report: { secrets: 3 },
			error: 'Deletion failed',
		},
		cleared: { masterPassword: '', confirmation: '', report: null, error: null },
	},
]

/**
 * @param {object} wrapper The mounted dialog.
 * @param {object} shape The keys to read.
 * @return {object} The dialog's values for those keys.
 */
function pick(wrapper, shape) {
	return Object.fromEntries(Object.keys(shape).map((k) => [k, wrapper.vm[k]]))
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
})

describe.each(CASES)('$name open state', ({ component, entered, cleared }) => {
	/**
	 * @return {object} The open dialog with the entered state.
	 */
	function mountEntered() {
		const wrapper = mount(component, { props: { open: true }, ...mountOpts })
		Object.assign(wrapper.vm, entered)
		return wrapper
	}

	it('reads the export and session stores', () => {
		const wrapper = mountEntered()
		expect(wrapper.vm.exportStore.$id).toBe(useExportStore().$id)
		expect(wrapper.vm.sessionStore.$id).toBe(useSessionStore().$id)
		wrapper.vm.exportStore.loading = true
		expect(useExportStore().loading).toBe(true)
	})

	it('shows the export store loading flag', async () => {
		const wrapper = mountEntered()
		expect(wrapper.vm.loading).toBe(false)
		useExportStore().loading = true
		expect(wrapper.vm.loading).toBe(true)
	})

	it('clears what was entered on close, then tells the parent', () => {
		const wrapper = mountEntered()
		let seen = null
		wrapper.vm.$.vnode.props['onUpdate:open'] = () => {
			seen = pick(wrapper, cleared)
		}
		wrapper.vm.onUpdateOpen(false)
		expect(pick(wrapper, cleared)).toEqual(cleared)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(seen).toEqual(cleared)
	})

	it('clears nothing on open', () => {
		const wrapper = mountEntered()
		wrapper.vm.onUpdateOpen(true)
		expect(pick(wrapper, entered)).toEqual(entered)
		expect(wrapper.emitted('update:open')).toEqual([[true]])
	})
})
