// @vitest-environment happy-dom
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { INLINE_MIN_HEIGHT_PX, INLINE_ROW_PX, inlineHeight, type InlineListToBackground, type InlinePickReply, type InlineStateReply } from '@/src/messages'
import { setLocale } from '@/src/testing/i18n'
import { startInlineList } from './list'

const KEY = 'session-key'
const SECOND_KEY = 'second-key'
const REFUSED = 'Keepiq can\'t fill here because this page changes how it is shown. Use the Keepiq button in the toolbar.'
const GUARD_MS = 500
// The page's own markup, without the script and stylesheet happy-dom would try to load.
const BODY = /<body>([\s\S]*)<\/body>/.exec(readFileSync(resolve('entrypoints/inline-list/index.html'), 'utf8'))![1]!.replace(/<script[\s\S]*?<\/script>/, '')
const STYLES = readFileSync(resolve('entrypoints/inline-list/inline-list.css'), 'utf8')

const ROWS = [
	{ id: 'p', name: 'Personal', username: 'alice' },
	{ id: 'w', name: 'Work', username: 'alice@work.test' },
	{ id: 'b', name: 'Bank', username: null },
]
const unlocked = (rows = ROWS, host = 'example.test'): InlineStateReply => ({ state: 'unlocked', host, rows, theme: 'system' })
const noMatch = (): InlineStateReply => unlocked([], 'nothing.test')
const locked = (): InlineStateReply => ({ state: 'locked', host: 'example.test', rows: [], theme: 'system' })

let sent: InlineListToBackground[]
let stateReply: InlineStateReply
let pickReply: InlinePickReply
let stop: () => void

beforeEach(() => {
	vi.useFakeTimers({ now: 1_000_000 })
	document.body.innerHTML = BODY
	sent = []
	stateReply = unlocked()
	pickReply = { filled: 'both' }
	vi.spyOn(browser.runtime, 'sendMessage').mockImplementation((async (message: InlineListToBackground) => {
		sent.push(message)
		// SECOND_KEY is valid too, as if the background had opened a second session; the list must still keep its first.
		if (message.kind === 'inline.state') return message.key === KEY || message.key === SECOND_KEY ? stateReply : null
		if (message.kind === 'inline.pick') return message.key === KEY || message.key === SECOND_KEY ? pickReply : null
		return undefined
	}) as never)
})

afterEach(() => {
	stop?.()
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

const flush = () => vi.advanceTimersByTimeAsync(0)
const wait = (ms: number) => vi.advanceTimersByTimeAsync(ms)

function post(data: unknown, source: unknown = window.parent) {
	window.dispatchEvent(new MessageEvent('message', { data, source: source as Window }))
}

async function open(reply: InlineStateReply = unlocked(), { settle = true } = {}) {
	stateReply = reply
	stop = startInlineList()
	post({ kind: 'inline.init', key: KEY })
	await flush()
	if (settle) await wait(GUARD_MS)
}

const options = () => [...document.querySelectorAll<HTMLElement>('[role="option"]')]
const row = (index: number) => options()[index]!
const listbox = () => document.querySelector<HTMLElement>('[role="listbox"]')
const status = () => document.querySelector('[role="status"]')!
const unlockButton = () => [...document.querySelectorAll('button')].find((b) => b.textContent === 'Unlock')!
const button = (text: string) => [...document.querySelectorAll('button')].find((b) => b.textContent === text)!
const noMatchMessage = () => [...document.querySelectorAll<HTMLElement>('[tabindex="-1"]')].find((el) => el.textContent?.startsWith('No logins'))!
const picks = () => sent.filter((m) => m.kind === 'inline.pick')
const kinds = () => sent.map((m) => m.kind)

function key(target: Element, keyName: string, init: KeyboardEventInit = {}) {
	const event = new KeyboardEvent('keydown', { key: keyName, bubbles: true, cancelable: true, ...init })
	target.dispatchEvent(event)
	return event
}

function press(target: Element, x = 10, y = 20, button = 0) {
	target.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, button, clientX: x, clientY: y }))
}

function click(target: Element, x = 10, y = 20, button = 0) {
	press(target, x, y, button)
	target.dispatchEvent(new MouseEvent('click', { bubbles: true, button, clientX: x, clientY: y }))
}

const closes = () => sent.filter((m) => m.kind === 'inline.close')
const message = (text: string) => [...document.querySelectorAll<HTMLElement>('.message, .message *')].find((el) => el.textContent === text)!

