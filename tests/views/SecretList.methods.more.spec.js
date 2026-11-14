/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The secret list's remaining behaviour, beyond what the other
 * SecretList.*.spec.js files pin:
 *
 *  - mount: the preamble fetches (types, folders, requests, view
 *    preference, tags), the first reload with the declared query, the
 *    folder-switch spinner flag and the lazy password-health pass;
 *  - the folder watcher (blank while switching, reload, unblank) and the
 *    selection-prune watcher;
 *  - selection: select-all / clear-all, the library select passthrough
 *    and the shift-click range fallback when an end left the view;
 *  - export / CXP / GDPR: decrypt the whole vault client-side, counting
 *    blocked rows and rows whose decryption throws as skipped;
 *  - filters, sort, debounced search, paging and the routing helpers
 *    (open secret / row / folder, trashed rows opening nothing);
 *  - the computeds with branches: title, empty texts, folder trail with
 *    its cycle guard, breadcrumbs, request badge state, pending-sync
 *    marker, pagination, funnel active state and tag options;
 *  - the template wiring: a CnIndexPage stub renders every named slot so
 *    the action menu, filter menu, folder strip and bulk selection buttons
 *    are clicked for real and their effects asserted.
 *
 * Only boundaries are mocked: the stores' network actions, the router and
 * the injected registry-modal opener. Mount style follows
 * SecretList.rowCheck.golden.spec.js; options-object calls follow
 * SecretList.listQuery.spec.js.
 *
 * @spec openspec/specs/secrets/spec.md#requirement-list-and-pagination
 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
 * @spec openspec/specs/bulk-actions/spec.md#requirement-multi-select-and-bulk-action-bar
 * @spec openspec/specs/secret-export/spec.md
 * @spec openspec/specs/vault-trash-and-archive/spec.md#requirement-archiving-a-secret
 */

import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import AccountDeletionDialog from '../../src/dialogs/AccountDeletionDialog.vue'
import BulkMoveDialog from '../../src/dialogs/BulkMoveDialog.vue'
import BulkStateDialog from '../../src/dialogs/BulkStateDialog.vue'
import ImportWizardDialog from '../../src/dialogs/ImportWizardDialog.vue'
import SecretRequestCreateDialog from '../../src/dialogs/SecretRequestCreateDialog.vue'
import SecretList from '../../src/views/SecretList.vue'
import { useBulkStore } from '../../src/store/modules/bulk.js'
import { useFolderStore } from '../../src/store/modules/folder.js'
import { useHealthStore } from '../../src/store/modules/health.js'
import { useSecretStore } from '../../src/store/modules/secret.js'
import { useSecretRequestStore } from '../../src/store/modules/secretRequest.js'
import { useSecretTypeStore } from '../../src/store/modules/secretType.js'
import { useSessionStore } from '../../src/store/modules/session.js'
import { useUserPreferencesStore } from '../../src/store/modules/userPreferences.js'

const { methods, computed, watch } = SecretList

/** Renders every named slot CnIndexPage offers, per object where relevant. */
const CnIndexPage = {
	name: 'CnIndexPage',
	props: ['objects', 'title', 'addLabel', 'loading', 'pagination'],
	emits: ['refresh', 'add', 'search', 'select', 'rowClick', 'pageChanged'],
	render() {
		const s = this.$slots
		const rows = this.objects ?? []
		return h('div', { class: 'cn-index-page' }, [
			h('div', { class: 'action-items' }, s['action-items']?.()),
			h('div', { class: 'after-search' }, s['after-search']?.()),
			h('div', { class: 'before-collection' }, s['before-collection']?.()),
			h('div', { class: 'selection-actions' }, s['selection-actions']?.()),
			h('div', { class: 'empty' }, s.empty?.()),
			...rows.flatMap((object) => [
				h('div', { class: 'list-row' }, s['list-item']?.({ object, selected: true })),
				h('div', { class: 'card-row' }, s.card?.({ object, selected: false })),
				h('div', { class: 'col-name' }, s['column-name']?.({ row: object })),
				h('div', { class: 'col-type' }, s['column-type']?.({ row: object })),
				h('div', { class: 'col-strength' }, s['column-strength']?.({ row: object })),
			]),
		])
	},
}

const NcActions = {
	name: 'NcActions',
	render() {
		return h('div', { class: 'nc-actions' }, [this.$slots.icon?.(), this.$slots.default?.()])
	},
}

const NcActionButton = {
	name: 'NcActionButton',
	props: ['disabled'],
	emits: ['click'],
	render() {
		return h(
			'button',
			{ class: 'nc-action-button', disabled: this.disabled, onClick: () => this.$emit('click') },
			[this.$slots.icon?.(), this.$slots.default?.()],
		)
	},
}

