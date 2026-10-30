import { i18n } from '#i18n'
import {
	INLINE_GAP_PX, INLINE_INSET_PX, INLINE_MIN_HEIGHT_PX, INLINE_RADIUS_PX, INLINE_ROW_PX, inlineClampHeight, inlineHeight, THEMES,
	type ContentToInlineList, type InlineListToBackground, type InlineRow, type InlineStateReply, type PickInput,
} from '@/src/messages'
import { GUARD_MS } from '@/src/autofill/visibility'
import { trackVisibility } from '@/src/autofill/visibility-tracker'
import { applyTheme, followMirror, mirroredTheme } from '../popup/theme'

/** A status region filled at once after it appears is not announced. */
const ANNOUNCE_MS = 250

type State = NonNullable<InlineStateReply>

function isState(reply: unknown): reply is State {
	const r = reply as Partial<State> | null
	return typeof r === 'object' && r !== null
		&& (r.state === 'locked' || r.state === 'unlocked')
		&& typeof r.host === 'string'
		&& THEMES.includes(r.theme as never)
		&& Array.isArray(r.rows)
		&& r.rows.every((row) => typeof row?.id === 'string' && typeof row.name === 'string' && (row.username === null || typeof row.username === 'string'))
}

async function send(message: InlineListToBackground): Promise<unknown> {
	try {
		return await browser.runtime.sendMessage(message)
	} catch {
		return undefined
	}
}

/** Before any `await`: Firefox below 149 opens the popup only from a user-input handler. */
function openPopup(): Promise<unknown> {
	const action = browser.action ?? browser.browserAction
	try {
		return action?.openPopup ? Promise.resolve(action.openPopup()) : Promise.reject(new Error('no openPopup'))
	} catch (error) {
		return Promise.reject(error)
	}
}

function element<K extends keyof HTMLElementTagNameMap>(tag: K, attributes: Record<string, string>, ...children: Array<Node | string>) {
	const node = document.createElement(tag)
	for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value)
	node.append(...children)
	return node
}