describe('key hand-off', () => {
	it('presents the key from its parent and renders the reply', async () => {
		await open()
		expect(sent[0]).toEqual({ kind: 'inline.state', key: KEY })
		expect(options()).toHaveLength(3)
	})

	it('ignores a key posted by any window but its parent', async () => {
		stop = startInlineList()
		post({ kind: 'inline.init', key: KEY }, { postMessage() {} })
		post({ kind: 'inline.init', key: KEY }, null)
		await flush()
		expect(sent).toEqual([])
		expect(options()).toHaveLength(0)
	})

	it('tries a false key the page posted first, then takes the real one', async () => {
		stop = startInlineList()
		post({ kind: 'inline.init', key: 'planted' })
		await flush()
		expect(options()).toHaveLength(0)
		post({ kind: 'inline.init', key: KEY })
		await flush()
		expect(kinds()).toEqual(['inline.state', 'inline.state', 'inline.resize'])
		expect(options()).toHaveLength(3)
	})

	it('takes no other key once one is accepted', async () => {
		await open()
		post({ kind: 'inline.init', key: 'another' })
		post({ kind: 'inline.init', key: KEY })
		await flush()
		expect(kinds()).toEqual(['inline.state', 'inline.resize'])
	})

	it('keeps the first accepted key when a later one is accepted too', async () => {
		stop = startInlineList()
		post({ kind: 'inline.init', key: KEY })
		post({ kind: 'inline.init', key: SECOND_KEY })
		await flush()
		post({ kind: 'inline.focus', key: SECOND_KEY })
		expect(document.activeElement).toBe(document.body)
		post({ kind: 'inline.focus', key: KEY })
		expect(document.activeElement).toBe(row(0))
		await wait(GUARD_MS)
		key(row(0), 'Enter')
		await flush()
		expect(picks().map((m) => m.key)).toEqual([KEY])
	})

	it('ignores malformed messages and commands without the key', async () => {
		await open()
		post('inline.focus')
		post(null)
		post({ kind: 'inline.focus', key: 'planted' })
		post({ kind: 'inline.focus' })
		await flush()
		expect(document.activeElement).toBe(document.body)
	})

	it('shows nothing when the background refuses or answers nonsense', async () => {
		await open(null)
		expect(document.querySelector('#list')!.children).toHaveLength(0)
		stop()
		await open({ state: 'open', rows: 'all' } as never)
		expect(document.querySelector('#list')!.children).toHaveLength(0)
	})

	it('registers no runtime message listener', async () => {
		const addListener = vi.spyOn(browser.runtime.onMessage, 'addListener')
		await open()
		expect(addListener).not.toHaveBeenCalled()
	})

	it('follows the Theme setting', async () => {
		await open({ ...unlocked()!, theme: 'dark' })
		expect(document.documentElement.dataset.theme).toBe('dark')
	})
})

