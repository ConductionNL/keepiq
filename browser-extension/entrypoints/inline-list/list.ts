import { i18n } from '#i18n'
import {
	INLINE_GAP_PX, INLINE_INSET_PX, INLINE_MIN_HEIGHT_PX, INLINE_RADIUS_PX, INLINE_ROW_PX, inlineClampHeight, inlineHeight, THEMES,
	type ContentToInlineList, type InlineCode, type InlineListToBackground, type InlineMode, type InlineRow, type InlineStateReply, type PasskeyRow, type PickInput,
} from '@/src/messages'
import { GUARD_MS } from '@/src/autofill/visibility'
import { trackVisibility } from '@/src/autofill/visibility-tracker'
import { applyTheme, followMirror, mirroredTheme } from '../popup/theme'

/** A status region filled at once after it appears is not announced. */
const ANNOUNCE_MS = 250

type State = NonNullable<InlineStateReply>
/** A shown row; with `credentialId` a passkey, whose `id` is its login's. */
type Row = InlineRow & { credentialId?: string }

const isPasskeyRow = (row: unknown): row is PasskeyRow => {
	const r = row as Partial<PasskeyRow> | null
	return typeof r === 'object' && r !== null && [r.itemId, r.credentialId, r.name, r.userName].every((value) => typeof value === 'string')
}

const isCode = (code: unknown): code is InlineCode => {
	const c = code as Partial<InlineCode> | null
	return typeof c === 'object' && c !== null && (c.digits === null || (typeof c.digits === 'string' && /^\d{1,10}$/.test(c.digits)))
		&& typeof c.period === 'number' && Number.isFinite(c.validUntil)
}

/** A code list has a code on every row, a login list on none. */
function hasValidRows({ mode, rows }: Partial<State>): boolean {
	return Array.isArray(rows) && rows.every((row) => typeof row?.id === 'string' && typeof row.name === 'string'
		&& (row.username === null || typeof row.username === 'string') && (mode === 'code' ? isCode(row.code) : row.code === undefined))
}

/** Passkeys come only with an unlocked login list. */
function hasValidPasskeys({ state, mode, passkeys }: Partial<State>): boolean {
	return passkeys === undefined || (state === 'unlocked' && mode === 'login' && Array.isArray(passkeys) && passkeys.every(isPasskeyRow))
}

function isState(reply: unknown): reply is State {
	const r = reply as Partial<State> | null
	return typeof r === 'object' && r !== null
		&& (r.state === 'locked' || r.state === 'unlocked')
		&& (r.mode === 'login' || r.mode === 'code')
		&& typeof r.host === 'string'
		&& THEMES.includes(r.theme as never)
		&& hasValidRows(r)
		&& hasValidPasskeys(r)
}

/** Passkeys above the logins (spec `inline-list`, Passkeys in the list during a conditional request). */
const shownRows = ({ rows, passkeys = [] }: State): Row[] => [
	...passkeys.map((passkey) => ({ id: passkey.itemId, name: passkey.name, username: passkey.userName || null, credentialId: passkey.credentialId })),
	...rows,
]

/** A passkey names its credential; only a login can carry the confirmed insecure fill. */
function pickMessage(key: string, row: Row, input: PickInput, confirmedInsecure: boolean): InlineListToBackground {
	if (row.credentialId !== undefined) return { kind: 'inline.pick', key, itemId: row.id, credentialId: row.credentialId, input }
	return { kind: 'inline.pick', key, itemId: row.id, input, ...(confirmedInsecure ? { confirmedInsecure } : {}) }
}

type PickAnswer = Partial<{ needsInsecureConfirm: true; refused: string }> | null | undefined

