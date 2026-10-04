/**
 * The popup's Vault tab: search and browse the vault, open an item, and add,
 * edit, clone, move or delete items. The worker decrypts and encrypts; this
 * view holds the open item's values only while it is open.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-edit-every-kind-of-item
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-a-passkeys-private-key-stays-in-the-worker
 */

import {
	changedParts,
	COMPOSITE_LABELS,
	compositeFields,
	draftFromItem,
	formKind,
	MASKED_COMPOSITE,
	partsFromDraft,
	validateDraft,
} from '../lib/item-form.js'
import {
	filterIndex,
	folderChoices,
	listState,
	NO_FOLDER,
	presentTypes,
} from '../lib/vault-index.js'
import { copyText } from './clipboard.js'
import { relativeTime } from '../lib/generator-state.js'
import { initFolders } from './folder-view.js'
import { clearDetail, renderDetail } from './item-detail.js'

const UNSAVED = 'You have unsaved changes. Discard them?'
const NEW_FOLDER = '__new__'

/**
 * Replace a select's options after its first option.
 *
 * @param {HTMLSelectElement} select The select.
 * @param {Array<{value: string, label: string}>} options The options.
 * @param {Document} doc The popup document.
 */
function fillSelect(select, options, doc) {
	const keep = select.options[0]
	select.replaceChildren(...(keep ? [keep] : []))
	for (const { value, label } of options) {
		const option = doc.createElement('option')
		option.value = value
		option.textContent = label
		select.appendChild(option)
	}
}

/**
 * Wire the Vault tab.
 *
 * @param {object} ctx The popup context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {(type: string, payload?: object) => Promise<object>} ctx.send Message the worker.
 * @param {(id: string, message: string) => void} ctx.showError Show or clear an error.
 * @param {(kind: string, onPick: (value: string) => void) => Promise<void>} ctx.pickGenerated Open the Generator to pick a value for the form.
 * @param {(item: object) => void} ctx.sendItem Open the Send tab for an item.
 * @param {Document} [ctx.doc] The popup document.
 * @param {() => Promise<string>} [ctx.currentSite] The address of the site the popup is on.
 * @spec openspec/changes/clients-extension-finish/specs/extension-small-items/spec.md#requirement-a-form-that-starts-and-checks-sensibly
 * @return {{open: () => Promise<void>, canLeave: () => boolean}}
 */
