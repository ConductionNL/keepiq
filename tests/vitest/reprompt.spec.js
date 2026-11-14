/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Tests for the per-item master password re-prompt (crypto-item-reprompt):
 * the prompt store accepts the right password, keeps the prompt open on a
 * wrong one and refuses the action on cancel; the guard lets an item that is
 * not flagged through at once and asks again on every call for a flagged one;
 * and every reveal path of the detail sidebar and the list row goes through
 * the guard (design D2), so a new path cannot skip it unnoticed.
 *
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.1
 * @spec openspec/changes/crypto-item-reprompt/tasks.md#task-2.2
 */

import { readFileSync } from 'node:fs'
import { createPinia, setActivePinia } from 'pinia'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { encryptPrivateKey } from '../../src/crypto/aes.js'

const session = vi.hoisted(() => ({ encryptedPrivateKey: null }))

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('../../src/store/modules/session.js', () => ({
	useSessionStore: () => session,
}))

const { RepromptCancelled, useRepromptPromptStore } = await import('../../src/store/modules/repromptPrompt.js')
const { needsReprompt, useReprompt, withReprompt } = await import('../../src/composables/useReprompt.js')

beforeAll(async () => {
	session.encryptedPrivateKey = await encryptPrivateKey('-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----', 'right-password')
})

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('the re-prompt store', () => {
	it('lets the action go ahead once the right password is typed', async () => {
		const prompt = useRepromptPromptStore()
		const asked = prompt.ask('Bank')

		expect(prompt.open).toBe(true)
		expect(prompt.itemName).toBe('Bank')
		expect(await prompt.submit('right-password')).toBe(true)
		await expect(asked).resolves.toBeUndefined()
		expect(prompt.open).toBe(false)
	})

	it('keeps the prompt open and the action waiting on a wrong password', async () => {
		const prompt = useRepromptPromptStore()
		let settled = false
		prompt.ask('Bank').then(() => {
			settled = true
		}, () => {
			settled = true
		})

		expect(await prompt.submit('wrong-password')).toBe(false)
		expect(prompt.open).toBe(true)
		expect(prompt.error).toBe('That master password is not right.')
		expect(await prompt.submit('')).toBe(false)
		await Promise.resolve()
		expect(settled).toBe(false)

		expect(await prompt.submit('right-password')).toBe(true)
		expect(prompt.error).toBe('')
	})

	it('refuses the action when the prompt is closed', async () => {
		const prompt = useRepromptPromptStore()
		const asked = prompt.ask('Bank')

		prompt.cancel()

		await expect(asked).rejects.toBeInstanceOf(RepromptCancelled)
		expect(prompt.open).toBe(false)
		expect(await prompt.submit('right-password')).toBe(false)
	})

	it('cancels an earlier ask when a second one starts', async () => {
		const prompt = useRepromptPromptStore()
		const first = prompt.ask('Bank')
		const second = prompt.ask('Root key')

		await expect(first).rejects.toBeInstanceOf(RepromptCancelled)
		expect(prompt.itemName).toBe('Root key')
		await prompt.submit('right-password')
		await expect(second).resolves.toBeUndefined()
	})
})

describe('the guard', () => {
	/**
	 * A prompt double that records asks and answers them as told.
	 *
	 * @param {Array<string>} answers 'ok' or 'cancel', one per ask.
	 * @return {{factory: Function, asked: Array<string>}}
	 */
	function fakePrompt(answers) {
		const asked = []
		return {
			asked,
			factory: () => ({
				ask: (name) => {
					asked.push(name)
					return answers.shift() === 'ok' ? Promise.resolve() : Promise.reject(new RepromptCancelled())
				},
			}),
		}
	}

	it('only flags an item that carries reprompt: true', () => {
		expect(needsReprompt({ reprompt: true })).toBe(true)
		expect(needsReprompt({ reprompt: 'true' })).toBe(false)
		expect(needsReprompt({})).toBe(false)
		expect(needsReprompt(null)).toBe(false)
	})

	it('lets an item that is not flagged through without asking', async () => {
		const prompt = fakePrompt([])

		await useReprompt({ name: 'Mail', reprompt: false }, prompt.factory)()

		expect(prompt.asked).toEqual([])
	})

	it('asks again on every action for a flagged item, with no grace period', async () => {
		const prompt = fakePrompt(['ok', 'ok'])
		const guard = useReprompt(() => ({ name: 'Bank', reprompt: true }), prompt.factory)

		await guard()
		await guard()

		expect(prompt.asked).toEqual(['Bank', 'Bank'])
	})

	it('runs the action only when allowed, and nothing when cancelled', async () => {
		const prompt = fakePrompt(['cancel', 'ok'])
		const action = vi.fn(() => 'copied')

		expect(await withReprompt({ name: 'Bank', reprompt: true }, action, prompt.factory)).toBeNull()
		expect(action).not.toHaveBeenCalled()
		expect(await withReprompt({ name: 'Bank', reprompt: true }, action, prompt.factory)).toBe('copied')
		expect(action).toHaveBeenCalledOnce()
	})

	it('passes on a failure that is not a cancel', async () => {
		const broken = () => ({ ask: () => Promise.reject(new Error('store gone')) })

		await expect(withReprompt({ reprompt: true }, () => 'x', broken)).rejects.toThrow('store gone')
	})
})

