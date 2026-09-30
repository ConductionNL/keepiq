/**
 * @spec openspec/specs/clients-save-prompt/spec.md
 *
 * After a submit the worker offers save, update or nothing, and the in-page
 * offer acts only on the user's own click.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { showSavePrompt } from '../../browser-extension/src/content/save-prompt.js'
import { classifyCapture } from '../../browser-extension/src/lib/capture.js'

const rows = [
	{
		id: 's1',
		name: 'Example',
		url: 'https://login.example.org',
		login: 'c1',
		key: 'k1',
	},
	{ id: 's2', name: 'Other', url: 'https://other.test', login: 'c2', key: 'k2' },
]
const plain = {
	s1: { login: 'ann', secret: 'old-password' },
	s2: { login: 'ann', secret: 'x' },
}
const decrypt = async (row) => plain[row.id]

describe('classifyCapture', () => {
	it('offers an update with the id when the saved password changed', async () => {
		const offer = await classifyCapture(
			{ host: 'login.example.org', login: 'ann', secret: 'new-password' },
			rows,
			decrypt,
		)
		expect(offer).toEqual({ action: 'update', id: 's1', name: 'Example' })
	})

	it('offers nothing when the password is unchanged', async () => {
		const offer = await classifyCapture(
			{ host: 'login.example.org', login: 'ann', secret: 'old-password' },
			rows,
			decrypt,
		)
		expect(offer.action).toBe('none')
	})

	it('offers a save for another username or no saved login', async () => {
		expect(
			(
				await classifyCapture(
					{ host: 'login.example.org', login: 'bob', secret: 'p' },
					rows,
					decrypt,
				)
			).action,
		).toBe('save')
		expect(
			(
				await classifyCapture(
					{ host: 'new.example', login: 'ann', secret: 'p' },
					rows,
					decrypt,
				)
			).action,
		).toBe('save')
	})
})

describe('showSavePrompt', () => {
	afterEach(() => {
		document.body.innerHTML = ''
		vi.useRealTimers()
	})

	it('resolves with the action on a user click and removes itself', async () => {
		const shown = showSavePrompt(
			{ action: 'update', name: 'Example' },
			'login.example.org',
			document,
			{
				mode: 'open',
				isUserEvent: () => true,
			},
		)
		const root = document.getElementById('keepiq-save-prompt').shadowRoot
		expect(root.textContent).toContain('Update the password of Example')
		root.querySelector('button.primary').click()
		await expect(shown).resolves.toBe('update')
		expect(document.getElementById('keepiq-save-prompt')).toBeNull()
	})

	it('ignores a click the page dispatched', async () => {
		vi.useFakeTimers()
		const shown = showSavePrompt(
			{ action: 'save' },
			'login.example.org',
			document,
			{
				mode: 'open',
			},
		)
		const root = document.getElementById('keepiq-save-prompt').shadowRoot
		root.querySelector('button.primary').click() // jsdom: isTrusted is false
		expect(document.getElementById('keepiq-save-prompt')).not.toBeNull()
		vi.advanceTimersByTime(30000)
		await expect(shown).resolves.toBe('dismiss')
	})
})