describe('contents', () => {
	it('lists rows in the given order as options of a named listbox', async () => {
		await open()
		expect(listbox()!.getAttribute('aria-label')).toBe('Keepiq logins for example.test')
		expect(options().map((o) => o.getAttribute('aria-label'))).toEqual(['Personal, alice', 'Work, alice@work.test', 'Bank'])
		expect(options().map((o) => o.textContent)).toEqual(['Personalalice', 'Workalice@work.test', 'Bank'])
	})

	it('shows the no-match message without a listbox or anything to pick', async () => {
		await open(noMatch())
		expect(listbox()).toBeNull()
		expect(noMatchMessage().textContent).toBe('No logins for nothing.test')
		expect(document.querySelectorAll('button')).toHaveLength(0)
	})

	it('shows the locked message with Unlock and no listbox', async () => {
		await open(locked())
		expect(listbox()).toBeNull()
		expect(document.querySelector('#list')!.textContent).toContain('Keepiq is locked')
		expect(unlockButton()).toBeTruthy()
		expect(options()).toHaveLength(0)
	})

	it('describes Unlock by the locked message', async () => {
		await open(locked())
		const described = document.getElementById(unlockButton().getAttribute('aria-describedby') ?? '')
		expect(described?.textContent).toBe('Keepiq is locked')
	})

	it('asks the host, through the background, for the height of what it shows, and never tells its parent anything', async () => {
		const postToParent = vi.spyOn(window.parent, 'postMessage')
		const twelve = unlocked(Array.from({ length: 12 }, (_, i) => ({ id: `${i}`, name: `n${i}`, username: null })))
		const asked: unknown[] = []
		for (const reply of [unlocked(ROWS.slice(0, 1)), unlocked(), twelve, noMatch(), locked()]) {
			await open(reply)
			asked.push(sent.filter((m) => m.kind === 'inline.resize'))
			sent = []
			stop()
		}
		expect(asked).toEqual([60, 160, 260, 60, 60].map((height) => [{ kind: 'inline.resize', key: KEY, height }]))
		expect(postToParent).not.toHaveBeenCalled()
		expect(STYLES).toMatch(/html,\s*body\s*\{[^}]*height: 100%/)
	})

	it.each([['locked', locked], ['no-match', noMatch]])('asks for one row when %s, as for one match, however tall its text measures', async (_state, reply) => {
		const measured = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
			return { height: this.classList.contains('message') ? 70.4 : 0 } as DOMRect
		})
		await open(reply())
		expect(sent.filter((m) => m.kind === 'inline.resize')).toEqual([{ kind: 'inline.resize', key: KEY, height: INLINE_MIN_HEIGHT_PX }])
		measured.mockImplementation(() => ({ height: 200 }) as DOMRect)
		window.dispatchEvent(new Event('resize'))
		expect(sent.filter((m) => m.kind === 'inline.resize')).toHaveLength(1)
	})

	it('sizes rows by the shared sizes', async () => {
		await open()
		const style = document.documentElement.style
		expect(['--row', '--gap', '--inset', '--radius'].map((name) => style.getPropertyValue(name))).toEqual([`${INLINE_ROW_PX}px`, '2px', '6px', '10px'])
		expect(STYLES).toMatch(/\.row\s*\{[^}]*height: var\(--row\)/)
		expect(STYLES).toMatch(/\.listbox\s*\{[^}]*gap: var\(--gap\)/)
	})

	it('sets its language and title from the catalog', async () => {
		setLocale('nl')
		await open()
		expect(document.documentElement.lang).toBe('nl')
		expect(document.title).toBe('Keepiq-logins')
	})
})

describe('keyboard', () => {
	it.each([
		['unlocked', unlocked, () => row(0)],
		['locked', locked, unlockButton],
		['no-match', noMatch, noMatchMessage],
	])('Down from the field focuses the entry point when %s', async (_state, reply, entry) => {
		await open(reply())
		post({ kind: 'inline.focus', key: KEY })
		expect(document.activeElement).toBe(entry())
	})

	it('applies a focus that arrived before the rows', async () => {
		stop = startInlineList()
		post({ kind: 'inline.init', key: KEY })
		post({ kind: 'inline.focus', key: KEY })
		await flush()
		expect(document.activeElement).toBe(row(0))
	})

	it('moves between rows with Up, Down, Home and End, selection following focus', async () => {
		await open()
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'ArrowDown')
		expect(document.activeElement).toBe(row(1))
		expect(options().map((o) => o.getAttribute('aria-selected'))).toEqual(['false', 'true', 'false'])
		key(row(1), 'End')
		expect(document.activeElement).toBe(row(2))
		key(row(2), 'ArrowDown')
		expect(document.activeElement).toBe(row(2))
		key(row(2), 'Home')
		expect(document.activeElement).toBe(row(0))
		key(row(0), 'ArrowDown')
		key(row(1), 'ArrowUp')
		expect(document.activeElement).toBe(row(0))
		expect(kinds()).not.toContain('inline.refocus')
	})

	it.each([
		['the first row', unlocked, () => row(0)],
		['Unlock', locked, unlockButton],
		['the no-match message', noMatch, noMatchMessage],
	])('Up on %s returns focus to the field', async (_name, reply, entry) => {
		await open(reply())
		post({ kind: 'inline.focus', key: KEY })
		key(entry(), 'ArrowUp')
		expect(sent.at(-1)).toEqual({ kind: 'inline.refocus', key: KEY })
	})

	it.each([['Escape', {}], ['Tab', {}], ['Tab', { shiftKey: true }]])('%s closes the list and returns focus to the field', async (keyName, init) => {
		for (const [reply, entry] of [[unlocked, () => row(1)], [noMatch, noMatchMessage], [locked, unlockButton]] as const) {
			sent = []
			await open(reply())
			const event = key(entry(), keyName, init)
			expect(event.defaultPrevented).toBe(true)
			expect(sent.at(-1)).toEqual({ kind: 'inline.close', key: KEY, refocus: true })
			stop()
		}
	})

	it('blurs its focused row when its window loses focus, so a page cannot aim a key at it', async () => {
		await open()
		post({ kind: 'inline.focus', key: KEY })
		window.dispatchEvent(new Event('blur'))
		expect(document.activeElement).toBe(document.body)
		// The page focuses the list's window and the user presses Enter.
		key(document.body, 'Enter')
		key(document.body, 'ArrowDown')
		key(document.body, 'Enter')
		await flush()
		expect(picks()).toEqual([])
	})

	it('drops the selection when its window loses focus', async () => {
		await open()
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'ArrowDown')
		window.dispatchEvent(new Event('blur'))
		expect(options().map((o) => o.getAttribute('aria-selected'))).toEqual(['false', 'false', 'false'])
	})

	it('closes on Escape when focus is on its body', async () => {
		await open()
		const event = key(document.body, 'Escape')
		expect(event.defaultPrevented).toBe(true)
		expect(sent.at(-1)).toEqual({ kind: 'inline.close', key: KEY, refocus: true })
		expect(picks()).toEqual([])
	})

	it('ignores Escape on its body before it has a key', async () => {
		stop = startInlineList()
		key(document.body, 'Escape')
		await flush()
		expect(sent).toEqual([])
	})

	it('announces the state 250 ms after it arrives', async () => {
		await open(unlocked(), { settle: false })
		expect(status().textContent).toBe('')
		await wait(249)
		expect(status().textContent).toBe('')
		await wait(1)
		expect(status().textContent).toBe('3 Keepiq logins. Press Down Arrow to choose.')
	})

	it.each([
		[unlocked(ROWS.slice(0, 1)), '1 Keepiq login. Press Down Arrow to choose.'],
		[noMatch(), 'No Keepiq logins for nothing.test'],
		[locked(), 'Keepiq is locked. Press Down Arrow to unlock.'],
	])('announces %j as %s', async (reply, text) => {
		await open(reply, { settle: false })
		await wait(250)
		expect(status().textContent).toBe(text)
		expect(document.activeElement).toBe(document.body)
	})
})

