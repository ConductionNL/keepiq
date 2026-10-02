/**
 * The popup's Vault tab: search and browse the vault, open an item to copy
 * or show its values, and add, edit or delete items. The worker decrypts
 * and encrypts; the popup only holds the open item's values, and drops them
 * when the item closes.
 *
 * @spec openspec/changes/clients-extension-generator-vault-send/specs/extension-vault/spec.md#requirement-browse-and-search-the-vault
 */

import { filterIndex, folderChoices, presentTypes } from '../lib/vault-index.js'

const MASK = '••••••••'

/**
 * Copy text, quietly doing nothing where the clipboard is unavailable.
 *
 * @param {string} text The text.
 * @return {Promise<void>}
 */
async function copyText(text) {
	try {
		await navigator.clipboard.writeText(text)
	} catch {
		// No clipboard (no focus, or not allowed): the value stays visible.
	}
}

/**
 * Replace a select's options after its first ("all") option.
 *
 * @param {HTMLSelectElement} select The select.
 * @param {Array<{value: string, label: string}>} options The options.
 * @param {Document} doc The popup document.
 */
function fillSelect(select, options, doc) {
	const keep = select.options[0]
	select.replaceChildren(keep)
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
 * @param {() => string|null} ctx.generatePassword A strong password for the edit form.
 * @param {(item: object) => void} ctx.sendItem Open the Send tab for an item.
 * @param {Document} [ctx.doc] The popup document.
 * @return {{open: () => Promise<void>}}
 */
export function initVault({
	$,
	send,
	showError,
	generatePassword,
	sendItem,
	doc = document,
}) {
	let index = []
	let folders = []
	// The open item's decrypted values; dropped on close.
	let current = null

	/**
	 * Show one of the three Vault views.
	 *
	 * @param {'vault-browse'|'vault-detail'|'vault-edit'} view The view id.
	 */
	function showView(view) {
		for (const id of ['vault-browse', 'vault-detail', 'vault-edit']) {
			$(id).hidden = id !== view
		}
		if (view !== 'vault-detail' && view !== 'vault-edit') {
			current = null
		}
	}

	/** Render the filtered list. */
	function renderList() {
		const entries = filterIndex(index, {
			query: $('vault-search').value,
			folderId: $('vault-folder').value || null,
			typeName: $('vault-type').value || null,
		})
		const list = $('vault-list')
		list.replaceChildren()
		$('vault-status').textContent =
			entries.length === 0 ? 'Nothing matches.' : `${entries.length} items`
		for (const entry of entries) {
			const li = doc.createElement('li')
			li.className = 'candidate'
			const button = doc.createElement('button')
			button.className = 'candidate-fill'
			button.textContent = entry.name + (entry.url ? ` (${entry.url})` : '')
			if (entry.blocked) {
				button.disabled = true
				button.title = 'Open this item in Keepiq in Nextcloud'
			} else {
				button.addEventListener('click', () => openItem(entry.id))
			}
			li.appendChild(button)
			list.appendChild(li)
		}
	}

	/**
	 * Open an item's detail view.
	 *
	 * @param {string} id The item id.
	 */
	async function openItem(id) {
		showError('detail-error', '')
		const item = await send('vault-item', { id })
		if (item.error) {
			$('vault-status').textContent = item.error
			return
		}
		current = item
		$('detail-name').textContent = item.name
		$('detail-url').textContent = item.url
		$('detail-login').textContent = item.login || '—'
		$('detail-secret').textContent = MASK
		$('detail-reveal').textContent = 'Show'
		$('detail-reveal').setAttribute('aria-pressed', 'false')
		showView('vault-detail')
	}

	/**
	 * Open the edit form, for a new item or the open one.
	 *
	 * @param {object|null} item The item to edit, or null for a new one.
	 */
	function openEdit(item) {
		showError('edit-error', '')
		$('edit-title').textContent = item ? 'Edit item' : 'New item'
		$('edit-name').value = item?.name || ''
		$('edit-url').value = item?.url || ''
		$('edit-login').value = item?.login || ''
		$('edit-secret').value = item?.secret || ''
		fillSelect(
			$('edit-folder'),
			folderChoices(folders).map((f) => ({ value: f.id, label: f.label })),
			doc,
		)
		$('edit-folder').value = item?.folderId || $('vault-folder').value || ''
		$('vault-edit').dataset.id = item?.id || ''
		showView('vault-edit')
		$('edit-name').focus()
	}

	/** Load the index, folders and types from the worker. */
	async function load() {
		$('vault-status').textContent = 'Loading…'
		const result = await send('vault-list')
		if (result.error) {
			$('vault-status').textContent = result.error
			return
		}
		index = result.items || []
		folders = result.folders || []
		fillSelect(
			$('vault-folder'),
			folderChoices(folders).map((f) => ({ value: f.id, label: f.label })),
			doc,
		)
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
	$('vault-new').addEventListener('click', () => openEdit(null))
	$('detail-back').addEventListener('click', () => showView('vault-browse'))
	$('detail-reveal').addEventListener('click', () => {
		if (!current) return
		const shown = $('detail-reveal').getAttribute('aria-pressed') === 'true'
		$('detail-secret').textContent = shown ? MASK : current.secret
		$('detail-reveal').textContent = shown ? 'Show' : 'Hide'
		$('detail-reveal').setAttribute('aria-pressed', shown ? 'false' : 'true')
	})
	$('detail-copy-login').addEventListener('click', () => {
		if (current) copyText(current.login || '')
	})
	$('detail-copy-secret').addEventListener('click', () => {
		if (current) copyText(current.secret || '')
	})
	$('detail-edit').addEventListener('click', () => openEdit(current))
	$('detail-send').addEventListener('click', () => {
		if (current) sendItem(current)
	})
	$('detail-delete').addEventListener('click', async () => {
		if (!current) return
		// The browser's own confirm: a popup cannot be clicked by a page.
		if (
			!window.confirm(
				`Move "${current.name}" to the trash? You can restore it in Keepiq in Nextcloud.`,
			)
		) {
			return
		}
		const res = await send('vault-trash', { id: current.id })
		if (res.error) {
			showError('detail-error', res.error)
			return
		}
		showView('vault-browse')
		await load()
	})
	$('edit-generate').addEventListener('click', () => {
		const value = generatePassword()
		if (value) {
			$('edit-secret').value = value
			$('edit-secret').type = 'text'
		}
	})
	$('edit-cancel').addEventListener('click', () => {
		showView(current ? 'vault-detail' : 'vault-browse')
	})
	$('vault-edit').addEventListener('submit', async (event) => {
		event.preventDefault()
		showError('edit-error', '')
		const id = $('vault-edit').dataset.id || undefined
		const res = await send('vault-save', {
			id,
			name: $('edit-name').value,
			url: $('edit-url').value.trim(),
			login: $('edit-login').value,
			secret: $('edit-secret').value,
			folderId: $('edit-folder').value || null,
		})
		// Keep the user's input on an error, so nothing they typed is lost.
		if (res.error) {
			showError('edit-error', res.error)
			return
		}
		$('edit-secret').value = ''
		$('edit-secret').type = 'password'
		showView('vault-browse')
		await load()
		const saved = res.id || id
		if (saved) await openItem(saved)
	})

	return {
		async open() {
			showView('vault-browse')
			await load()
		},
	}
}