export function initVault({
	$,
	send,
	showError,
	pickGenerated,
	sendItem,
	doc = document,
	currentSite = null,
}) {
	let index = []
	// Whether the first list has arrived.
	let loaded = false
	// The type of a new item (Settings).
	let preferredType = 'login'
	// The address of the site the popup is on, for a new item.
	let siteOrigin = ''
	// Where the list was scrolled when an item opened.
	let listScroll = 0
	let folders = []
	let types = []
	let webAppUrl = ''
	// Whether the server could not be reached on the last sync.
	let offline = false

	/** The buttons that change the vault, disabled while offline. */
	const WRITE_CONTROLS = [
		'vault-new',
		'detail-edit',
		'detail-clone',
		'detail-move',
		'detail-delete',
		'edit-save',
		'folder-add-save',
		'folder-delete-confirm',
	]

	/**
	 * Show the sync status, and allow or block writes.
	 *
	 * @param {object|null} status The sync status from the worker.
	 */
	function renderSync(status) {
		offline = status?.offline === true
		$('vault-sync').textContent = status?.syncedAt
			? `Last synced ${relativeTime(Date.parse(status.syncedAt))}${offline ? ' (offline)' : ''}`
			: offline
				? 'Offline'
				: ''
		$('vault-offline').hidden = !offline
		for (const id of WRITE_CONTROLS) {
			const el = $(id)
			if (el) el.disabled = offline || el.dataset.blocked === 'true'
		}
	}
	// The open item, decrypted; dropped when it closes.
	let current = null
	// The form: its type and the parts it opened with, to detect changes.
	let form = null

	/**
	 * Show one of the three Vault views; leaving the detail drops its values.
	 *
	 * @param {'vault-browse'|'vault-detail'|'vault-edit'} view The view id.
	 */
	function showView(view) {
		for (const id of [
			'vault-browse',
			'vault-detail',
			'vault-edit',
			'vault-folders',
		]) {
			$(id).hidden = id !== view
		}
		if (view !== 'vault-edit') form = null
		if (view === 'vault-browse') {
			current = null
			clearDetail({ $ })
		}
	}

	/** @return {Array<{value: string, label: string}>} Folder choices. */
	const folderOptions = () => [
		{ value: NO_FOLDER, label: 'No folder' },
		...folderChoices(folders).map((f) => ({ value: f.id, label: f.label })),
	]

	/** Render the filtered list. */
	function renderList() {
		const entries = filterIndex(index, {
			query: $('vault-search').value,
			folderId: $('vault-folder').value || null,
			typeName: $('vault-type').value || null,
		})
		const list = $('vault-list')
		list.replaceChildren()
		const state = listState(loaded ? index : null, entries)
		$('vault-status').textContent = {
			loading: 'Loading your vault…',
			empty: 'Your vault is empty. Add an item with New.',
			'no-match': 'Nothing matches.',
			'all-blocked':
				'Every item is blocked here: its key cannot be used in this browser.',
			items: `${entries.length} items`,
		}[state]
		$('vault-clear').hidden = state !== 'no-match'
		for (const entry of entries) list.appendChild(card(entry))
	}

	/**
	 * One item in the list: its name, type and site, Copy and Open.
	 *
	 * @param {object} entry The index entry.
	 * @return {HTMLLIElement}
	 * @spec openspec/changes/clients-extension-gaps/specs/extension-list-and-settings/spec.md#requirement-a-list-that-says-what-it-shows
	 */
	function card(entry) {
		const li = doc.createElement('li')
		li.className = 'candidate row vault-card'
		const button = doc.createElement('button')
		button.className = 'candidate-fill'
		const name = doc.createElement('span')
		name.className = 'vault-card-name'
		name.textContent = entry.name
		const meta = doc.createElement('span')
		meta.className = 'vault-card-meta'
		let host = ''
		try {
			host = entry.url ? new URL(entry.url).host : ''
		} catch {
			host = entry.url
		}
		meta.textContent = [entry.typeName, host, entry.blocked ? 'blocked' : '']
			.filter(Boolean)
			.join(' · ')
		button.append(name, meta)
		button.addEventListener('click', () => openItem(entry.id))
		li.appendChild(button)
		const kind = formKind(entry.typeName)
		if (!entry.blocked && (kind === 'login' || kind === 'generic')) {
			const copy = doc.createElement('button')
			copy.type = 'button'
			copy.className = 'link'
			copy.textContent = 'Copy'
			copy.setAttribute('aria-label', `Copy the password of ${entry.name}`)
			copy.addEventListener('click', async () => {
				const item = await send('vault-item', { id: entry.id })
				if (item.error) return showError('vault-status', item.error)
				if (item.secret) await copyText(item.secret)
			})
			li.appendChild(copy)
		}
		if (host && /^https?:\/\//i.test(entry.url)) {
			const open = doc.createElement('button')
			open.type = 'button'
			open.className = 'link'
			open.textContent = 'Open'
			open.setAttribute('aria-label', `Open ${host}`)
			open.addEventListener('click', () =>
				chrome.tabs.create({ url: entry.url }),
			)
			li.appendChild(open)
		}
		return li
	}

	/**
	 * Open an item's detail view, fetched fresh.
	 *
	 * @param {string} id The item id.
	 */
	async function openItem(id) {
		listScroll = doc.body.scrollTop
		showError('detail-error', '')
		const item = await send('vault-item', { id })
		if (item.error) {
			$('vault-status').textContent = item.error
			return
		}
		current = item
		$('detail-move-picker').hidden = true
		renderDetail({ $, doc }, item, folders)
		showView('vault-detail')
	}

	/**
	 * The current draft, read from the form.
	 *
	 * @return {object}
	 */
	function readDraft() {
		const kind = form.kind
		const composite = {}
		for (const field of compositeFields(kind)) {
			composite[field] = $('edit-composite-' + field).value
		}
		const fields = [...$('edit-fields').querySelectorAll('.field-edit')].map(
			(row) => ({
				name: row.querySelector('.field-name').value,
				value: row.querySelector('.field-value').value,
			}),
		)
		return {
			kind,
			name: $('edit-name').value,
			url: $('edit-url').value,
			folderId: $('edit-folder').value || null,
			login: $('edit-login').value,
			secret: $('edit-secret').value,
			notes: $('edit-notes').value,
			composite,
			fields,
		}
	}

	/** @return {boolean} Whether the form differs from how it opened. */
	const dirty = () =>
		form !== null
		&& Object.keys(changedParts(form.initialParts, partsFromDraft(readDraft())))
			.length > 0

	/**
	 * Whether the user may leave the form: no changes, or they discard them.
	 *
	 * @return {boolean}
	 */
	function canLeave() {
		return !dirty() || window.confirm(UNSAVED)
	}

	/**
	 * Add one additional-field row.
	 *
	 * @param {string} name The field name.
	 * @param {string} value The field value.
	 */
	function addFieldRow(name = '', value = '') {
		const row = doc.createElement('div')
		row.className = 'field-edit row'
		const nameInput = doc.createElement('input')
		nameInput.className = 'field-name'
		nameInput.placeholder = 'Field name'
		nameInput.setAttribute('aria-label', 'Field name')
		nameInput.maxLength = 4096
		nameInput.value = name
		const valueInput = doc.createElement('input')
		valueInput.className = 'field-value'
		valueInput.type = 'password'
		valueInput.setAttribute('aria-label', 'Field value')
		valueInput.value = value
		const show = doc.createElement('button')
		show.type = 'button'
		show.className = 'link'
		show.textContent = 'Show'
		show.setAttribute('aria-pressed', 'false')
		show.addEventListener('click', () => {
			const open = valueInput.type === 'text'
			valueInput.type = open ? 'password' : 'text'
			show.textContent = open ? 'Show' : 'Hide'
			show.setAttribute('aria-pressed', open ? 'false' : 'true')
		})
		const remove = doc.createElement('button')
		remove.type = 'button'
		remove.className = 'link danger'
		remove.textContent = 'Remove'
		remove.setAttribute('aria-label', 'Remove this field')
		remove.addEventListener('click', () => row.remove())
		row.append(nameInput, valueInput, show, remove)
		$('edit-fields').appendChild(row)
	}

	/**
	 * Show the inputs for a form kind.
	 *
	 * @param {string} kind The form kind.
	 */
	function layoutFor(kind) {
		$('edit-login-block').hidden = !(kind === 'login' || kind === 'generic')
		$('edit-secret-block').hidden = !(
			kind === 'login'
			|| kind === 'generic'
			|| kind === 'totp'
		)
		$('edit-generate').hidden = kind !== 'login' && kind !== 'generic'
		$('edit-secret-label').firstChild.textContent =
			kind === 'totp'
				? 'Secret key or otpauth:// address'
				: kind === 'login'
					? 'Password'
					: 'Value'
		const composite = $('edit-composite')
		composite.replaceChildren()
		for (const field of compositeFields(kind)) {
			const label = doc.createElement('label')
			label.textContent = COMPOSITE_LABELS[field]
			const input = doc.createElement('input')
			input.id = 'edit-composite-' + field
			input.type = MASKED_COMPOSITE.includes(field) ? 'password' : 'text'
			input.autocomplete = 'off'
			input.maxLength = 4096
			label.appendChild(input)
			composite.appendChild(label)
		}
	}

	/**
	 * Open the form for a new item, an edit or a clone.
	 *
	 * @param {object|null} item The decrypted item, or null for a new one.
	 * @param {{clone?: boolean}} [how] clone: prefill from item, save as new.
	 */
	function openEdit(item, { clone = false } = {}) {
		showError('edit-error', '')
		const creating = !item || clone
		// A new item starts with the type picked in Settings.
		const typeId =
			item?.typeId
			|| types.find((t) => t.name === preferredType)?.id
			|| types.find((t) => t.name === 'login')?.id
			|| types[0]?.id
			|| ''
		$('edit-title').textContent = clone
			? 'Clone item'
			: item
				? 'Edit item'
				: 'New item'
		$('edit-type-label').hidden = !creating || clone
		fillSelect(
			$('edit-type'),
			// Passkeys are created by the website that uses them.
			types
				.filter((t) => t.name !== 'passkey')
				.map((t) => ({ value: t.id, label: t.name })),
			doc,
		)
		$('edit-type').value = typeId
		const typeName =
			types.find((t) => t.id === typeId)?.name || item?.typeName || 'login'
		const draft = draftFromItem(item, typeName)
		if (clone) draft.name += ' - Clone'
		// A new item starts with the address of the site the popup is on.
		const fresh = creating && !clone
		if (fresh && !draft.url) draft.url = siteOrigin
		fillEditFolders()
		layoutFor(draft.kind)
		$('edit-name').value = draft.name
		$('edit-url').value = draft.url
		$('edit-login').value = draft.login
		$('edit-secret').value = draft.secret
		$('edit-secret').type = 'password'
		$('edit-notes').value = draft.notes
		$('edit-folder').value =
			draft.folderId
			|| (creating && !clone ? $('vault-folder').value : '')
			|| ''
		for (const field of compositeFields(draft.kind)) {
			$('edit-composite-' + field).value = draft.composite[field] || ''
		}
		$('edit-fields').replaceChildren()
		for (const { name, value } of draft.fields) addFieldRow(name, value)
		form = {
			kind: draft.kind,
			typeId,
			typeName,
			id: creating ? null : item.id,
			// A clone or new item has nothing on the server yet: every part is new.
			initialParts: creating
				? partsFromDraft({
						...draftFromItem(null, typeName),
						url: fresh ? siteOrigin : '',
					})
				: partsFromDraft(draftFromItem(item, typeName)),
		}
		showView('vault-edit')
		$('edit-name').focus()
	}

	/** Fill the form's folder picker, with "New folder…" at the end. */
	function fillEditFolders() {
		fillSelect(
			$('edit-folder'),
			[...folderOptions(), { value: NEW_FOLDER, label: 'New folder…' }],
			doc,
		)
	}

	/** Reload the folders only, keeping the form as it is. */
	async function reloadFolders() {
		const result = await send('vault-list')
		if (result.error) return
		folders = result.folders || []
		fillEditFolders()
	}

	/** Load the index, folders and types from the worker. */
	async function load() {
		$('vault-status').textContent = 'Loading your vault…'
		const result = await send('vault-list')
		if (result.error) {
			$('vault-status').textContent = result.error
			return
		}
		index = result.items || []
		loaded = true
		preferredType = (await send('extension-settings')).defaultType || 'login'
		siteOrigin = (await currentSite?.()) || ''
		folders = result.folders || []
		types = result.types || []
		webAppUrl = result.webAppUrl || ''
		renderSync(result.sync)
		fillSelect($('vault-folder'), folderOptions(), doc)
		fillSelect(
			$('vault-type'),
			presentTypes(index).map((t) => ({ value: t, label: t })),
			doc,
		)
		renderList()
	}

	for (const id of ['vault-search', 'vault-folder', 'vault-type']) {
		$(id).addEventListener('input', renderList)
	}
	$('vault-clear').addEventListener('click', () => {
		$('vault-search').value = ''
		$('vault-folder').value = ''
		$('vault-type').value = ''
		renderList()
	})
	const folderView = initFolders({
		$,
		send,
		showError,
		getFolders: () => folders,
		reload: load,
		doc,
	})
	$('vault-sync-now').addEventListener('click', async () => {
		$('vault-sync').textContent = 'Syncing…'
		await send('vault-sync-now')
		await load()
	})
	$('vault-folders-open').addEventListener('click', async () => {
		showView('vault-folders')
		await folderView.render()
	})
	$('folders-back').addEventListener('click', () => showView('vault-browse'))
	// The form's folder picker can make a folder on the spot.
	$('edit-folder').addEventListener('change', async () => {
		if ($('edit-folder').value !== NEW_FOLDER) return
		const name = window.prompt('Name of the new folder')
		if (!name) {
			$('edit-folder').value = ''
			return
		}
		const res = await send('folder-create', { name, parentId: null })
		if (res.error) {
			$('edit-folder').value = ''
			return showError('edit-error', res.error)
		}
		const keep = readDraft()
		await reloadFolders()
		$('edit-folder').value = res.id || ''
		$('edit-name').value = keep.name
	})
	$('vault-new').addEventListener('click', () => openEdit(null))
	$('detail-back').addEventListener('click', () => {
		showView('vault-browse')
		// Back where the user was in the list.
		doc.body.scrollTop = listScroll
	})
	$('detail-open-web').addEventListener('click', () => {
		if (webAppUrl) chrome.tabs.create({ url: webAppUrl })
	})
	$('detail-edit').addEventListener('click', async () => {
		if (!current) return
		// Edit starts from the server's current value, not the open view.
		const fresh = await send('vault-item', { id: current.id })
		if (fresh.error) return showError('detail-error', fresh.error)
		current = fresh
		openEdit(fresh)
	})
	$('detail-clone').addEventListener(
		'click',
		() => current && openEdit(current, { clone: true }),
	)
	$('detail-send').addEventListener('click', () => current && sendItem(current))
	$('detail-move').addEventListener('click', () => {
		fillSelect($('detail-move-folder'), folderOptions(), doc)
		$('detail-move-folder').value = current?.folderId || ''
		$('detail-move-picker').hidden = false
	})
	$('detail-move-cancel').addEventListener('click', () => {
		$('detail-move-picker').hidden = true
	})
	$('detail-move-save').addEventListener('click', async () => {
		const res = await send('vault-move', {
			id: current.id,
			folderId: $('detail-move-folder').value || null,
		})
		if (res.error) return showError('detail-error', res.error)
		const id = current.id
		await load()
		await openItem(id)
	})
	$('detail-delete').addEventListener('click', async () => {
		if (!current) return
		// The browser's own confirm: a page cannot click it.
		if (
			!window.confirm(
				`Move "${current.name}" to the trash? You can restore it in Keepiq in Nextcloud.`,
			)
		) {
			return
		}
		const res = await send('vault-trash', { id: current.id })
		if (res.error) return showError('detail-error', res.error)
		showView('vault-browse')
		await load()
	})
	$('edit-type').addEventListener('change', () => {
		const typeName =
			types.find((t) => t.id === $('edit-type').value)?.name || 'login'
		form.kind = formKind(typeName)
		form.typeId = $('edit-type').value
		form.typeName = typeName
		form.initialParts = partsFromDraft(draftFromItem(null, typeName))
		layoutFor(form.kind)
	})
	$('edit-field-add').addEventListener('click', () => addFieldRow())
	$('edit-generate').addEventListener('click', () =>
		pickGenerated('password', (value) => {
			$('edit-secret').value = value
			$('edit-secret').type = 'text'
		}),
	)
	$('edit-generate-username').addEventListener('click', () =>
		pickGenerated('username', (value) => {
			$('edit-login').value = value
		}),
	)
	$('edit-cancel').addEventListener('click', () => {
		if (!canLeave()) return
		form = null
		if (current) {
			renderDetail({ $, doc }, current, folders)
			showView('vault-detail')
		} else {
			showView('vault-browse')
		}
	})
	$('vault-edit').addEventListener('submit', async (event) => {
		event.preventDefault()
		showError('edit-error', '')
		const draft = readDraft()
		const errors = validateDraft(draft)
		if (Object.keys(errors).length > 0) {
			showError('edit-error', Object.values(errors)[0])
			return
		}
		const parts = partsFromDraft(draft)
		const changes = form.id ? changedParts(form.initialParts, parts) : parts
		if (form.id && Object.keys(changes).length === 0) {
			form = null
			renderDetail({ $, doc }, current, folders)
			return showView('vault-detail')
		}
		const res = await send('vault-save', {
			id: form.id || undefined,
			typeId: form.typeId,
			typeName: form.typeName,
			changes,
		})
		// On an error the form keeps everything the user typed.
		if (res.error) return showError('edit-error', res.error)
		$('edit-secret').type = 'password'
		form = null
		showView('vault-browse')
		await load()
		const saved = res.id
		if (saved) await openItem(saved)
	})

	return {
		async open() {
			showView('vault-browse')
			await load()
		},
		canLeave,

		/**
		 * Drop everything this view holds of the vault: the open item, the
		 * form, the list. Called when the worker locks.
		 *
		 * @spec openspec/changes/clients-extension-gaps/specs/extension-lock/spec.md#requirement-the-popup-forgets-the-vault-when-it-locks
		 */
		forget() {
			current = null
			form = null
			index = []
			loaded = false
			clearDetail({ $ })
			for (const el of $('vault-edit').querySelectorAll('input, textarea')) {
				if (el.type !== 'checkbox' && el.type !== 'radio') el.value = ''
			}
			$('edit-fields').replaceChildren()
			$('vault-list').replaceChildren()
			showView('vault-browse')
		},
	}
}