const NcButton = {
	name: 'NcButton',
	props: ['disabled'],
	emits: ['click'],
	render() {
		return h(
			'button',
			{ class: 'nc-button', disabled: this.disabled, onClick: () => this.$emit('click') },
			[this.$slots.icon?.(), this.$slots.default?.()],
		)
	},
}

const NcActionCheckbox = {
	name: 'NcActionCheckbox',
	props: ['modelValue', 'disabled'],
	emits: ['update:modelValue'],
	render() {
		return h(
			'button',
			{
				class: 'nc-action-checkbox',
				onClick: () => this.$emit('update:modelValue', !this.modelValue),
			},
			this.$slots.default?.(),
		)
	},
}

const NcActionRadio = {
	name: 'NcActionRadio',
	props: ['modelValue', 'value'],
	emits: ['update:modelValue'],
	render() {
		return h(
			'button',
			{
				class: 'nc-action-radio',
				'data-value': this.value,
				onClick: () => this.$emit('update:modelValue', this.value),
			},
			this.$slots.default?.(),
		)
	},
}

/**
 * Replace every store action that reaches the network with a spy.
 *
 * @return {object} The stores, keyed by short name.
 */
function stubNetwork() {
	const secret = useSecretStore()
	secret.fetchSecrets = vi.fn().mockResolvedValue()
	secret.fetchTags = vi.fn().mockResolvedValue()
	secret.fetchAllSecrets = vi.fn().mockResolvedValue()
	secret.decryptSecret = vi.fn(async (s) => ({ ...s, key: 'plain' }))
	secret.searchSecrets = vi.fn().mockResolvedValue()
	const folder = useFolderStore()
	folder.fetchFolders = vi.fn().mockResolvedValue()
	const types = useSecretTypeStore()
	types.fetchTypes = vi.fn().mockResolvedValue()
	const requests = useSecretRequestStore()
	requests.fetchRequests = vi.fn().mockResolvedValue()
	const prefs = useUserPreferencesStore()
	prefs.ensureLoaded = vi.fn().mockResolvedValue()
	const health = useHealthStore()
	health.analyseVault = vi.fn().mockResolvedValue()
	return { secret, folder, types, requests, prefs, health }
}

/**
 * Mount the list with slot-rendering stubs and a mocked router.
 *
 * @param {object} [options] Route and registry overrides.
 * @param {object} [options.route] The `$route` mock.
 * @return {Promise<{wrapper: object, router: object, cnOpenModal: Function}>}
 */
async function mountList({ route } = {}) {
	const router = { push: vi.fn(), replace: vi.fn().mockResolvedValue() }
	const cnOpenModal = vi.fn()
	const wrapper = mount(SecretList, {
		shallow: true,
		global: {
			stubs: {
				CnIndexPage,
				NcActions,
				NcActionButton,
				NcActionCheckbox,
				NcActionRadio,
				NcButton,
				NcActionSeparator: true,
				NcActionCaption: true,
			},
			provide: { cnOpenModal },
			mocks: {
				$route: route ?? { query: {}, params: {}, name: 'SecretList' },
				$router: router,
			},
		},
	})
	await flushPromises()
	return { wrapper, router, cnOpenModal }
}

/**
 * @param {object} wrapper The mounted list.
 * @param {string} label The button text.
 * @return {object} The first button whose text is exactly the label.
 */
function button(wrapper, label) {
	const found = wrapper.findAll('button').find((b) => b.text().trim() === label)
	if (!found) throw new Error(`no button "${label}"`)
	return found
}

