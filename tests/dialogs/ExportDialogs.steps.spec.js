/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pins, path by path, what the export, account deletion and CXP transfer
 * dialogs do when submitted: which store call runs with which arguments, the
 * master-password re-auth refusal, the two-pass unmapped-item report, the
 * texts a failure shows, and when the dialog closes.
 *
 * @spec openspec/specs/secret-export/spec.md
 * @spec openspec/specs/gdpr-compliance/spec.md
 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
 */

import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AccountDeletionDialog from '../../src/dialogs/AccountDeletionDialog.vue'
import CxpTransferDialog from '../../src/dialogs/CxpTransferDialog.vue'
import ExportDialog from '../../src/dialogs/ExportDialog.vue'
import * as reauth from '../../src/crypto/reauth.js'
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

const SECRETS = [{ id: 's1' }]
const FOLDERS = [{ id: 'f1', name: 'F' }]

function mountExport(props = {}) {
	return mount(ExportDialog, {
		props: { open: true, secrets: SECRETS, folders: FOLDERS, ...props },
		...mountOpts,
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.restoreAllMocks()
	useSessionStore().encryptedPrivateKey = 'WRAPPED'
})

describe('ExportDialog.onExport', () => {
	it('writes an encrypted backup of the vault and closes', async () => {
		const wrapper = mountExport()
		const store = useExportStore()
		const backup = vi.spyOn(store, 'exportBackup').mockResolvedValue()
		wrapper.vm.passphrase = 'pass phrase'
		await wrapper.vm.onExport()
		expect(backup).toHaveBeenCalledWith(SECRETS, FOLDERS, 'pass phrase', {
			mode: 'vault',
		})
		expect(wrapper.emitted('update:open')).toEqual([[false]])
		expect(wrapper.vm.passphrase).toBe('')
	})

	it('scopes the export to one folder', async () => {
		const wrapper = mountExport()
		const backup = vi.spyOn(useExportStore(), 'exportBackup').mockResolvedValue()
		wrapper.vm.scopeFolder = 'f1'
		await wrapper.vm.onExport()
		expect(backup.mock.calls[0][3]).toEqual({ mode: 'folders', folderIds: ['f1'] })
	})

	it('writes nothing while skipped secrets are not acknowledged', async () => {
		const wrapper = mountExport({ skipped: 2 })
		const backup = vi.spyOn(useExportStore(), 'exportBackup').mockResolvedValue()
		wrapper.vm.error = 'old'
		await wrapper.vm.onExport()
		expect(backup).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe(null)
		wrapper.vm.skippedAcknowledged = true
		await wrapper.vm.onExport()
		expect(backup).toHaveBeenCalledTimes(1)
	})

	it('refuses a wrong master password for a plaintext export', async () => {
		const wrapper = mountExport()
		const verify = vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(false)
		const csv = vi.spyOn(useExportStore(), 'exportCsv').mockResolvedValue()
		wrapper.vm.mode = 'csv'
		wrapper.vm.masterPassword = 'wrong'
		await wrapper.vm.onExport()
		expect(verify).toHaveBeenCalledWith('WRAPPED', 'wrong')
		expect(csv).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Incorrect master password')
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})

	it('writes a CSV after the re-auth and closes', async () => {
		const wrapper = mountExport()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const csv = vi.spyOn(useExportStore(), 'exportCsv').mockResolvedValue()
		wrapper.vm.mode = 'csv'
		await wrapper.vm.onExport()
		expect(csv).toHaveBeenCalledWith(SECRETS, FOLDERS, { mode: 'vault' })
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('shows the unmapped-item report first, then exports on the second pass', async () => {
		const wrapper = mountExport()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const report = { unmapped: [{ id: 's1' }] }
		const cxf = vi.spyOn(useExportStore(), 'exportCxf').mockResolvedValue(report)
		wrapper.vm.mode = 'cxf'
		await wrapper.vm.onExport()
		expect(wrapper.vm.cxfReport).toEqual(report)
		expect(wrapper.emitted('update:open')).toBeUndefined()
		await wrapper.vm.onExport()
		expect(cxf.mock.calls.map((call) => call[3])).toMatchSnapshot()
		expect(cxf.mock.calls[1].slice(0, 3)).toEqual([SECRETS, FOLDERS, { mode: 'vault' }])
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('exports a clean CXF report at once', async () => {
		const wrapper = mountExport()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const cxf = vi
			.spyOn(useExportStore(), 'exportCxf')
			.mockResolvedValue({ unmapped: [] })
		wrapper.vm.mode = 'cxf'
		await wrapper.vm.onExport()
		expect(cxf).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('update:open')).toEqual([[false]])
	})

	it('shows the export ban instead of the refusal', async () => {
		const wrapper = mountExport()
		vi.spyOn(useExportStore(), 'exportBackup').mockRejectedValue({
			response: { data: { code: 'export_disabled_by_policy' } },
		})
		await wrapper.vm.onExport()
		expect(wrapper.vm.exportBlocked).toBe(true)
		expect(wrapper.vm.error).toBe(null)
	})

	it("shows the store's error, else the error's message, else the fallback", async () => {
		const wrapper = mountExport()
		const store = useExportStore()
		const backup = vi.spyOn(store, 'exportBackup')
		backup.mockRejectedValueOnce(new Error('boom'))
		store.error = 'store says'
		await wrapper.vm.onExport()
		expect(wrapper.vm.error).toBe('store says')
		store.error = null
		backup.mockRejectedValueOnce(new Error('boom'))
		await wrapper.vm.onExport()
		expect(wrapper.vm.error).toBe('boom')
		backup.mockRejectedValueOnce(null)
		await wrapper.vm.onExport()
		expect(wrapper.vm.error).toBe('Export failed')
		expect(wrapper.emitted('update:open')).toBeUndefined()
	})
})

describe('AccountDeletionDialog.onDelete', () => {
	function mountDeletion() {
		return mount(AccountDeletionDialog, { props: { open: true }, ...mountOpts })
	}

	it('refuses a wrong master password and deletes nothing', async () => {
		const wrapper = mountDeletion()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(false)
		const del = vi.spyOn(useExportStore(), 'deleteAccountData')
		wrapper.vm.masterPassword = 'wrong'
		await wrapper.vm.onDelete()
		expect(reauth.verifyMasterPassword).toHaveBeenCalledWith('WRAPPED', 'wrong')
		expect(del).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Incorrect master password')
	})

	it('deletes, keeps the report and locks the vault', async () => {
		const wrapper = mountDeletion()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const del = vi
			.spyOn(useExportStore(), 'deleteAccountData')
			.mockResolvedValue({ report: { secrets: 3 } })
		const lock = vi.spyOn(useSessionStore(), 'lock').mockImplementation(() => {})
		wrapper.vm.masterPassword = 'right'
		wrapper.vm.confirmation = 'DELETE'
		await wrapper.vm.onDelete()
		expect(del).toHaveBeenCalledWith('DELETE', 'right')
		expect(wrapper.vm.report).toEqual({ secrets: 3 })
		expect(lock).toHaveBeenCalled()
	})

	it('shows why the deletion failed', async () => {
		const wrapper = mountDeletion()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		vi.spyOn(useExportStore(), 'deleteAccountData').mockRejectedValue(null)
		await wrapper.vm.onDelete()
		expect(wrapper.vm.error).toBe('Deletion failed')
	})
})

describe('CxpTransferDialog.doSend', () => {
	function mountCxp(props = {}) {
		return mount(CxpTransferDialog, {
			props: { open: true, secrets: SECRETS, folders: FOLDERS, ...props },
			...mountOpts,
		})
	}

	it('refuses a wrong master password before fetching the request', async () => {
		const wrapper = mountCxp()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(false)
		const get = vi.spyOn(axios, 'get')
		wrapper.vm.masterPassword = 'wrong'
		await wrapper.vm.doSend()
		expect(get).not.toHaveBeenCalled()
		expect(wrapper.vm.error).toBe('Incorrect master password')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('sends nothing while skipped secrets are not acknowledged', async () => {
		const wrapper = mountCxp({ skipped: 1 })
		const verify = vi.spyOn(reauth, 'verifyMasterPassword')
		await wrapper.vm.doSend()
		expect(verify).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('fetches the request once, reports first, then seals and posts the envelope', async () => {
		const wrapper = mountCxp()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { payload: '{"req":1}' } })
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const sealed = vi
			.spyOn(useExportStore(), 'exportCxpSealed')
			.mockResolvedValueOnce({ unmapped: [{ id: 's1' }] })
			.mockResolvedValueOnce({ envelope: { sealed: true } })
		wrapper.vm.sendPairingId = 'pair'
		wrapper.vm.masterPassword = 'pw'
		await wrapper.vm.doSend()
		expect(wrapper.vm.cxpReport).toEqual({ unmapped: [{ id: 's1' }] })
		expect(post).not.toHaveBeenCalled()
		await wrapper.vm.doSend()
		expect(get).toHaveBeenCalledTimes(1)
		expect({
			get: get.mock.calls,
			sealed: sealed.mock.calls,
			post: post.mock.calls,
			sent: wrapper.vm.sent,
			masterPassword: wrapper.vm.masterPassword,
			busy: wrapper.vm.busy,
		}).toMatchSnapshot()
	})

	it('shows why sending failed, else the fallback', async () => {
		const wrapper = mountCxp()
		vi.spyOn(reauth, 'verifyMasterPassword').mockResolvedValue(true)
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error('relay down'))
		await wrapper.vm.doSend()
		expect(wrapper.vm.error).toBe('relay down')
		vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error(''))
		await wrapper.vm.doSend()
		expect(wrapper.vm.error).toBe('Could not send the sealed transfer')
		expect(wrapper.vm.busy).toBe(false)
	})
})

describe('CxpTransferDialog.pollForEnvelope', () => {
	function polling() {
		const wrapper = mount(CxpTransferDialog, {
			props: { open: true, secrets: SECRETS, folders: FOLDERS },
			...mountOpts,
		})
		wrapper.vm.waiting = true
		wrapper.vm.pairingId = 'pair'
		wrapper.vm.session = { ephemeral: true }
		return wrapper
	}

	const notYet = () =>
		Object.assign(new Error('nope'), { response: { status: 404 } })

	it('keeps polling while the envelope is not there, then hands it to the import', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = polling()
			const cxp = await import('../../src/crypto/cxp.js')
			vi.spyOn(cxp, 'openEnvelope').mockResolvedValue(
				new TextEncoder().encode('CXF'),
			)
			const { useImportStore } = await import('../../src/store/modules/import.js')
			const importStore = useImportStore()
			const parse = vi.spyOn(importStore, 'parseFile').mockResolvedValue()
			const step = vi.spyOn(importStore, 'goToStep').mockImplementation(() => {})
			const get = vi
				.spyOn(axios, 'get')
				.mockRejectedValueOnce(notYet())
				.mockResolvedValueOnce({ data: { payload: '{"env":1}' } })
			wrapper.vm.pollForEnvelope()
			await vi.advanceTimersByTimeAsync(2000)
			expect(wrapper.vm.pollCount).toBe(1)
			await vi.advanceTimersByTimeAsync(2000)
			expect(get.mock.calls.map(([url]) => url)).toEqual([
				'/apps/keepiq/api/v1/cxp/relay/pair/response',
				'/apps/keepiq/api/v1/cxp/relay/pair/response',
			])
			expect(cxp.openEnvelope).toHaveBeenCalledWith({ ephemeral: true }, { env: 1 })
			expect(parse).toHaveBeenCalledWith('CXF', 'cxf', {})
			expect(step).toHaveBeenCalledWith('mapping')
			expect(wrapper.vm.session).toBe(null)
			expect(wrapper.vm.waiting).toBe(false)
			expect(wrapper.emitted('open-import')).toHaveLength(1)
			expect(wrapper.emitted('update:open')).toEqual([[false]])
		} finally {
			vi.useRealTimers()
		}
	})

	it('stops at the cap with the time-out text', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = polling()
			vi.spyOn(axios, 'get').mockRejectedValue(notYet())
			wrapper.vm.pollForEnvelope()
			await vi.advanceTimersByTimeAsync(2000 * 61)
			expect(wrapper.vm.pollCount).toBe(60)
			expect(wrapper.vm.waiting).toBe(false)
			expect(wrapper.vm.error).toBe('Timed out waiting for the sealed transfer')
		} finally {
			vi.useRealTimers()
		}
	})

	it('shows any other failure and stops polling, and does nothing once no longer waiting', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = polling()
			const get = vi.spyOn(axios, 'get').mockRejectedValueOnce(new Error(''))
			wrapper.vm.pollForEnvelope()
			await vi.advanceTimersByTimeAsync(4000)
			expect(get).toHaveBeenCalledTimes(1)
			expect(wrapper.vm.error).toBe('Could not open the sealed transfer')
			expect(wrapper.vm.waiting).toBe(true)
			wrapper.vm.waiting = false
			wrapper.vm.pollForEnvelope()
			await vi.advanceTimersByTimeAsync(2000)
			expect(get).toHaveBeenCalledTimes(1)
			expect(wrapper.vm.pollCount).toBe(1)
		} finally {
			vi.useRealTimers()
		}
	})
})