describe('pick guard', () => {
	it('picks a clicked row with the press time and point', async () => {
		await open()
		click(row(1), 30, 70)
		await flush()
		expect(picks()).toEqual([{ kind: 'inline.pick', key: KEY, itemId: 'w', input: { kind: 'pointer', at: 1_000_500, x: 30, y: 70 } }])
	})

	it('ignores a click within 500 ms of the list appearing', async () => {
		await open(unlocked(), { settle: false })
		await wait(GUARD_MS - 1)
		click(row(0))
		await flush()
		expect(picks()).toEqual([])
	})

	it('measures from the press, not the release', async () => {
		await open(unlocked(), { settle: false })
		await wait(GUARD_MS - 100)
		press(row(0))
		await wait(300)
		row(0).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flush()
		expect(picks()).toEqual([])
	})

	it.each([['middle', 1], ['right', 2]])('picks nothing with the %s button', async (_name, button) => {
		await open()
		click(row(0), 10, 20, button)
		await flush()
		expect(picks()).toEqual([])
	})

	it('ignores a click without its own press', async () => {
		await open()
		row(0).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		press(row(1))
		row(0).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flush()
		expect(picks()).toEqual([])
	})

	it('ignores the second click of a double-click on the field', async () => {
		await open(unlocked(), { settle: false })
		await wait(150)
		click(row(0))
		row(0).dispatchEvent(new MouseEvent('dblclick', { bubbles: true }))
		await flush()
		expect(picks()).toEqual([])
	})

	it('ignores a click within 500 ms of the list moving', async () => {
		await open()
		post({ kind: 'inline.moved', key: KEY })
		await wait(GUARD_MS - 1)
		click(row(0))
		await flush()
		expect(picks()).toEqual([])
		post({ kind: 'inline.moved', key: 'planted' })
		await wait(1)
		click(row(0))
		await flush()
		expect(picks()).toHaveLength(1)
	})

	it.each(['Enter', ' '])('picks with %j on a row, sending the key time and the row centre', async (keyName) => {
		await open()
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'ArrowDown')
		vi.spyOn(row(1), 'getBoundingClientRect').mockReturnValue(new DOMRect(0, 44, 240, 44))
		const event = key(row(1), keyName)
		await flush()
		expect(event.defaultPrevented).toBe(true)
		expect(picks()).toEqual([{ kind: 'inline.pick', key: KEY, itemId: 'w', input: { kind: 'key', at: 1_000_500, x: 120, y: 66 } }])
	})

	it.each(['Enter', ' '])('ignores %j within 500 ms of the list appearing', async (keyName) => {
		await open(unlocked(), { settle: false })
		post({ kind: 'inline.focus', key: KEY })
		await wait(GUARD_MS - 1)
		key(row(0), keyName)
		await flush()
		expect(picks()).toEqual([])
	})

	it('ignores a held key', async () => {
		await open()
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'Enter', { repeat: true })
		await flush()
		expect(picks()).toEqual([])
	})

	it('sends one pick while one is pending', async () => {
		await open()
		click(row(0))
		click(row(1))
		await flush()
		expect(picks()).toHaveLength(1)
	})

	it('sends nothing after a pick: the background closes the list', async () => {
		await open()
		click(row(0))
		await flush()
		expect(kinds()).toEqual(['inline.state', 'inline.resize', 'inline.pick'])
	})
})

