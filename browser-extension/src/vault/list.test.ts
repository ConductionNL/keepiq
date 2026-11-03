import { describe, expect, it } from 'vitest'
import { filterItems, flattenFolders, folderPath, launchUrl, NO_FILTER, sortItems } from './list'

const item = (name: string, overrides: { url?: string | null; folderId?: string | null; typeId?: string | null; id?: string } = {}) =>
	({ id: overrides.id ?? name, name, url: overrides.url ?? null, folderId: overrides.folderId ?? null, typeId: overrides.typeId ?? 't-login' })

describe('sortItems', () => {
	it('sorts case-insensitively with the id as tiebreak', () => {
		expect(sortItems([item('bank'), item('Amazon'), item('apple')]).map((i) => i.name)).toEqual(['Amazon', 'apple', 'bank'])
		expect(sortItems([item('Mail', { id: 'b' }), item('mail', { id: 'a' })]).map((i) => i.id)).toEqual(['a', 'b'])
	})
})

describe('filterItems', () => {
	const items = [
		item('Work mail', { url: 'https://mail.example.com', folderId: 'w' }),
		item('Bank', { url: 'bank.test', typeId: 't-card' }),
		item('Diary', { folderId: 'c' }),
	]

	it('passes everything without a filter', () => {
		expect(filterItems(items, NO_FILTER)).toHaveLength(3)
	})

	it('searches name and url, case-insensitive', () => {
		expect(filterItems(items, { ...NO_FILTER, search: 'EXAMPLE' }).map((i) => i.name)).toEqual(['Work mail'])
		expect(filterItems(items, { ...NO_FILTER, search: ' diary ' }).map((i) => i.name)).toEqual(['Diary'])
	})

	it('composes folder, type and search', () => {
		expect(filterItems(items, { ...NO_FILTER, folder: 'w' }).map((i) => i.name)).toEqual(['Work mail'])
		expect(filterItems(items, { ...NO_FILTER, folder: 'none' }).map((i) => i.name)).toEqual(['Bank'])
		expect(filterItems(items, { ...NO_FILTER, typeIds: new Set(['t-card']) }).map((i) => i.name)).toEqual(['Bank'])
		expect(filterItems(items, { search: 'mail', folder: 'w', typeIds: new Set(['t-card']) })).toEqual([])
	})
})

describe('flattenFolders', () => {
	it('walks depth-first with children by name', () => {
		const folders = [
			{ id: 'w', name: 'Work', parentId: null },
			{ id: 'z', name: 'Zeta', parentId: 'w' },
			{ id: 'c', name: 'Clients', parentId: 'w' },
			{ id: 'a', name: 'Archive', parentId: null },
			{ id: 'o', name: 'Orphan', parentId: 'gone' },
		]
		expect(flattenFolders(folders).map((f) => `${f.depth}:${f.name}`)).toEqual(['0:Archive', '0:Orphan', '0:Work', '1:Clients', '1:Zeta'])
	})

	it('survives a cycle', () => {
		const folders = [{ id: 'a', name: 'A', parentId: 'b' }, { id: 'b', name: 'B', parentId: 'a' }]
		expect(flattenFolders(folders)).toEqual([])
	})
})

describe('folderPath', () => {
	const folders = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }]

	it('joins the chain with slashes', () => {
		expect(folderPath(folders, 'c')).toBe('Work / Clients')
		expect(folderPath(folders, null)).toBeNull()
		expect(folderPath(folders, 'missing')).toBeNull()
	})
})

describe('launchUrl', () => {
	it.each([
		['github.com', 'https://github.com/'],
		['https://example.com/x', 'https://example.com/x'],
		['http://intranet.local', 'http://intranet.local/'],
		['localhost:8080', 'https://localhost:8080/'],
		[' example.org ', 'https://example.org/'],
	])('%j → %s', (url, launched) => {
		expect(launchUrl(url)).toBe(launched)
	})

	it.each([null, '', 'javascript:alert(1)', 'file:///etc/passwd', 'data:text/html,x', 'https://'])('does not launch %j', (url) => {
		expect(launchUrl(url)).toBeNull()
	})
})
