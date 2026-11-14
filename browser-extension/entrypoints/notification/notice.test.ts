// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { startNotice } from './notice'

const BODY = /<body>([\s\S]*)<\/body>/.exec(readFileSync(resolve('entrypoints/notification/index.html'), 'utf8'))![1]!.replace(/<script[\s\S]*?<\/script>/, '')

let sent: unknown[]
let stop: (() => void) | undefined
const status = () => document.querySelector('[role="status"]')!.textContent
const text = () => document.querySelector('.bar__text')?.textContent

beforeEach(() => {
	vi.useFakeTimers()
	document.body.innerHTML = BODY
	sent = []
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation((async (message: unknown) => {
		sent.push(message)
		return undefined
	}) as never)
})

afterEach(() => {
	stop?.()
	stop = undefined
	vi.useRealTimers()
	setLocale('en')
})

describe('the notice page', () => {
	it.each([
		['no_login_form', 'Keepiq found no login form to fill on this page.'],
		['no_card_form', 'Keepiq found no card fields to fill on this page.'],
		['no_identity_form', 'Keepiq found no address or personal details fields to fill on this page.'],
	])('words %s, and announces it as a status once shown', async (code, words) => {
		stop = startNotice(code)
		expect(text()).toBe(words)
		expect(document.querySelector('.bar__text')!.getAttribute('aria-hidden')).toBe('true')
		expect(status()).toBe('')
		await vi.advanceTimersByTimeAsync(250)
		expect(status()).toBe(words)
		expect(document.getElementById('bar')!.getAttribute('aria-label')).toBe('Keepiq notice')
	})

	it('words it in Dutch', () => {
		setLocale('nl')
		stop = startNotice('no_identity_form')
		expect(text()).toBe('Keepiq vond geen adres- of persoonsgegevensvelden om in te vullen op deze pagina.')
		expect(document.documentElement.lang).toBe('nl')
	})

	it('takes no focus, and asks the background to close it from Close or Escape', () => {
		const active = document.activeElement
		stop = startNotice('no_login_form')
		expect(document.activeElement).toBe(active)
		document.querySelector<HTMLButtonElement>('.bar__close')!.click()
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		expect(sent).toEqual([{ kind: 'notice.close' }, { kind: 'notice.close' }])
	})

	it('shows nothing for an unknown code', () => {
		stop = startNotice('<img src=x>')
		expect(document.getElementById('bar')!.childElementCount).toBe(0)
	})
})