describe('insecure question', () => {
	async function ask() {
		await open()
		pickReply = { needsInsecureConfirm: true }
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'ArrowDown')
		key(row(1), 'Enter')
		await flush()
		pickReply = { filled: 'both' }
	}

	it('asks inside the list with focus on Cancel', async () => {
		await ask()
		expect(document.querySelector('[role="alertdialog"]')).toBeTruthy()
		expect(listbox()).toBeNull()
		expect(document.activeElement).toBe(button('Cancel'))
	})

	it('leads with the question, named by it and described by the warning', async () => {
		await ask()
		const dialog = document.querySelector('[role="alertdialog"]')!
		const byId = (attribute: string) => document.getElementById(dialog.getAttribute(attribute) ?? '')?.textContent
		expect(byId('aria-labelledby')).toBe('Fill on this insecure site?')
		expect(byId('aria-describedby')).toBe('This site is not secure. Anyone on the network could read what is filled in.')
		expect(dialog.firstElementChild!.textContent).toBe('Fill on this insecure site?')
	})

	it('asks it in Dutch', async () => {
		setLocale('nl')
		await ask()
		expect(document.querySelector('[role="alertdialog"]')!.firstElementChild!.textContent).toBe('Toch invullen op deze onbeveiligde site?')
	})

	it('ignores a held key on Fill', async () => {
		await ask()
		await wait(GUARD_MS)
		key(button('Fill'), 'Enter', { repeat: true })
		key(button('Fill'), ' ', { repeat: true })
		await flush()
		expect(picks()).toHaveLength(1)
	})

	it('cancels on Escape when focus is on its body', async () => {
		await ask()
		window.dispatchEvent(new Event('blur'))
		key(document.body, 'Escape')
		expect(listbox()).toBeTruthy()
		expect(document.activeElement).toBe(row(1))
		expect(kinds()).not.toContain('inline.close')
	})

	it('restarts the guard: Fill within 500 ms of the question is ignored', async () => {
		await ask()
		await wait(GUARD_MS - 1)
		click(button('Fill'))
		key(button('Fill'), 'Enter')
		await flush()
		expect(picks()).toHaveLength(1)
		await wait(1)
		click(button('Fill'), 5, 6)
		await flush()
		expect(picks().at(-1)).toEqual({ kind: 'inline.pick', key: KEY, itemId: 'w', confirmedInsecure: true, input: { kind: 'pointer', at: 1_001_000, x: 5, y: 6 } })
	})

	it('fills with Enter on Fill', async () => {
		await ask()
		await wait(GUARD_MS)
		key(button('Fill'), 'Enter')
		await flush()
		expect(picks().at(-1)).toMatchObject({ itemId: 'w', confirmedInsecure: true, input: { kind: 'key' } })
	})

	it('Cancel sends nothing and returns to the row', async () => {
		await ask()
		button('Cancel').click()
		await flush()
		expect(picks()).toHaveLength(1)
		expect(document.activeElement).toBe(row(1))
	})

	it('Escape cancels and returns focus to the row, restarting the guard', async () => {
		await ask()
		await wait(GUARD_MS)
		key(button('Cancel'), 'Escape')
		expect(document.activeElement).toBe(row(1))
		expect(kinds()).not.toContain('inline.close')
		click(row(1))
		await flush()
		expect(picks()).toHaveLength(1)
	})

	it('asks for the question\'s height, and for the rows\' again after Cancel', async () => {
		await ask()
		const asked = () => sent.flatMap((m) => (m.kind === 'inline.resize' ? [m.height] : []))
		expect(asked()).toEqual([inlineHeight(3), INLINE_MIN_HEIGHT_PX])
		button('Cancel').click()
		expect(asked()).toEqual([inlineHeight(3), INLINE_MIN_HEIGHT_PX, inlineHeight(3)])
	})

	it('asks for the height the question measures, and again only when that changes', async () => {
		const measured = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
			return { height: this.classList.contains('question') ? 70.4 : 0 } as DOMRect
		})
		await ask()
		const asked = () => sent.flatMap((m) => (m.kind === 'inline.resize' ? [m.height] : []))
		expect(asked().at(-1)).toBe(83)
		const count = asked().length
		window.dispatchEvent(new Event('resize'))
		expect(asked()).toHaveLength(count)
		measured.mockImplementation(() => ({ height: 120 }) as DOMRect)
		window.dispatchEvent(new Event('resize'))
		expect(asked().at(-1)).toBe(132)
	})

	it('lets the keyboard reach a warning the frame has no room for', async () => {
		await ask()
		const list = document.getElementById('list')!
		vi.spyOn(list, 'scrollHeight', 'get').mockReturnValue(200)
		const clientHeight = vi.spyOn(list, 'clientHeight', 'get').mockReturnValue(120)
		window.dispatchEvent(new Event('resize'))
		const text = document.getElementById('question-text')!
		expect(text.getAttribute('tabindex')).toBe('0')
		key(button('Cancel'), 'Tab', { shiftKey: true })
		expect(document.activeElement).toBe(text)
		clientHeight.mockReturnValue(200)
		window.dispatchEvent(new Event('resize'))
		expect(text.hasAttribute('tabindex')).toBe(false)
	})

	it('keeps Tab between its buttons', async () => {
		await ask()
		key(button('Cancel'), 'Tab')
		expect(document.activeElement).toBe(button('Fill'))
		key(button('Fill'), 'Tab')
		expect(document.activeElement).toBe(button('Cancel'))
		key(button('Cancel'), 'Tab', { shiftKey: true })
		expect(document.activeElement).toBe(button('Fill'))
		expect(kinds()).not.toContain('inline.close')
	})
})

