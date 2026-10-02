/**
 * @spec openspec/changes/clients-extension-complete/specs/extension-vault/spec.md#requirement-manage-folders
 *
 * The folder manager: name rules, the tree, the delete request the server's
 * protocol expects, and the flows on the REAL popup and router.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
	deleteKind,
	deleteRequest,
	folderNameProblem,
	folderTree,
} from '../../browser-extension/src/lib/folder-rules.js'
import {
	installChrome,
	installServer,
	makeVault,
	POPUP,
} from './fixtures/fakeBrowser.js'

describe('folder rules', () => {
	it('refuses blank names and slashes', () => {
		expect(folderNameProblem(' ')).toBe('Name is required')
		expect(folderNameProblem('a/b')).toBe('Folder names cannot contain slashes')
		expect(folderNameProblem('Work')).toBeNull()
	})

	it('builds a tree, siblings by name', () => {
		const tree = folderTree([
			{ id: 'c', name: 'clients', parentId: 'w' },
			{ id: 'w', name: 'Work', parentId: null },
			{ id: 'h', name: 'Home', parentId: null },
			{ id: 'a', name: 'Archive', parentId: 'w' },
		])
		expect(tree.map((f) => `${f.depth}:${f.name}`)).toEqual([
			'0:Home',
			'0:Work',
			'1:Archive',
			'1:clients',
		])
	})

	it('asks the server for a cascade or a full plan', () => {
		expect(deleteKind({ directSecretCount: 0, subfolders: [] })).toBe('empty')
		expect(deleteKind({ directSecretCount: 3, subfolders: [] })).toBe('items')
		expect(deleteKind({ directSecretCount: 0, subfolders: [{ id: 's' }] })).toBe(
			'subfolders',
		)
		expect(deleteRequest('items', { items: 'delete' })).toEqual({
			cascade: 'delete',
		})
		expect(deleteRequest('items', {})).toEqual({ cascade: 'move' })
		expect(
			deleteRequest(
				'subfolders',
				{ items: 'move', subfolders: { s1: 'delete' } },
				[{ id: 's1' }, { id: 's2' }],
			),
		).toEqual({
			resolution: {
				directSecrets: 'move',
				subfolders: { s1: 'delete', s2: 'keep' },
			},
		})
	})
})

const SERVER = 'https://one.example'
let router
let server
let state

/**
 * Element by id.
 *
 * @param {string} id The id.
 * @return {HTMLElement}
 */
const $ = (id) => document.getElementById(id)

/** Open the popup on the folder manager. */
async function openFolders() {
	const html = readFileSync(
		resolve(__dirname, '../../browser-extension/src/popup/popup.html'),
		'utf8',
	)
	document.body.innerHTML = html
		.replace(/^[\s\S]*<body>/, '')
		.replace(/<\/body>[\s\S]*$/, '')
	globalThis.chrome.runtime.sendMessage = (msg, cb) => {
		const pending = router.handleMessage(msg, POPUP)
		if (pending) pending.then(cb)
	}
	vi.resetModules()
	await import('../../browser-extension/src/popup/popup.js')
	await vi.waitFor(() => expect($('view-unlocked').hidden).toBe(false))
	$('tab-vault').click()
	await vi.waitFor(() =>
		expect($('vault-list').children.length).toBeGreaterThan(0),
	)
	$('vault-folders-open').click()
	await vi.waitFor(() =>
		expect($('folder-tree').children.length).toBeGreaterThan(0),
	)
}

/**
 * The row of a folder in the tree.
 *
 * @param {string} name The folder name.
 * @return {HTMLElement}
 */
function rowOf (name) {
  return [...$('folder-tree').children].find(
		(li) => li.querySelector('.folder-name')?.textContent === name,
	)
}

beforeEach(async () => {
	vi.resetModules()
	installChrome()
	const fixture = await makeVault('m', 'x')
	state = {
		...fixture,
		types: [{ id: 't1', name: 'login' }],
		folders: [
			{ id: 'w', name: 'Work', parentId: null },
			{ id: 'c', name: 'Clients', parentId: 'w' },
			{ id: 'e', name: 'Empty', parentId: null },
		],
		children: {
			w: {
				directSecretCount: 2,
				subfolders: [
					{ id: 'c', name: 'Clients', secretCount: 1, subfolderCount: 0 },
				],
			},
			c: { directSecretCount: 1, subfolders: [] },
			e: { directSecretCount: 0, subfolders: [] },
		},
	}
	server = installServer({ [SERVER]: state })
	router = await import('../../browser-extension/src/background/router.js')
	await router.handleMessage(
		{ type: 'pair', payload: { url: SERVER, user: 'ann', appPassword: 'p' } },
		POPUP,
	)
	await router.handleMessage(
		{ type: 'unlock', payload: { masterPassword: 'm' } },
		POPUP,
	)
})