describe('every reveal path goes through the guard', () => {
	/**
	 * A component's template.
	 *
	 * @param {string} name The component under src/components.
	 * @return {string}
	 */
	function template(name) {
		const source = readFileSync(new URL(`../../src/components/${name}.vue`, import.meta.url), 'utf8')
		return source.slice(source.indexOf('<template>'), source.lastIndexOf('</template>'))
	}

	/**
	 * The opening tags of a component in a template.
	 *
	 * @param {string} tpl The template.
	 * @param {string} tag The component name.
	 * @return {Array<string>}
	 */
	function tags(tpl, tag) {
		return [...tpl.matchAll(new RegExp(`<${tag}\\b[^>]*>`, 'g'))].map((match) => match[0])
	}

	it('guards every copy button and the value field of the detail sidebar', () => {
		const tpl = template('SecretDetailSidebar')
		const copies = tags(tpl, 'CopyButton')
		const fields = tags(tpl, 'PasswordField')

		expect(copies.length).toBeGreaterThan(0)
		expect(fields.length).toBeGreaterThan(0)
		for (const tag of [...copies, ...fields]) {
			expect(tag, tag).toMatch(/:guard="revealGuard"/)
		}
	})

	it('guards the card and BSN reveals and the edit button of the detail sidebar', () => {
		const tpl = template('SecretDetailSidebar')
		const reveals = [...tpl.matchAll(/@click="([^"]+)"/g)].map((match) => match[1]).filter((handler) => /reveal|Revealed|toggleCard|toggleBsn/i.test(handler))

		expect(reveals.length).toBeGreaterThan(0)
		for (const handler of reveals) {
			expect(handler, handler).toMatch(/^toggleCardField\(|^toggleBsn$/)
		}
		const script = readFileSync(new URL('../../src/components/SecretDetailSidebar.vue', import.meta.url), 'utf8')
		for (const method of ['async toggleCardField(field) {', 'async toggleBsn() {', '\t\topenEdit() {']) {
			const body = script.slice(script.indexOf(method), script.indexOf('\n\t\t},', script.indexOf(method)))
			expect(body, method).toContain('allowedToReveal()')
		}
	})

	it('guards the copy button of a list row and marks a flagged row', () => {
		const tpl = template('SecretListItem')
		const copies = tags(tpl, 'CopyButton')

		expect(copies.length).toBeGreaterThan(0)
		for (const tag of copies) {
			expect(tag, tag).toMatch(/:guard="revealGuard"/)
		}
		expect(tpl).toContain('data-testid="secret-reprompt-marker"')
	})

	it('awaits the guard before a copy button or a value field reveals anything', () => {
		const copy = readFileSync(new URL('../../src/components/CopyButton.vue', import.meta.url), 'utf8')
		const field = readFileSync(new URL('../../src/components/PasswordField.vue', import.meta.url), 'utf8')
		const onCopy = copy.slice(copy.indexOf('async onCopy() {'))

		expect(onCopy.indexOf('await this.guard()')).toBeGreaterThan(-1)
		expect(onCopy.indexOf('await this.guard()')).toBeLessThan(onCopy.indexOf('await this.resolve()'))
		const toggle = field.slice(field.indexOf('async toggle() {'))
		expect(toggle.indexOf('await this.guard()')).toBeLessThan(toggle.indexOf('await this.resolve()'))
		expect(copy).toMatch(/if \(this\.useOnly \|\| this\.guard\) \{\s*return/)
	})
})