describe('refused as not visible', () => {
	async function refuse() {
		await open()
		pickReply = { refused: 'not_visible' }
		post({ kind: 'inline.focus', key: KEY })
		key(row(0), 'Enter')
		await flush()
	}

	it('says why and takes focus, which reads it out once', async () => {
		await refuse()
		expect(listbox()).toBeNull()
		expect(document.activeElement).toBe(message(REFUSED))
		await wait(250)
		expect(status().textContent).not.toBe(REFUSED)
	})

	it('lets Down scroll a clipped message, while Up still returns to the field', async () => {
		await refuse()
		expect(key(message(REFUSED), 'ArrowDown').defaultPrevented).toBe(false)
		key(message(REFUSED), 'ArrowUp')
		expect(sent.at(-1)).toEqual({ kind: 'inline.refocus', key: KEY })
	})

	it('says it in Dutch', async () => {
		setLocale('nl')
		await refuse()
		expect(document.activeElement!.textContent).toBe('Keepiq kan hier niet invullen, omdat deze pagina aanpast hoe ze wordt weergegeven. Gebruik de Keepiq-knop in de werkbalk.')
	})

	it.each(['Escape', 'Tab'])('closes on %s and returns focus to the field', async (keyName) => {
		await refuse()
		key(message(REFUSED), keyName)
		expect(sent.at(-1)).toEqual({ kind: 'inline.close', key: KEY, refocus: true })
	})

	it('leaves the list as it is for the refusals the background closes', async () => {
		await open()
		pickReply = { refused: 'stale' }
		click(row(0))
		await flush()
		expect(options()).toHaveLength(3)
	})
})

describe('a click on a message', () => {
	// Closing on some states and not others would tell the page, after a user's click, whether logins match.
	it('leaves the list open on the no-match, locked and refusal messages', async () => {
		for (const [reply, text] of [[noMatch, 'No logins for nothing.test'], [locked, 'Keepiq is locked']] as const) {
			await open(reply())
			click(message(text))
			await flush()
			stop()
		}
		await open()
		pickReply = { refused: 'not_visible' }
		click(row(0))
		await flush()
		click(message(REFUSED))
		await flush()
		expect(closes()).toEqual([])
		expect(message(REFUSED)).toBeTruthy()
	})

	it('leaves Unlock to unlock', async () => {
		await open(locked())
		vi.spyOn((browser.action ?? browser.browserAction), 'openPopup').mockRejectedValue(new Error('no window'))
		click(unlockButton())
		await flush()
		expect(closes()).toEqual([])
		expect(sent.at(-1)).toEqual({ kind: 'inline.unlock', key: KEY })
	})
})