function globeIcon(): SVGSVGElement {
	const ns = 'http://www.w3.org/2000/svg'
	const svg = document.createElementNS(ns, 'svg')
	for (const [name, value] of Object.entries({ class: 'row__icon', width: '32', height: '32', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'aria-hidden': 'true' })) {
		svg.setAttribute(name, value)
	}
	const circle = document.createElementNS(ns, 'circle')
	circle.setAttribute('cx', '12')
	circle.setAttribute('cy', '12')
	circle.setAttribute('r', '9')
	const path = document.createElementNS(ns, 'path')
	path.setAttribute('d', 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18')
	svg.append(circle, path)
	return svg
}

const SIZES = { '--row': INLINE_ROW_PX, '--gap': INLINE_GAP_PX, '--inset': INLINE_INSET_PX, '--radius': INLINE_RADIUS_PX }

/**
 * The inline list page (design.md § Inline list). It learns its key only from its parent's `postMessage`,
 * never from its URL, and hears from the background only in replies. Returns a teardown.
 */
export function startInlineList(): () => void {
	const root = document.getElementById('list')!
	const status = document.querySelector('[role="status"]')!
	document.documentElement.lang = i18n.t('language')
	document.title = i18n.t('inlineList.frameTitle')
	for (const [name, px] of Object.entries(SIZES)) document.documentElement.style.setProperty(name, `${px}px`)
	applyTheme(mirroredTheme())

	let stopped = false
	let key: string | null = null
	/** An `inline.focus` that came before its key was accepted. */
	let earlyFocusKey: string | null = null
	let state: State | null = null
	let guardSince = Date.now()
	let press: { target: Element; input: PickInput } | null = null
	/** Set while a pick is pending, and for good once the background answered it, as it then closes the list. */
	let pickLocked = false
	let question: { row: InlineRow; text: HTMLElement; cancel: HTMLButtonElement; fill: HTMLButtonElement } | null = null
	let entry: HTMLElement | null = null
	const rowsByElement = new Map<Element, InlineRow>()
	let announceTimer: ReturnType<typeof setTimeout> | undefined
	/** The height last asked of the host; the first render always asks, in case the vault changed since the open. */
	let requested: number | null = null
	const visibility = trackVisibility(root)

	const restartGuard = () => {
		guardSince = Date.now()
	}
	const settled = (input: PickInput) => input.at - guardSince >= GUARD_MS
	const notify = (message: InlineListToBackground) => void send(message)

	/** Through the background: a `postMessage` to the parent would reach the page. */
	function fit() {
		const content = root.firstElementChild
		if (!key || !content) return
		// Locked and no match stay one row whatever their text, so the frame never tells them from one match.
		const height = content.getAttribute('role') === 'listbox' ? inlineHeight(content.childElementCount)
			: content.matches('.message--locked, .message--muted') ? INLINE_MIN_HEIGHT_PX
				: inlineClampHeight(content.getBoundingClientRect().height + 2 * INLINE_INSET_PX)
		if (height !== null && height !== requested) {
			requested = height
			notify({ kind: 'inline.resize', key, height })
		}
		// Where the frame has no room for the whole question, its warning must still scroll into view from the keyboard.
		if (question) {
			if (root.scrollHeight > root.clientHeight) question.text.tabIndex = 0
			else question.text.removeAttribute('tabindex')
		}
	}

	function keyInput(target: Element): PickInput {
		const rect = target.getBoundingClientRect()
		return { kind: 'key', at: Date.now(), x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
	}

	function renderRows(rows: InlineRow[], host: string) {
		rowsByElement.clear()
		const listbox = element('ul', { class: 'listbox', role: 'listbox', 'aria-label': i18n.t('inlineList.label', { host }) })
		for (const row of rows) {
			const username = row.username || null
			const text = element('span', { class: 'row__text' }, element('span', { class: 'row__name' }, row.name))
			if (username) text.append(element('span', { class: 'row__user' }, username))
			const option = element('li', {
				class: 'row', role: 'option', tabindex: '-1', 'aria-selected': 'false',
				'aria-label': username ? i18n.t('inlineList.row', { name: row.name, username }) : row.name,
			}, globeIcon(), text)
			rowsByElement.set(option, row)
			listbox.append(option)
		}
		listbox.firstElementChild?.setAttribute('tabindex', '0')
		entry = listbox.firstElementChild as HTMLElement | null
		root.replaceChildren(listbox)
	}

	function render({ state: vault, host, rows }: State) {
		question = null
		if (vault === 'locked') {
			const unlock = element('button', { type: 'button', class: 'btn btn--primary' }, i18n.t('inlineList.unlock'))
			unlock.addEventListener('click', () => {
				openPopup().then(
					() => key && notify({ kind: 'inline.close', key, refocus: false }),
					() => key && notify({ kind: 'inline.unlock', key }),
				)
			})
			unlock.setAttribute('aria-describedby', 'locked-text')
			entry = unlock
			root.replaceChildren(element('div', { class: 'message message--locked' }, element('p', { id: 'locked-text' }, i18n.t('inlineList.locked')), unlock))
		} else if (rows.length === 0) {
			entry = element('p', { class: 'message message--muted', tabindex: '-1' }, i18n.t('inlineList.noMatch', { host }))
			root.replaceChildren(entry)
		} else {
			renderRows(rows, host)
		}
		restartGuard()
	}

	function announce(text: string) {
		clearTimeout(announceTimer)
		status.textContent = ''
		announceTimer = setTimeout(() => {
			status.textContent = text
		}, ANNOUNCE_MS)
	}

	function focusEntry() {
		;(question?.cancel ?? entry)?.focus()
	}

	async function present(candidate: string) {
		const reply = await send({ kind: 'inline.state', key: candidate })
		if (stopped || key !== null || !isState(reply)) return
		key = candidate
		state = reply
		applyTheme(reply.theme)
		render(reply)
		fit()
		announce(reply.state === 'locked' ? i18n.t('inlineList.status.locked')
			: reply.rows.length === 0 ? i18n.t('inlineList.status.noMatch', { host: reply.host })
				: i18n.t('inlineList.status.count', reply.rows.length))
		if (earlyFocusKey === key) focusEntry()
		earlyFocusKey = null
	}

	async function pick(row: InlineRow, input: PickInput, confirmedInsecure = false) {
		// Too soon after showing or moving stays silent, as a second press works; hidden by the page, it says why.
		if (!key || pickLocked || !settled(input)) return
		pickLocked = true
		if (!visibility.allows(input.at)) return showNotVisible()
		const reply = await send({ kind: 'inline.pick', key, itemId: row.id, input, ...(confirmedInsecure ? { confirmedInsecure } : {}) })
		if (stopped) return
		const answer = reply as Partial<{ needsInsecureConfirm: true; refused: string }> | null | undefined
		if (answer === undefined) pickLocked = false
		else if (answer?.needsInsecureConfirm === true) {
			pickLocked = false
			ask(row)
		} else if (answer?.refused === 'not_visible') showNotVisible()
	}

	/** For a pick refused as not visible, here or by the background: the user learns why and where to fill instead; focus reads it out. Picks stay locked. */
	function showNotVisible() {
		question = null
		rowsByElement.clear()
		entry = element('p', { class: 'message message--notice', tabindex: '-1' }, i18n.t('inlineList.notVisible'))
		root.replaceChildren(entry)
		fit()
		entry.focus()
	}

	function ask(row: InlineRow) {
		const title = element('p', { class: 'question__title', id: 'question-title' }, i18n.t('inlineList.insecureQuestion'))
		const text = element('p', { class: 'question__text', id: 'question-text' }, i18n.t('inlineList.insecureDetail'))
		const cancel = element('button', { type: 'button', class: 'btn btn--secondary' }, i18n.t('common.cancel'))
		const fill = element('button', { type: 'button', class: 'btn btn--primary' }, i18n.t('inlineList.fill'))
		cancel.addEventListener('click', cancelQuestion)
		root.replaceChildren(element('div', { class: 'question', role: 'alertdialog', 'aria-labelledby': 'question-title', 'aria-describedby': 'question-text' },
			title, text, element('div', { class: 'question__actions' }, cancel, fill)))
		question = { row, text, cancel, fill }
		fit()
		restartGuard()
		cancel.focus()
	}

	function cancelQuestion() {
		if (!question || !state) return
		const { id } = question.row
		question = null
		renderRows(state.rows, state.host)
		fit()
		restartGuard()
		const option = [...rowsByElement].find(([, row]) => row.id === id)?.[0] as HTMLElement | undefined
		option?.focus()
	}

	function questionKey(event: KeyboardEvent, open: NonNullable<typeof question>) {
		const target = event.target as Element
		if (event.key === 'Escape') {
			event.preventDefault()
			cancelQuestion()
		} else if (event.key === 'Tab' && root.contains(target)) {
			event.preventDefault()
			const stops = [...root.querySelectorAll<HTMLElement>('[tabindex="0"], button')]
			const next = stops.indexOf(target as HTMLElement) + (event.shiftKey ? -1 : 1)
			stops[(next + stops.length) % stops.length]?.focus()
		} else if ((event.key === 'Enter' || event.key === ' ') && target === open.fill) {
			event.preventDefault()
			if (!event.repeat) void pick(open.row, keyInput(open.fill), true)
		}
	}

	function onKeydown(event: KeyboardEvent) {
		if (question) return questionKey(event, question)
		const target = event.target as HTMLElement
		// Keys on the body, as after a page focused this window, do nothing but Escape, which cannot pick.
		if (!root.contains(target) || !(rowsByElement.has(target) || target === entry)) {
			if (event.key === 'Escape' && key) {
				event.preventDefault()
				notify({ kind: 'inline.close', key, refocus: true })
			}
			return
		}
		const rows = [...rowsByElement.keys()] as HTMLElement[]
		const index = rows.indexOf(target)
		switch (event.key) {
			case 'ArrowDown':
				// Off a row, Down scrolls a clipped message.
				if (index < 0) return
				event.preventDefault()
				rows[Math.min(index + 1, rows.length - 1)]!.focus()
				break
			case 'ArrowUp':
				event.preventDefault()
				if (index > 0) rows[index - 1]!.focus()
				else if (key) notify({ kind: 'inline.refocus', key })
				break
			case 'Home':
			case 'End':
				if (index < 0) return
				event.preventDefault()
				rows[event.key === 'Home' ? 0 : rows.length - 1]!.focus()
				break
			case 'Enter':
			case ' ':
				if (index < 0) return
				event.preventDefault()
				if (!event.repeat) void pick(rowsByElement.get(target)!, keyInput(target))
				break
			case 'Escape':
			case 'Tab':
				event.preventDefault()
				if (key) notify({ kind: 'inline.close', key, refocus: true })
		}
	}

	const pickTarget = (event: Event): Element | null => {
		const target = (event.target as Element).closest?.('[role="option"], button') ?? null
		return target && (rowsByElement.has(target) || target === question?.fill) ? target : null
	}

	function onPointerdown(event: PointerEvent) {
		const target = pickTarget(event)
		press = target && event.button === 0 ? { target, input: { kind: 'pointer', at: Date.now(), x: event.clientX, y: event.clientY } } : null
	}

	/** A click inside the list never closes it: closing in some states only would tell the page whether logins match. */
	function onClick(event: MouseEvent) {
		const target = pickTarget(event)
		const pressed = press
		press = null
		if (!target || pressed?.target !== target) return
		if (question && target === question.fill) void pick(question.row, pressed.input, true)
		else void pick(rowsByElement.get(target)!, pressed.input)
	}

	function onFocusin(event: FocusEvent) {
		if (!rowsByElement.has(event.target as Element)) return
		for (const option of rowsByElement.keys()) {
			const current = option === event.target
			option.setAttribute('aria-selected', String(current))
			option.setAttribute('tabindex', current ? '0' : '-1')
		}
	}

	function onMessage(event: MessageEvent) {
		if (event.source !== window.parent) return
		const message = event.data as Partial<ContentToInlineList> | null
		if (typeof message !== 'object' || message === null || typeof message.key !== 'string') return
		if (key === null) {
			if (message.kind === 'inline.init') void present(message.key)
			else if (message.kind === 'inline.focus') earlyFocusKey = message.key
			return
		}
		if (message.key !== key) return
		if (message.kind === 'inline.focus') focusEntry()
		else if (message.kind === 'inline.moved') restartGuard()
	}

	/** So a `frames[n].focus()` by the page lands on no row. */
	function onBlur() {
		earlyFocusKey = null
		press = null
		for (const option of rowsByElement.keys()) option.setAttribute('aria-selected', 'false')
		const active = document.activeElement
		if (active instanceof HTMLElement && active !== document.body) active.blur()
	}

	window.addEventListener('message', onMessage)
	window.addEventListener('blur', onBlur)
	// The host's width or height changed: text may wrap differently.
	window.addEventListener('resize', fit)
	window.addEventListener('storage', followMirror)
	document.addEventListener('keydown', onKeydown)
	document.addEventListener('pointerdown', onPointerdown)
	document.addEventListener('click', onClick)
	document.addEventListener('focusin', onFocusin)

	return () => {
		if (stopped) return
		stopped = true
		clearTimeout(announceTimer)
		visibility.stop()
		window.removeEventListener('message', onMessage)
		window.removeEventListener('blur', onBlur)
		window.removeEventListener('resize', fit)
		window.removeEventListener('storage', followMirror)
		document.removeEventListener('keydown', onKeydown)
		document.removeEventListener('pointerdown', onPointerdown)
		document.removeEventListener('click', onClick)
		document.removeEventListener('focusin', onFocusin)
	}
}