afterEach(async () => {
	await new Promise((r) => setTimeout(r, 300))
	document.body.innerHTML = ''
})

describe('folder manager', () => {
	it('shows the tree and the not-encrypted notice once', async () => {
		await openFolders()
		expect(
			[...$('folder-tree').children].map(
				(li) => li.querySelector('.folder-name').textContent,
			),
		).toEqual(['Empty', 'Work', 'Clients'])
		expect($('folders-notice').hidden).toBe(false)
		$('folders-notice-ok').click()
		await vi.waitFor(() => expect($('folders-notice').hidden).toBe(true))
		await openFolders()
		expect($('folders-notice').hidden).toBe(true)
	})

	it('adds a folder inside another, and refuses a slash', async () => {
		await openFolders()
		$('folder-add-name').value = 'a/b'
		$('folder-add').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() =>
			expect($('folders-error').textContent).toBe(
				'Folder names cannot contain slashes',
			),
		)
		$('folder-add-name').value = 'Invoices'
		$('folder-add-parent').value = 'w'
		$('folder-add').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect(rowOf('Invoices')).toBeTruthy())
		expect(
			server.calls.find(
				(c) => c.method === 'POST' && c.url.endsWith('/api/v1/folders'),
			).body,
		).toEqual({ name: 'Invoices', parentId: 'w' })
	})

	it('renames a folder, sending only its name', async () => {
		await openFolders()
		rowOf('Empty').querySelector('button[aria-label="Rename Empty"]').click()
		// The row turned into a name field.
		const input = $('folder-tree').querySelector('input')
		input.value = 'Spare'
		$('folder-tree')
			.querySelector('button[aria-label="Save the name of Empty"]')
			.click()
		await vi.waitFor(() => expect(rowOf('Spare')).toBeTruthy())
		expect(server.calls.find((c) => c.method === 'PUT').body).toEqual({
			name: 'Spare',
		})
	})

	it('deletes an empty folder plainly, and a leaf with items with the chosen cascade', async () => {
		await openFolders()
		rowOf('Empty').querySelector('button[aria-label="Delete Empty"]').click()
		await vi.waitFor(() => expect($('folder-delete').hidden).toBe(false))
		expect($('folder-delete-text').textContent).toBe(
			'Delete Empty? This cannot be undone.',
		)
		$('folder-delete-confirm').click()
		await vi.waitFor(() => expect(rowOf('Empty')).toBeFalsy())
		expect(server.calls.find((c) => c.method === 'DELETE').url).toMatch(
			/\/api\/v1\/folders\/e$/,
		)

		rowOf('Clients').querySelector('button[aria-label="Delete Clients"]').click()
		await vi.waitFor(() =>
			expect($('folder-delete-text').textContent).toContain('holds 1 items'),
		)
		$('folder-delete-items').value = 'delete'
		$('folder-delete-confirm').click()
		await vi.waitFor(() =>
			expect(server.calls.filter((c) => c.method === 'DELETE')).toHaveLength(
				2,
			),
		)
		expect(server.calls.filter((c) => c.method === 'DELETE')[1].url).toMatch(
			/\/folders\/c\?cascade=delete$/,
		)
	})

	it('deletes a folder with subfolders with a plan for every subfolder', async () => {
		await openFolders()
		rowOf('Work').querySelector('button[aria-label="Delete Work"]').click()
		await vi.waitFor(() =>
			expect(
				$('folder-delete-subfolders').querySelectorAll('select'),
			).toHaveLength(1),
		)
		$('folder-delete-subfolders').querySelector('select').value = 'move'
		$('folder-delete-confirm').click()
		await vi.waitFor(() =>
			expect(server.calls.some((c) => c.method === 'DELETE')).toBe(true),
		)
		const call = server.calls.find((c) => c.method === 'DELETE')
		expect(call.url).toMatch(/\/folders\/w$/)
		expect(call.body).toEqual({
			directSecrets: 'move',
			subfolders: { c: 'move' },
		})
	})
})