describe('stylesheet', () => {
	const POPUP_STYLES = readFileSync(resolve('entrypoints/popup/popup.css'), 'utf8')

	/** Custom properties per selector, so the dark media block and the pinned themes compare separately. */
	function tokens(css: string): Map<string, Map<string, string>> {
		const blocks = new Map<string, Map<string, string>>()
		for (const [, selector, body] of css.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
			const found = new Map([...body!.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map(([, name, value]) => [name!, value!.trim()]))
			if (found.size > 0) blocks.set(selector!.trim(), found)
		}
		return blocks
	}

	it('copies the popup tokens unchanged', () => {
		const popup = tokens(POPUP_STYLES)
		const copied = tokens(STYLES)
		expect([...copied.keys()].sort()).toEqual([':root', ':root:not([data-theme=\'light\'])', ':root[data-theme=\'dark\']'])
		for (const [selector, values] of copied) {
			for (const [name, value] of values) expect({ selector, name, value }).toEqual({ selector, name, value: popup.get(selector)?.get(name) })
		}
	})

	it('wraps long host names, makes a message at least one row, and scrolls what the host has no room for', () => {
		const message = /\.message\s*\{([^}]*)\}/.exec(STYLES)![1]
		expect(message).toMatch(/min-height: var\(--row\)/)
		expect(message).not.toMatch(/(^|[^-])height:/)
		expect(message).toMatch(/overflow-wrap: anywhere/)
		expect(/\.list\s*\{([^}]*)\}/.exec(STYLES)![1]).toMatch(/overflow-y: auto/)
	})

	it('draws its edge on the body in a colour of at least 3:1, as the first pixel of the inset', () => {
		const body = /\}\s*body\s*\{([^}]*)\}/.exec(STYLES.replace(/\/\*[\s\S]*?\*\//g, ''))![1]
		expect(body).toMatch(/border: 1px solid var\(--fg-muted\)/)
		expect(body).toMatch(/border-radius: var\(--radius\)/)
		expect(/\.list\s*\{([^}]*)\}/.exec(STYLES)![1]).toMatch(/padding: calc\(var\(--inset\) - 1px\)/)
	})

	it('paints nothing over the list, which would make Chrome report it never visible', () => {
		const rules = [...STYLES.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/([^{}]+)\{([^{}]*)\}/g)]
		const overlays = rules.filter(([, selector, body]) => /::?(before|after)/.test(selector!) && /position:\s*(fixed|absolute)/.test(body!))
		expect(overlays.map(([, selector]) => selector!.trim())).toEqual([])
	})

	it('highlights a row only while it has focus', () => {
		expect(STYLES).not.toMatch(/aria-selected/)
	})
})

describe('unlock', () => {
	const action = () => (browser.action ?? browser.browserAction)

	it('opens the popup and closes the list', async () => {
		await open(locked())
		const openPopup = vi.fn(async () => {})
		vi.spyOn(action(), 'openPopup').mockImplementation(openPopup)
		unlockButton().click()
		expect(openPopup).toHaveBeenCalled()
		await flush()
		expect(sent.at(-1)).toEqual({ kind: 'inline.close', key: KEY, refocus: false })
	})

	it('asks the background for the pop-out when the popup does not open', async () => {
		await open(locked())
		vi.spyOn(action(), 'openPopup').mockRejectedValue(new Error('no window'))
		unlockButton().click()
		await flush()
		expect(sent.at(-1)).toEqual({ kind: 'inline.unlock', key: KEY })
	})

	it('asks for the pop-out where openPopup throws or is missing', async () => {
		await open(locked())
		vi.spyOn(action(), 'openPopup').mockImplementation(() => {
			throw new Error('not implemented')
		})
		unlockButton().click()
		await flush()
		expect(sent.at(-1)).toEqual({ kind: 'inline.unlock', key: KEY })
	})
})

