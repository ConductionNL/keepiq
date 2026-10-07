/**
 * The folder manager in the Vault tab: add, rename and delete folders. A
 * folder that holds items or subfolders is deleted only with the user's
 * choice for what it holds, as the server's deletion protocol requires.
 *
 * @spec openspec/specs/extension-vault/spec.md#requirement-manage-folders
 */

import {
	deleteKind,
	deleteRequest,
	folderNameProblem,
	folderTree,
} from '../lib/folder-rules.js'
import { folderChoices } from '../lib/vault-index.js'

const NOTICE_KEY = 'folders-notice-seen'

/**
 * Wire the folder manager.
 *
 * @param {object} ctx The popup context.
 * @param {(id: string) => HTMLElement} ctx.$ Element by id.
 * @param {(type: string, payload?: object) => Promise<object>} ctx.send Message the worker.
 * @param {(id: string, message: string) => void} ctx.showError Show or clear an error.
 * @param {() => Array<object>} ctx.getFolders The current folders.
 * @param {() => Promise<void>} ctx.reload Reload the vault after a change.
 * @param {Document} [ctx.doc] The popup document.
 * @return {{render: () => Promise<void>}}
 */
export function initFolders({
	$,
	send,
	showError,
	getFolders,
	reload,
	doc = document,
}) {
	// The folder being deleted and what the server said it holds.
	let pending = null

	/** Fill the parent picker of the add form. */
	function fillParents() {
		const select = $('folder-add-parent')
		select.replaceChildren(select.options[0])
		for (const { id, label } of folderChoices(getFolders())) {
			const option = doc.createElement('option')
			option.value = id
			option.textContent = label
			select.appendChild(option)
		}
	}

	/**
	 * A button.
	 *
	 * @param {string} text The text.
	 * @param {string} label The accessible name.
	 * @param {Function} onClick The action.
	 * @return {HTMLButtonElement}
	 */
	function button(text, label, onClick) {
		const el = doc.createElement('button')
		el.type = 'button'
		el.className = 'link'
		el.textContent = text
		el.setAttribute('aria-label', label)
		el.addEventListener('click', onClick)
		return el
	}

	/**
	 * Turn a row into a rename field.
	 *
	 * @param {HTMLElement} li The row.
	 * @param {{id: string, name: string}} folder The folder.
	 */
	function startRename(li, folder) {
		const input = doc.createElement('input')
		input.value = folder.name
		input.maxLength = 255
		input.setAttribute('aria-label', `New name for ${folder.name}`)
		const save = button('Save', `Save the name of ${folder.name}`, async () => {
			showError('folders-error', '')
			const res = await send('folder-rename', {
				id: folder.id,
				name: input.value,
			})
			// On an error the typed name stays.
			if (res.error) return showError('folders-error', res.error)
			await reload()
			await render()
		})
		const cancel = button('Cancel', 'Cancel renaming', () => render())
		li.replaceChildren(input, save, cancel)
		input.focus()
	}

	/**
	 * Ask what the folder holds and show the matching delete dialog.
	 *
	 * @param {{id: string, name: string, parentId: string|null}} folder The folder.
	 */
	async function startDelete(folder) {
		showError('folders-error', '')
		const children = await send('folder-children', { id: folder.id })
		if (children.error) return showError('folders-error', children.error)
		const kind = deleteKind(children)
		const parent = getFolders().find((f) => f.id === folder.parentId)
		const parentName = parent ? parent.name : 'No folder'
		pending = { folder, kind, children }
		$('folder-delete-items').options[0].textContent = `Move to ${parentName}`
		$('folder-delete-items').value = 'move'
		$('folder-delete-items-label').hidden =
			kind === 'empty'
			|| (kind === 'subfolders' && children.directSecretCount === 0)
		const list = $('folder-delete-subfolders')
		list.replaceChildren()
		if (kind === 'empty') {
			$('folder-delete-text').textContent =
				`Delete ${folder.name}? This cannot be undone.`
		} else if (kind === 'items') {
			$('folder-delete-text').textContent =
				`${folder.name} holds ${children.directSecretCount} items. Move them to ${parentName}, or delete them too?`
		} else {
			$('folder-delete-text').textContent =
				`${folder.name} holds subfolders. Choose what happens to each.`
			for (const sub of children.subfolders) {
				const label = doc.createElement('label')
				label.textContent = `${sub.name} (${sub.secretCount} items, ${sub.subfolderCount} subfolders)`
				const select = doc.createElement('select')
				select.dataset.subfolder = sub.id
				for (const [value, text] of [
					['keep', `Keep it, inside ${parentName}`],
					['move', `Move its items to ${parentName}`],
					['delete', 'Delete it and its items'],
				]) {
					const option = doc.createElement('option')
					option.value = value
					option.textContent = text
					select.appendChild(option)
				}
				label.appendChild(select)
				list.appendChild(label)
			}
		}
		$('folder-delete').hidden = false
	}

	/** Render the notice, the add form and the tree. */
	async function render() {
		$('folder-delete').hidden = true
		pending = null
		let seen = false
		try {
			seen = (await chrome.storage.local.get(NOTICE_KEY))[NOTICE_KEY] === true
		} catch {
			seen = false
		}
		$('folders-notice').hidden = seen
		fillParents()
		// Folder changes need the server: off while it cannot be reached.
		const offline = $('vault-offline').hidden === false
		$('folder-add-save').disabled = offline
		showError(
			'folders-error',
			offline ? 'You are offline. Changes need a connection to Keepiq.' : '',
		)
		const tree = $('folder-tree')
		tree.replaceChildren()
		for (const folder of folderTree(getFolders())) {
			const li = doc.createElement('li')
			li.className = 'candidate row'
			li.style.paddingLeft = `${folder.depth * 16}px`
			const name = doc.createElement('span')
			name.className = 'folder-name'
			name.textContent = folder.name
			li.append(
				name,
				button('Rename', `Rename ${folder.name}`, () =>
					startRename(li, folder),
				),
				button('Delete', `Delete ${folder.name}`, () => startDelete(folder)),
			)
			for (const action of li.querySelectorAll('button')) {
				action.disabled = offline
			}
			tree.appendChild(li)
		}
	}

	$('folders-notice-ok').addEventListener('click', async () => {
		await chrome.storage.local.set({ [NOTICE_KEY]: true }).catch(() => {})
		$('folders-notice').hidden = true
	})
	$('folder-add').addEventListener('submit', async (event) => {
		event.preventDefault()
		showError('folders-error', '')
		const problem = folderNameProblem($('folder-add-name').value)
		if (problem) return showError('folders-error', problem)
		const res = await send('folder-create', {
			name: $('folder-add-name').value,
			parentId: $('folder-add-parent').value || null,
		})
		if (res.error) return showError('folders-error', res.error)
		$('folder-add-name').value = ''
		await reload()
		await render()
	})
	$('folder-delete-cancel').addEventListener('click', () => {
		$('folder-delete').hidden = true
		pending = null
	})
	$('folder-delete-confirm').addEventListener('click', async () => {
		if (!pending) return
		const subfolders = {}
		for (const select of $('folder-delete-subfolders').querySelectorAll(
			'select',
		)) {
			subfolders[select.dataset.subfolder] = select.value
		}
		const request = deleteRequest(
			pending.kind,
			{ items: $('folder-delete-items').value, subfolders },
			pending.children.subfolders,
		)
		const res = await send('folder-delete', {
			id: pending.folder.id,
			...request,
		})
		if (res.error) return showError('folders-error', res.error)
		await reload()
		await render()
	})

	return { render }
}