beforeEach(() => {
	setActivePinia(createPinia())
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('SecretList — mount and watchers', () => {
	it('runs the preamble fetches, declares the query, loads page 1 and starts the health pass', async () => {
		const { secret, folder, types, requests, prefs, health } = stubNetwork()
		const { wrapper } = await mountList()

		expect(types.fetchTypes).toHaveBeenCalledTimes(1)
		expect(folder.fetchFolders).toHaveBeenCalledTimes(1)
		expect(requests.fetchRequests).toHaveBeenCalledTimes(1)
		expect(prefs.ensureLoaded).toHaveBeenCalledTimes(1)
		expect(secret.fetchTags).toHaveBeenCalledTimes(1)
		expect(secret.fetchSecrets).toHaveBeenCalledWith({ page: 1 })
		expect(secret.filters.state).toBe('live')
		expect(secret.filters.folderId).toBeNull()
		expect(health.analyseVault).toHaveBeenCalledWith({
			stalenessThreshold: '365',
			breachEnabled: false,
		})
		expect(wrapper.vm.folderSwitching).toBe(false)
	})

	it('still loads the list when a preamble fetch rejects', async () => {
		const { secret, folder } = stubNetwork()
		folder.fetchFolders = vi.fn().mockRejectedValue(new Error('offline'))
		const { wrapper } = await mountList()

		expect(secret.fetchSecrets).toHaveBeenCalledWith({ page: 1 })
		expect(wrapper.vm.folderSwitching).toBe(false)
	})

	it('skips the health pass when an analysis already ran', () => {
		const health = useHealthStore()
		health.analyseVault = vi.fn().mockResolvedValue()
		health.status = 'ready'
		methods.triggerHealthPass.call({})
		expect(health.analyseVault).not.toHaveBeenCalled()
	})

	it('swallows a rejected health pass', async () => {
		const health = useHealthStore()
		health.analyseVault = vi.fn().mockRejectedValue(new Error('locked'))
		expect(() => methods.triggerHealthPass.call({})).not.toThrow()
		await flushPromises()
		expect(health.analyseVault).toHaveBeenCalledTimes(1)
	})

	it('blanks the list during a folder switch and unblanks after the reload, even on failure', async () => {
		const states = []
		const ctx = {
			folderSwitching: false,
			reload: vi.fn(async () => {
				states.push(ctx.folderSwitching)
				throw new Error('boom')
			}),
		}
		await expect(watch.selectedFolderId.call(ctx)).rejects.toThrow('boom')
		expect(states).toEqual([true])
		expect(ctx.folderSwitching).toBe(false)
	})

	it('prunes selected ids that left the view, and leaves an intact selection alone', () => {
		const setSelection = vi.fn()
		const ctx = { bulkStore: { selectedIds: ['a', 'b', 'gone'], setSelection } }
		watch.secrets.call(ctx, [{ id: 'a' }, { id: 'b' }])
		expect(setSelection).toHaveBeenCalledWith(['a', 'b'])

		setSelection.mockClear()
		ctx.bulkStore.selectedIds = ['a']
		watch.secrets.call(ctx, [{ id: 'a' }])
		expect(setSelection).not.toHaveBeenCalled()

		watch.secrets.call(ctx, null)
		expect(setSelection).toHaveBeenCalledWith([])
	})
})

describe('SecretList — selection', () => {
	it('selects all visible rows on top of an existing selection, and clears only them', () => {
		const setSelection = vi.fn()
		const ctx = {
			secrets: [{ id: 'a' }, { id: 'b' }],
			bulkStore: { selectedIds: ['x'], setSelection },
		}
		methods.onSelectAll.call(ctx, true)
		expect(setSelection).toHaveBeenLastCalledWith(['x', 'a', 'b'])

		ctx.bulkStore.selectedIds = ['x', 'a', 'b']
		methods.onSelectAll.call(ctx, false)
		expect(setSelection).toHaveBeenLastCalledWith(['x'])
	})

	it('falls back to a plain toggle when the shift anchor is no longer in view', () => {
		const setSelection = vi.fn()
		const ctx = {
			lastShiftKey: true,
			lastCheckedId: 'vanished',
			secrets: [{ id: 'a' }, { id: 'b' }],
			bulkStore: { selectedIds: ['b'], setSelection },
		}
		methods.onRowCheck.call(ctx, { id: 'b' })
		expect(setSelection).toHaveBeenCalledWith([])
		expect(ctx.lastCheckedId).toBe('b')
	})

	it('passes library selections through and treats a missing list as empty', () => {
		const setSelection = vi.fn()
		const ctx = { bulkStore: { setSelection } }
		methods.onLibrarySelect.call(ctx, ['s-1', 's-2'])
		expect(setSelection).toHaveBeenLastCalledWith(['s-1', 's-2'])
		methods.onLibrarySelect.call(ctx, undefined)
		expect(setSelection).toHaveBeenLastCalledWith([])
	})

	it('reports all-selected only for a non-empty, fully selected view', () => {
		const all = computed.allCurrentSelected
		expect(all.call({ secrets: [], bulkStore: { selectedIds: [] } })).toBe(false)
		expect(
			all.call({ secrets: [{ id: 'a' }, { id: 'b' }], bulkStore: { selectedIds: ['a'] } }),
		).toBe(false)
		expect(
			all.call({ secrets: [{ id: 'a' }], bulkStore: { selectedIds: ['a', 'z'] } }),
		).toBe(true)
	})
})

describe('SecretList — export, CXP and GDPR', () => {
	/**
	 * @return {object} A context whose store holds a mixed vault.
	 */
	function exportCtx() {
		const store = {
			secrets: [
				{ id: 'ok', name: 'Mail' },
				{ id: 'blocked', name: 'Old', blocked: true },
				{ id: 'broken', name: 'Bad' },
			],
			fetchAllSecrets: vi.fn().mockResolvedValue(),
			decryptSecret: vi.fn(async (s) => {
				if (s.id === 'broken') throw new Error('revoked suite')
				return { ...s, key: 'hunter2' }
			}),
		}
		const ctx = { secretStore: store, decryptedSecrets: [], skippedSecrets: 0 }
		ctx.decryptAllSecrets = methods.decryptAllSecrets.bind(ctx)
		return ctx
	}

	it('decrypts the whole vault, skipping blocked rows without decrypting them and counting failures', async () => {
		const ctx = exportCtx()
		const result = await ctx.decryptAllSecrets()

		expect(ctx.secretStore.fetchAllSecrets).toHaveBeenCalledTimes(1)
		expect(ctx.secretStore.decryptSecret).toHaveBeenCalledTimes(2)
		expect(ctx.secretStore.decryptSecret).not.toHaveBeenCalledWith(
			expect.objectContaining({ id: 'blocked' }),
		)
		expect(result).toEqual({
			secrets: [{ id: 'ok', name: 'Mail', key: 'hunter2' }],
			skipped: 2,
		})
	})

	it.each([
		['openExport', 'exportOpen'],
		['openCxp', 'cxpOpen'],
		['openGdpr', 'gdprOpen'],
	])('%s hands the decrypted secrets and skip count to its dialog', async (method, flag) => {
		const ctx = exportCtx()
		await methods[method].call(ctx)
		expect(ctx.decryptedSecrets).toEqual([{ id: 'ok', name: 'Mail', key: 'hunter2' }])
		expect(ctx.skippedSecrets).toBe(2)
		expect(ctx[flag]).toBe(true)
	})

	it('Export selected opens the export dialog on the current selection', async () => {
		const ctx = exportCtx()
		ctx.bulkStore = { selectedIds: ['ok', 'broken'] }
		ctx.exportSelection = []
		ctx.openExport = methods.openExport.bind(ctx)
		await methods.openExportSelected.call(ctx)
		expect(ctx.exportSelection).toEqual(['ok', 'broken'])
		expect(ctx.exportSelection).not.toBe(ctx.bulkStore.selectedIds)
		expect(ctx.exportOpen).toBe(true)
		expect(ctx.skippedSecrets).toBe(2)
	})

	it('closing the export dialog forgets the selection it was opened with', () => {
		const ctx = { exportOpen: true, exportSelection: ['ok'] }
		methods.onExportOpen.call(ctx, true)
		expect(ctx.exportSelection).toEqual(['ok'])
		methods.onExportOpen.call(ctx, false)
		expect(ctx.exportOpen).toBe(false)
		expect(ctx.exportSelection).toEqual([])
	})

	it('"export first" closes the deletion dialog and opens the export', async () => {
		const ctx = { deletionOpen: true, openExport: vi.fn().mockResolvedValue() }
		await methods.onExportFirst.call(ctx)
		expect(ctx.deletionOpen).toBe(false)
		expect(ctx.openExport).toHaveBeenCalledTimes(1)
	})
})

describe('SecretList — filters, search, sort and refresh hooks', () => {
	it.each([
		['onTypeFilter', 'type-login', 'typeFilter', 'type-login'],
		['onFavouriteFilter', 1, 'favouriteFilter', true],
		['onFavouriteFilter', undefined, 'favouriteFilter', false],
		['onTagFilter', 'work', 'tagFilter', 'work'],
		['onTagFilter', '', 'tagFilter', null],
		['onSort', 'updated_at', 'sortField', 'updated_at'],
	])('%s(%s) sets %s and reloads', (method, arg, field, expected) => {
		const ctx = { reload: vi.fn() }
		methods[method].call(ctx, arg)
		expect(ctx[field]).toBe(expected)
		expect(ctx.reload).toHaveBeenCalledTimes(1)
	})

	it('debounces search: only the last term reaches the store, after 300ms', () => {
		vi.useFakeTimers({ now: new Date('2026-01-01T00:00:00Z') })
		const ctx = { searchTimer: null, secretStore: { searchSecrets: vi.fn() } }
		methods.onSearch.call(ctx, 'ma')
		methods.onSearch.call(ctx, 'mail')
		vi.advanceTimersByTime(299)
		expect(ctx.secretStore.searchSecrets).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(ctx.secretStore.searchSecrets).toHaveBeenCalledTimes(1)
		expect(ctx.secretStore.searchSecrets).toHaveBeenCalledWith('mail')
		expect(ctx.searchTerm).toBe('mail')
	})

	it('reloads and refetches tag counts after a bulk run, tolerating a tag fetch failure', async () => {
		const ctx = {
			reload: vi.fn().mockResolvedValue(),
			secretStore: { fetchTags: vi.fn().mockRejectedValue(new Error('x')) },
		}
		await expect(methods.onBulkDone.call(ctx)).resolves.toBeUndefined()
		expect(ctx.reload).toHaveBeenCalledTimes(1)
		expect(ctx.secretStore.fetchTags).toHaveBeenCalledTimes(1)
	})

	it('refreshes folders before secrets after an import', async () => {
		const calls = []
		const ctx = {
			folderStore: { fetchFolders: vi.fn(async () => calls.push('folders')) },
			reload: vi.fn(async () => calls.push('reload')),
		}
		await methods.onImported.call(ctx)
		expect(calls).toEqual(['folders', 'reload'])
	})

	it('refetches list and requests after a credential request, keeping the dialog open', async () => {
		const requests = useSecretRequestStore()
		requests.fetchRequests = vi.fn().mockResolvedValue()
		const ctx = { credentialRequestOpen: true, reload: vi.fn().mockResolvedValue() }
		await methods.onCredentialRequested.call(ctx)
		expect(ctx.reload).toHaveBeenCalledTimes(1)
		expect(requests.fetchRequests).toHaveBeenCalledTimes(1)
		expect(ctx.credentialRequestOpen).toBe(true)
	})

	it('refuses to open the import wizard while locked', () => {
		const locked = { vaultLocked: true, importOpen: false }
		methods.openImport.call(locked)
		expect(locked.importOpen).toBe(false)
		const open = { vaultLocked: false, importOpen: false }
		methods.openImport.call(open)
		expect(open.importOpen).toBe(true)
	})
})

describe('SecretList — navigation and registry modals', () => {
	it('opens a secret in the folder route, and opens nothing from the trash', () => {
		const push = vi.fn()
		const ctx = {
			listState: 'live',
			$route: { name: 'SecretListFolder', params: { folderId: 'f-1' } },
			$router: { push },
		}
		methods.openSecret.call(ctx, 's-9')
		expect(push).toHaveBeenCalledWith({
			name: 'SecretListFolder',
			params: { folderId: 'f-1', id: 's-9' },
		})

		push.mockClear()
		methods.openSecret.call({ ...ctx, listState: 'trashed' }, 's-9')
		expect(push).not.toHaveBeenCalled()
	})

	it('opens the clicked row, ignoring an empty row event', () => {
		const ctx = { openSecret: vi.fn() }
		methods.onRowOpen.call(ctx, null)
		expect(ctx.openSecret).not.toHaveBeenCalled()
		methods.onRowOpen.call(ctx, { id: 's-3' })
		expect(ctx.openSecret).toHaveBeenCalledWith('s-3')
	})

	it('navigates into a folder by path', () => {
		const push = vi.fn()
		methods.openFolder.call({ $router: { push } }, 'f-7')
		expect(push).toHaveBeenCalledWith('/folders/f-7')
	})

	it('opens the create-secret modal for the current folder and reloads on save', () => {
		const cnOpenModal = vi.fn()
		const ctx = { cnOpenModal, selectedFolderId: 'f-1', reload: vi.fn() }
		methods.openCreateSecret.call(ctx)
		expect(cnOpenModal).toHaveBeenCalledWith('secret-create', {
			folderId: 'f-1',
			onSaved: expect.any(Function),
		})
		cnOpenModal.mock.calls[0][1].onSaved()
		expect(ctx.reload).toHaveBeenCalledTimes(1)
	})

	it('opens the create-folder modal under an explicit parent, else the current folder', () => {
		const cnOpenModal = vi.fn()
		const ctx = {
			cnOpenModal,
			selectedFolderId: 'f-1',
			folderStore: { fetchFolders: vi.fn() },
		}
		methods.openCreateFolder.call(ctx, { parentId: 'p-2' })
		expect(cnOpenModal.mock.calls[0][1].parentId).toBe('p-2')
		methods.openCreateFolder.call(ctx)
		expect(cnOpenModal.mock.calls[1][1].parentId).toBe('f-1')
		cnOpenModal.mock.calls[1][1].onSaved()
		expect(ctx.folderStore.fetchFolders).toHaveBeenCalledTimes(1)
	})
})

describe('SecretList — computeds with branches', () => {
	it('titles and empty-states each list state', () => {
		const cases = [
			['trashed', 'Trash', 'The trash is empty'],
			['archived', 'Archive', 'No archived secrets'],
			['live', 'Work', 'No secrets found'],
		]
		for (const [listState, title, empty] of cases) {
			const ctx = { listState, selectedFolderName: 'Work' }
			expect(computed.pageTitle.call(ctx)).toBe(title)
			expect(computed.emptyText.call(ctx)).toBe(empty)
			expect(computed.emptyDescription.call(ctx)).toMatch(/\S/)
		}
		expect(computed.pageTitle.call({ listState: 'live', selectedFolderName: '' })).toBe('Secrets')
		expect(computed.emptyDescription.call({ listState: 'live' })).toBe(
			'Add your first secret using the button above',
		)
	})

	it('maps route names to list states', () => {
		expect(computed.listState.call({ $route: { name: 'SecretTrash' } })).toBe('trashed')
		expect(computed.listState.call({ $route: { name: 'SecretArchive' } })).toBe('archived')
		expect(computed.listState.call({ $route: { name: 'SecretListFolder' } })).toBe('live')
		expect(computed.listState.call({ $route: undefined })).toBe('live')
	})

	it('walks the folder trail root-first and stops on a parent cycle', () => {
		const folders = [
			{ id: 'v', name: 'Vault', parentId: 'c' },
			{ id: 'c', name: 'Child', parentId: 'v' },
		]
		const trail = computed.folderTrail.call({ selectedFolderId: 'c', folders })
		expect(trail.map((f) => f.id)).toEqual(['v', 'c'])
		expect(computed.folderTrail.call({ selectedFolderId: null, folders })).toEqual([])
	})

	it('builds breadcrumbs: home, linked ancestors, unlinked current folder', () => {
		const folderTrail = [
			{ id: 'v', name: 'Vault' },
			{ id: 'f', name: 'Folder' },
		]
		expect(computed.breadcrumbs.call({ folderTrail })).toEqual([
			{ icon: 'Home', label: 'Vaults', to: { name: 'SecretList' } },
			{ label: 'Vault', to: { name: 'SecretListFolder', params: { folderId: 'v' } } },
			{ label: 'Folder' },
		])
		expect(computed.breadcrumbs.call({ folderTrail: [] })).toEqual([])
	})

	it('indexes only pending requests and derives the row request state', () => {
		const requests = useSecretRequestStore()
		requests.secretRequests = [
			{ id: 'r1', status: 'pending', secretId: 's-empty' },
			{ id: 'r2', status: 'pending', secretId: 's-full' },
			{ id: 'r3', status: 'fulfilled', secretId: 's-done' },
			{ id: 'r4', status: 'pending' },
			null,
		]
		const map = computed.pendingRequestsBySecret.call({})
		expect(Object.keys(map).sort()).toEqual(['s-empty', 's-full'])

		const ctx = { pendingRequestsBySecret: map }
		expect(methods.requestStateFor.call(ctx, { id: 's-empty', key: '' })).toBe('awaiting-fill')
		expect(methods.requestStateFor.call(ctx, { id: 's-full', key: 'abc' })).toBe('re-request')
		expect(methods.requestStateFor.call(ctx, { id: 's-done', key: '' })).toBeNull()
		expect(methods.requestStateFor.call(ctx, null)).toBeNull()
	})

	it('marks offline-pending rows and blanks the list mid folder-switch', () => {
		const secrets = [
			{ id: 'a', name: 'Mail', pendingSync: true },
			{ id: 'b', name: 'VPN' },
		]
		expect(computed.listObjects.call({ folderSwitching: false, secrets })).toEqual([
			{ id: 'a', name: 'Mail (Not synced yet)', pendingSync: true },
			{ id: 'b', name: 'VPN' },
		])
		expect(secrets[0].name).toBe('Mail')
		expect(computed.listObjects.call({ folderSwitching: true, secrets })).toEqual([])
	})

	it('derives pagination with at least one page', () => {
		expect(
			computed.pagination.call({ secretStore: { page: 2, totalCount: 101 } }),
		).toEqual({ page: 2, pages: 3, total: 101, limit: 50 })
		expect(computed.pagination.call({ secretStore: { page: 1, totalCount: 0 } }).pages).toBe(1)
	})

	it('flags the funnel active for any non-default filter or sort', () => {
		const base = { typeFilter: null, favouriteFilter: false, tagFilter: null, sortField: 'name' }
		const active = computed.filterMenuActive
		expect(active.call(base)).toBe(false)
		expect(active.call({ ...base, typeFilter: 't' })).toBe(true)
		expect(active.call({ ...base, favouriteFilter: true })).toBe(true)
		expect(active.call({ ...base, tagFilter: 'work' })).toBe(true)
		expect(active.call({ ...base, sortField: 'url' })).toBe(true)
	})

	it('labels tag options with their counts and resolves the folder name', () => {
		expect(
			computed.tagFilterOptions.call({
				secretStore: { tags: [{ tag: 'work', count: 3 }] },
			}),
		).toEqual([{ value: 'work', label: 'work (3)' }])
		const folders = [{ id: 'f-1', name: 'Work' }]
		expect(computed.selectedFolderName.call({ folders, selectedFolderId: 'f-1' })).toBe('Work')
		expect(computed.selectedFolderName.call({ folders, selectedFolderId: 'zz' })).toBe('')
	})
})

describe('SecretList — template wiring', () => {
	it('wires the action menu: select all, sends, deletion and the credential request dialog', async () => {
		const { secret } = stubNetwork()
		useSessionStore().cryptoKey = {}
		const { wrapper } = await mountList()
		secret.secrets = [{ id: 'a', name: 'Mail' }, { id: 'b', name: 'VPN' }]
		await flushPromises()

		await button(wrapper, 'Select all').trigger('click')
		expect(useBulkStore().selectedIds).toEqual(['a', 'b'])

		await button(wrapper, 'New ephemeral send').trigger('click')
		await button(wrapper, 'My ephemeral sends').trigger('click')
		await button(wrapper, 'Delete my Keepiq data').trigger('click')
		expect(wrapper.vm.newSendOpen).toBe(true)
		expect(wrapper.vm.mySendsOpen).toBe(true)
		expect(wrapper.vm.deletionOpen).toBe(true)

		await button(wrapper, 'Ask for a credential').trigger('click')
		const dialog = wrapper.findComponent(SecretRequestCreateDialog)
		expect(dialog.exists()).toBe(true)
		dialog.vm.$emit('update:open', false)
		await flushPromises()
		expect(wrapper.vm.credentialRequestOpen).toBe(false)
	})

	it('runs the export flow from the menu and reacts to the deletion and import dialogs', async () => {
		const { secret, folder } = stubNetwork()
		useSessionStore().cryptoKey = {}
		const { wrapper } = await mountList()
		secret.secrets = [{ id: 'a', name: 'Mail' }]

		await button(wrapper, 'Export data').trigger('click')
		await flushPromises()
		expect(secret.fetchAllSecrets).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.exportOpen).toBe(true)
		expect(wrapper.vm.decryptedSecrets).toEqual([{ id: 'a', name: 'Mail', key: 'plain' }])

		await button(wrapper, 'Import').trigger('click')
		expect(wrapper.vm.importOpen).toBe(true)

		folder.fetchFolders.mockClear()
		secret.fetchSecrets.mockClear()
		wrapper.findComponent(ImportWizardDialog).vm.$emit('imported')
		await flushPromises()
		expect(folder.fetchFolders).toHaveBeenCalledTimes(1)
		expect(secret.fetchSecrets).toHaveBeenCalledWith({ page: 1 })

		wrapper.vm.deletionOpen = true
		await flushPromises()
		wrapper.findComponent(AccountDeletionDialog).vm.$emit('exportFirst')
		await flushPromises()
		expect(wrapper.vm.deletionOpen).toBe(false)
		expect(secret.fetchAllSecrets).toHaveBeenCalledTimes(2)
	})

	it('wires the filter menu: type, favourites, tags and sort reach the store query', async () => {
		const { secret, types } = stubNetwork()
		types.types = [{ id: 'type-login', name: 'login', label: 'Login' }]
		secret.tags = [{ tag: 'work', count: 2 }]
		const { wrapper } = await mountList()

		await button(wrapper, 'Login').trigger('click')
		expect(secret.filters.typeId).toBe('type-login')
		await button(wrapper, 'All types').trigger('click')
		expect(secret.filters.typeId).toBeNull()

		await button(wrapper, 'Favourites').trigger('click')
		expect(secret.filters.favourite).toBe(true)

		await button(wrapper, 'work (2)').trigger('click')
		expect(secret.filters.tag).toBe('work')
		await button(wrapper, 'All tags').trigger('click')
		expect(secret.filters.tag).toBeNull()

		await button(wrapper, 'Last used').trigger('click')
		expect(secret.sort).toEqual({ field: 'last_used_at', direction: 'desc' })
		expect(wrapper.vm.filterMenuActive).toBe(true)
	})

	it('renders breadcrumbs and subfolder rows inside a folder; a row click navigates', async () => {
		const { folder } = stubNetwork()
		useSessionStore().cryptoKey = {}
		folder.folders = [
			{ id: 'v-1', name: 'Vault one', parentId: null },
			{ id: 'f-1', name: 'Subfolder', parentId: 'v-1' },
		]
		const { wrapper, router } = await mountList({
			route: { query: {}, params: { folderId: 'v-1' }, name: 'SecretListFolder' },
		})

		expect(wrapper.find('.secret-list-view__crumbs').exists()).toBe(true)
		const row = wrapper.find('[data-testid="folder-row-f-1"]')
		expect(row.text()).toContain('Subfolder')
		await row.trigger('click')
		expect(router.push).toHaveBeenCalledWith('/folders/f-1')
		expect(wrapper.text()).toContain('Team sharing')
		await button(wrapper, 'Team sharing').trigger('click')
		expect(wrapper.vm.teamFolderOpen).toBe(true)
	})

	it('opens each live bulk dialog from the selection strip and closes it again', async () => {
		stubNetwork()
		const { wrapper } = await mountList()

		await button(wrapper, 'Move').trigger('click')
		expect(wrapper.vm.bulkDialog).toBe('move')
		const move = wrapper.findComponent(BulkMoveDialog)
		expect(move.exists()).toBe(true)
		move.vm.$emit('close')
		await flushPromises()
		expect(wrapper.vm.bulkDialog).toBeNull()

		for (const [label, dialog] of [
			['Share', 'share'],
			['Add to team folder', 'teamFolder'],
			['Tags', 'tag'],
			['Archive', 'archive'],
			['Delete', 'delete'],
		]) {
			await button(wrapper, label).trigger('click')
			expect(wrapper.vm.bulkDialog).toBe(dialog)
		}
		expect(wrapper.findComponent(BulkStateDialog).exists()).toBe(false)
	})

	it('offers Restore / Delete for good in the trash and reloads after the state dialog', async () => {
		const { secret } = stubNetwork()
		const { wrapper } = await mountList({
			route: { query: {}, params: {}, name: 'SecretTrash' },
		})
		expect(secret.filters.state).toBe('trashed')
		expect(wrapper.findComponent(CnIndexPage).props('addLabel')).toBe('')

		await button(wrapper, 'Delete for good').trigger('click')
		const state = wrapper.findComponent(BulkStateDialog)
		expect(state.props('action')).toBe('purge')
		await button(wrapper, 'Restore').trigger('click')
		expect(wrapper.vm.bulkDialog).toBe('restore')

		secret.fetchSecrets.mockClear()
		secret.fetchTags.mockClear()
		wrapper.findComponent(BulkStateDialog).vm.$emit('done')
		await flushPromises()
		expect(secret.fetchSecrets).toHaveBeenCalledWith({ page: 1 })
		expect(secret.fetchTags).toHaveBeenCalledTimes(1)
	})

	it('offers Unarchive in the archive', async () => {
		stubNetwork()
		const { wrapper } = await mountList({
			route: { query: {}, params: {}, name: 'SecretArchive' },
		})
		await button(wrapper, 'Unarchive').trigger('click')
		expect(wrapper.vm.bulkDialog).toBe('unarchive')
		expect(wrapper.findComponent(CnIndexPage).props('title')).toBe('Archive')
	})

	it('forwards CnIndexPage events: refresh, add, search, select, row click and paging', async () => {
		vi.useFakeTimers({ now: new Date('2026-01-01T00:00:00Z') })
		const { secret } = stubNetwork()
		const { wrapper, router, cnOpenModal } = await mountList()
		const page = wrapper.findComponent(CnIndexPage)

		secret.fetchSecrets.mockClear()
		page.vm.$emit('refresh')
		await flushPromises()
		expect(secret.fetchSecrets).toHaveBeenCalledWith({ page: 1 })

		page.vm.$emit('pageChanged', 4)
		expect(secret.fetchSecrets).toHaveBeenLastCalledWith({ page: 4 })

		page.vm.$emit('add')
		expect(cnOpenModal).toHaveBeenCalledWith('secret-create', {
			folderId: null,
			onSaved: expect.any(Function),
		})

		page.vm.$emit('select', ['s-1'])
		expect(useBulkStore().selectedIds).toEqual(['s-1'])

		page.vm.$emit('rowClick', { id: 's-1' })
		expect(router.push).toHaveBeenCalledWith({ name: 'SecretList', params: { id: 's-1' } })

		page.vm.$emit('search', 'bank')
		vi.advanceTimersByTime(300)
		expect(secret.searchSecrets).toHaveBeenCalledWith('bank')
	})

	it('renders rows through every slot with the pending-sync marker', async () => {
		const { secret } = stubNetwork()
		const { wrapper } = await mountList()
		secret.secrets = [{ id: 'a', name: 'Mail', typeId: 't', pendingSync: true }]
		await flushPromises()

		expect(wrapper.findComponent(CnIndexPage).props('objects')).toEqual([
			{ id: 'a', name: 'Mail (Not synced yet)', typeId: 't', pendingSync: true },
		])
		expect(wrapper.find('.col-name').text()).toContain('Mail (Not synced yet)')
		expect(wrapper.find('.col-strength').text()).toBe('—')
	})
})