describe('visibility on Chrome', () => {
	let report: (visible: boolean) => void

	beforeEach(() => {
		vi.stubGlobal('IntersectionObserverEntry', class { get isVisible() { return false } })
		vi.stubGlobal('IntersectionObserver', class {
			constructor(callback: IntersectionObserverCallback, readonly init: IntersectionObserverInit & { trackVisibility?: boolean; delay?: number }) {
				expect(init).toMatchObject({ trackVisibility: true, delay: 100 })
				report = (visible) => callback([{ isIntersecting: true, isVisible: visible } as never], this as never)
			}

			observe() {}
			disconnect() {}
		})
	})

	it('refuses until the browser reports the list visible', async () => {
		await open()
		click(row(0))
		await flush()
		expect(picks()).toEqual([])
	})

	it('picks once the list has been visible for 500 ms', async () => {
		await open(unlocked(), { settle: false })
		report(true)
		await wait(GUARD_MS)
		click(row(0))
		await flush()
		expect(picks()).toHaveLength(1)
	})

	it('refuses while the list is reported not visible', async () => {
		await open(unlocked(), { settle: false })
		report(true)
		await wait(GUARD_MS)
		report(false)
		await wait(GUARD_MS * 4)
		click(row(0))
		await flush()
		expect(picks()).toEqual([])
	})

	it.each([[GUARD_MS - 1, 0], [GUARD_MS, 1]])('after a not-visible report, a click %i ms after it shows again sends %i picks', async (after, count) => {
		await open(unlocked(), { settle: false })
		report(true)
		await wait(GUARD_MS * 2)
		report(false)
		await wait(10)
		report(true)
		await wait(after)
		click(row(0))
		await flush()
		expect(picks()).toHaveLength(count)
	})

	describe('says why it refused', () => {
		it('shows and focuses the explanation in place of the rows, and tells the page nothing', async () => {
			const postToParent = vi.spyOn(window.parent, 'postMessage')
			await open()
			post({ kind: 'inline.focus', key: KEY })
			key(row(0), 'Enter')
			await flush()
			expect(picks()).toEqual([])
			expect(listbox()).toBeNull()
			expect(document.activeElement).toBe(message(REFUSED))
			expect(postToParent).not.toHaveBeenCalled()
			expect(sent.filter((m) => m.kind !== 'inline.state')).toEqual([
				{ kind: 'inline.resize', key: KEY, height: inlineHeight(3) },
				{ kind: 'inline.resize', key: KEY, height: INLINE_MIN_HEIGHT_PX },
			])
		})

		it('asks for the height the explanation measures, as only a pick shows it', async () => {
			vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
				return { height: this.classList.contains('message--notice') ? 110.2 : 0 } as DOMRect
			})
			await open()
			post({ kind: 'inline.focus', key: KEY })
			key(row(0), 'Enter')
			await flush()
			expect(sent.filter((m) => m.kind === 'inline.resize').at(-1)).toEqual({ kind: 'inline.resize', key: KEY, height: 123 })
		})

		it('for a click, and for Fill in the insecure question', async () => {
			await open(unlocked(), { settle: false })
			report(true)
			await wait(GUARD_MS)
			pickReply = { needsInsecureConfirm: true }
			click(row(0))
			await flush()
			await wait(GUARD_MS)
			report(false)
			click(button('Fill'))
			await flush()
			expect(picks()).toHaveLength(1)
			expect(document.activeElement).toBe(message(REFUSED))
		})

		it('stays silent within 500 ms of the list showing or moving, where a second press works', async () => {
			await open(unlocked(), { settle: false })
			report(true)
			click(row(0))
			await flush()
			expect(options()).toHaveLength(3)
			await wait(GUARD_MS)
			post({ kind: 'inline.moved', key: KEY })
			click(row(0))
			await flush()
			expect(options()).toHaveLength(3)
			expect(picks()).toEqual([])
		})

		it('stays silent for a held key and a non-primary button', async () => {
			await open()
			post({ kind: 'inline.focus', key: KEY })
			key(row(0), 'Enter', { repeat: true })
			click(row(0), 10, 20, 1)
			click(row(0), 10, 20, 2)
			await flush()
			expect(options()).toHaveLength(3)
			expect(picks()).toEqual([])
		})
	})

	it('refuses a press made while visible when it went not visible before the release', async () => {
		await open(unlocked(), { settle: false })
		report(true)
		await wait(GUARD_MS * 2)
		press(row(0))
		await wait(50)
		report(false)
		await wait(50)
		report(true)
		row(0).dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await flush()
		expect(picks()).toEqual([])
	})
})