/** The texts that differ between the login list and the code list. */
function texts(mode: InlineMode) {
	return mode === 'code'
		? {
			frameTitle: () => i18n.t('inlineList.code.frameTitle'),
			label: (host: string) => i18n.t('inlineList.code.label', { host }),
			noMatch: (host: string) => i18n.t('inlineList.code.noMatch', { host }),
			locked: () => i18n.t('inlineList.code.locked'),
			count: (n: number) => i18n.t('inlineList.code.status.count', n),
			statusNoMatch: (host: string) => i18n.t('inlineList.code.status.noMatch', { host }),
		}
		: {
			frameTitle: () => i18n.t('inlineList.frameTitle'),
			label: (host: string) => i18n.t('inlineList.label', { host }),
			noMatch: (host: string) => i18n.t('inlineList.noMatch', { host }),
			locked: () => i18n.t('inlineList.locked'),
			count: (n: number) => i18n.t('inlineList.status.count', n),
			statusNoMatch: (host: string) => i18n.t('inlineList.status.noMatch', { host }),
		}
}

/** Seconds until `validUntil`, as the countdown shows them. */
const secondsLeft = (validUntil: number, now = Date.now()) => Math.max(0, Math.ceil((validUntil - now) / 1000))

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

/** A globe for a login, a clock for a login's code, a key for a passkey. */
function rowIcon(kind: InlineMode | 'passkey'): SVGSVGElement {
	const ns = 'http://www.w3.org/2000/svg'
	const svg = document.createElementNS(ns, 'svg')
	for (const [name, value] of Object.entries({ class: 'row__icon', width: '32', height: '32', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'aria-hidden': 'true' })) {
		svg.setAttribute(name, value)
	}
	const circle = document.createElementNS(ns, 'circle')
	const [cx, cy, r] = kind === 'passkey' ? ['8', '15', '4'] : ['12', '12', '9']
	circle.setAttribute('cx', cx)
	circle.setAttribute('cy', cy)
	circle.setAttribute('r', r)
	const path = document.createElementNS(ns, 'path')
	path.setAttribute('d', kind === 'passkey' ? 'M10.8 12.2 20 3M17 6l3 3M14.5 8.5l2 2' : kind === 'code' ? 'M12 7v5l3 2' : 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18')
	svg.append(circle, path)
	return svg
}

function optionLabel(row: Row): string {
	if (row.credentialId !== undefined) return row.username ? i18n.t('inlineList.passkeyRow', { name: row.name, username: row.username }) : i18n.t('inlineList.passkeyRowNoUser', { name: row.name })
	return row.username ? i18n.t('inlineList.row', { name: row.name, username: row.username }) : row.name
}

const SIZES = { '--row': INLINE_ROW_PX, '--gap': INLINE_GAP_PX, '--inset': INLINE_INSET_PX, '--radius': INLINE_RADIUS_PX }

/** A key pressed on a row or the entry, with the rows in order and the focused one's index. */
interface RowKey {
	event: KeyboardEvent
	rows: HTMLElement[]
	index: number
	target: HTMLElement
}

/** Focus the first or last row; off a row, the keys do nothing. */
function focusEdge({ event, rows, index }: RowKey) {
	if (0 > index) return
	event.preventDefault()
	rows[event.key === 'Home' ? 0 : rows.length - 1]!.focus()
}

/** Focus the next row; off a row, Down scrolls a clipped message. */
function focusNext({ event, rows, index }: RowKey) {
	if (0 > index) return
	event.preventDefault()
	rows[Math.min(index + 1, rows.length - 1)]!.focus()
}

/** Adds each listener to `target`; the returned teardown removes them again. */
function listenAll<M>(target: EventTarget, listeners: { [K in keyof M]?: (event: M[K]) => void }): () => void {
	const entries = Object.entries(listeners) as [string, EventListener][]
	for (const [type, listener] of entries) target.addEventListener(type, listener)
	return () => {
		for (const [type, listener] of entries) target.removeEventListener(type, listener)
	}
}

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
	let question: { row: Row; text: HTMLElement; cancel: HTMLButtonElement; fill: HTMLButtonElement } | null = null
	let entry: HTMLElement | null = null
	const rowsByElement = new Map<Element, Row>()
	let announceTimer: ReturnType<typeof setTimeout> | undefined
	/** The height last asked of the host; the first render always asks, in case the vault changed since the open. */
	let requested: number | null = null
	const visibility = trackVisibility(root)
	/** Code mode: ticks the countdowns and asks for fresh codes once the earliest ends. */
	let codeTimer: ReturnType<typeof setInterval> | undefined
	let refreshing = false

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

	/** The code in two halves and the seconds left, both hidden from assistive technology so they are not re-read every period. */
	function codeParts(code: InlineCode): HTMLElement[] {
		if (code.digits === null) return []
		const half = Math.ceil(code.digits.length / 2)
		return [
			element('span', { class: 'row__code', 'aria-hidden': 'true' }, element('span', {}, code.digits.slice(0, half)), element('span', {}, code.digits.slice(half))),
			element('span', { class: 'row__timer', 'aria-hidden': 'true' }, String(secondsLeft(code.validUntil))),
		]
	}

	function renderRows(rows: Row[], host: string, mode: InlineMode) {
		rowsByElement.clear()
		const listbox = element('ul', { class: 'listbox', role: 'listbox', 'aria-label': texts(mode).label(host) })
		for (const row of rows) {
			const invalid = row.code?.digits === null
			const username = invalid ? i18n.t('inlineList.code.invalid') : row.username || null
			const text = element('span', { class: 'row__text' }, element('span', { class: 'row__name' }, row.name))
			if (username) text.append(element('span', { class: 'row__user' }, username))
			const option = element('li', {
				class: 'row', role: 'option', tabindex: '-1', 'aria-selected': 'false',
				'aria-label': optionLabel({ ...row, username }),
				...(invalid && { 'aria-disabled': 'true' }),
			}, rowIcon(row.credentialId === undefined ? mode : 'passkey'), text, ...(row.code ? codeParts(row.code) : []))
			rowsByElement.set(option, row)
			listbox.append(option)
		}
		listbox.firstElementChild?.setAttribute('tabindex', '0')
		entry = listbox.firstElementChild as HTMLElement | null
		root.replaceChildren(listbox)
	}

	/** One interval for every countdown; the codes themselves only ever come from the background. */
	function watchCodes() {
		clearInterval(codeTimer)
		if (state?.mode !== 'code' || !state.rows.some((row) => row.code?.digits)) return
		codeTimer = setInterval(() => {
			const now = Date.now()
			for (const [option, row] of rowsByElement) {
				const timer = option.querySelector('.row__timer')
				if (timer && row.code) timer.textContent = String(secondsLeft(row.code.validUntil, now))
			}
			if (!refreshing && state && now >= Math.min(...state.rows.map((row) => (row.code?.digits ? row.code.validUntil : Infinity)))) void refreshCodes()
		}, 1000)
	}

	/** Same rows: the codes change in place, so focus and the pick guard stay; other rows: rendered anew with the guard restarted, focus kept by id. */
	async function refreshCodes() {
		if (!key) return
		refreshing = true
		const reply = await send({ kind: 'inline.state', key })
		refreshing = false
		if (stopped || question || !isState(reply) || reply.mode !== 'code' || reply.state !== 'unlocked' || !state) return
		const before = state.rows.map((row) => row.id).join(' ')
		state = reply
		if (before === reply.rows.map((row) => row.id).join(' ')) {
			for (const [option, row] of [...rowsByElement]) {
				const fresh = reply.rows.find((candidate) => candidate.id === row.id)!
				rowsByElement.set(option, fresh)
				option.querySelectorAll('.row__code, .row__timer').forEach((part) => part.remove())
				option.append(...(fresh.code ? codeParts(fresh.code) : []))
			}
		} else {
			const focused = rowsByElement.get(document.activeElement as Element)?.id
			if (reply.rows.length) renderRows(reply.rows, reply.host, 'code')
			else render(reply)
			fit()
			restartGuard()
			const again = [...rowsByElement].find(([, row]) => row.id === focused)?.[0] as HTMLElement | undefined
			again?.focus()
		}
		watchCodes()
	}

	function render(shown: State) {
		const { state: vault, mode, host } = shown
		const rows = shownRows(shown)
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
			root.replaceChildren(element('div', { class: 'message message--locked' }, element('p', { id: 'locked-text' }, texts(mode).locked()), unlock))
		} else if (rows.length === 0) {
			entry = element('p', { class: 'message message--muted', tabindex: '-1' }, shown.passkeys ? i18n.t('inlineList.noMatchPasskeys', { host }) : texts(mode).noMatch(host))
			root.replaceChildren(entry)
		} else {
			renderRows(rows, host, mode)
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
		document.title = texts(reply.mode).frameTitle()
		applyTheme(reply.theme)
		render(reply)
		fit()
		watchCodes()
		const count = shownRows(reply).length
		announce(reply.state === 'locked' ? i18n.t('inlineList.status.locked')
			: reply.passkeys ? (count === 0 ? i18n.t('inlineList.status.noMatchPasskeys', { host: reply.host }) : i18n.t('inlineList.status.countPasskeys', count))
				: count === 0 ? texts(reply.mode).statusNoMatch(reply.host) : texts(reply.mode).count(count))
		if (earlyFocusKey === key) focusEntry()
		earlyFocusKey = null
	}

	async function pick(row: Row, input: PickInput, confirmedInsecure = false) {
		// Too soon after showing or moving stays silent, as a second press works; hidden by the page, it says why.
		if (!key || pickLocked || !settled(input) || row.code?.digits === null) return
		pickLocked = true
		if (!visibility.allows(input.at)) return showNotVisible()
		const reply = await send(pickMessage(key, row, input, confirmedInsecure))
		if (stopped) return
		settlePick(row, reply as PickAnswer)
	}

	/** No answer unlocks the list for another pick; one that needs the insecure fill confirmed asks first. */
	function settlePick(row: Row, answer: PickAnswer) {
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

	function ask(row: Row) {
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
		renderRows(shownRows(state), state.host, state.mode)
		fit()
		restartGuard()
		const option = [...rowsByElement].find(([, row]) => row.id === id && row.credentialId === undefined)?.[0] as HTMLElement | undefined
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

	/** Pick the focused row; held keys pick once. */
	function pickFocused({ event, index, target }: RowKey) {
		if (0 > index) return
		event.preventDefault()
		if (!event.repeat) void pick(rowsByElement.get(target)!, keyInput(target))
	}

	/** Close the list and give the focus back to the page's field. */
	function closeList({ event }: RowKey) {
		event.preventDefault()
		if (key) notify({ kind: 'inline.close', key, refocus: true })
	}

	/** Focus the previous row, or from the first one give the focus back to the page's field. */
	function focusPrevious({ event, rows, index }: RowKey) {
		event.preventDefault()
		if (index > 0) rows[index - 1]!.focus()
		else if (key) notify({ kind: 'inline.refocus', key })
	}

	/** What each key does on a row or the entry. */
	function rowKeyActionMap(): Map<string, (at: RowKey) => void> {
		return new Map([
			['ArrowDown', focusNext],
			['ArrowUp', focusPrevious],
			['Home', focusEdge],
			['End', focusEdge],
			['Enter', pickFocused],
			[' ', pickFocused],
			['Escape', closeList],
			['Tab', closeList],
		])
	}

	const rowKeyActions = rowKeyActionMap()

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
		rowKeyActions.get(event.key)?.({ event, rows, index: rows.indexOf(target), target })
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

	// On resize the host's width or height changed: text may wrap differently.
	const unlistenWindow = listenAll<WindowEventMap>(window, { message: onMessage, blur: onBlur, resize: fit, storage: followMirror })
	const unlistenDocument = listenAll<DocumentEventMap>(document, { keydown: onKeydown, pointerdown: onPointerdown, click: onClick, focusin: onFocusin })

	return () => {
		if (stopped) return
		stopped = true
		clearTimeout(announceTimer)
		clearInterval(codeTimer)
		visibility.stop()
		unlistenWindow()
		unlistenDocument()
	}
}
