import { i18n } from '#i18n'
import {
	INLINE_FRAME_TITLES, INLINE_GAP_PX, INLINE_INSET_PX, INLINE_MODES, INLINE_RADIUS_PX, INLINE_ROW_PX, inlineClampHeight, inlineHeight, THEMES,
	type ContentToInlineList, type FormConfirmation, type FormQuestion, type InlineCard, type InlineCode, type InlineListToBackground, type InlineMode, type InlineRow,
	type InlineStateReply, type PasskeyRow, type PickInput,
} from '@/src/messages'
import { GUARD_MS } from '@/src/autofill/visibility'
import { trackVisibility } from '@/src/autofill/visibility-tracker'
import { answered, answeredOnlyHost } from '@/src/autofill/form-answers'
import { applyTheme, followMirror, mirroredTheme } from '../popup/theme'

/** A status region filled at once after it appears is not announced. */
const ANNOUNCE_MS = 250

type State = NonNullable<InlineStateReply>
/** `actions`: every button but Cancel, each answering with the press that chose it. */
interface Question {
	row: Row
	text: HTMLElement
	cancel: HTMLButtonElement
	actions: Map<Element, (input: PickInput) => void>
}
/** A shown row; with `credentialId` a passkey, whose `id` is its login's. */
type Row = InlineRow & { credentialId?: string }

const isObject = (value: unknown): value is object => typeof value === 'object' && value !== null

const isPasskeyRow = (row: unknown): row is PasskeyRow => {
	const r = row as Partial<PasskeyRow> | null
	return isObject(r) && [r.itemId, r.credentialId, r.name, r.userName].every((value) => typeof value === 'string')
}

const isDigits = (digits: unknown) => typeof digits === 'string' && /^\d{1,10}$/.test(digits)

const isCode = (code: unknown): code is InlineCode => {
	const c = code as Partial<InlineCode> | null
	return isObject(c) && (c.digits === null || isDigits(c.digits)) && typeof c.period === 'number' && Number.isFinite(c.validUntil)
}

const isCard = (card: unknown): card is InlineCard => {
	const c = card as Partial<InlineCard> | null
	return isObject(c) && (c.brand === null || typeof c.brand === 'string') && typeof c.last4 === 'string' && /^\d{4}$/.test(c.last4)
}

const isWalletRow = (row: InlineRow) => row.code === undefined && row.username === null

/** A code list has a code on every row; card and identity rows have no username, a card row may have a card and an identity row has a detail; a login row none of these. */
const ROW_CHECKS: Record<InlineMode, (row: InlineRow) => boolean> = {
	login: (row) => row.code === undefined && row.detail === undefined && row.card === undefined,
	code: (row) => isCode(row.code) && row.detail === undefined && row.card === undefined,
	card: (row) => isWalletRow(row) && row.detail === undefined && (row.card === undefined || isCard(row.card)),
	identity: (row) => isWalletRow(row) && row.card === undefined && (row.detail === null || typeof row.detail === 'string'),
}

function hasValidRows({ mode, rows }: Partial<State>): boolean {
	return Array.isArray(rows) && rows.every((row) => typeof row?.id === 'string' && typeof row.name === 'string'
		&& (row.username === null || typeof row.username === 'string') && ROW_CHECKS[mode as InlineMode](row))
}

/** Passkeys come only with an unlocked login list. */
function hasValidPasskeys({ state, mode, passkeys }: Partial<State>): boolean {
	return passkeys === undefined || (state === 'unlocked' && mode === 'login' && Array.isArray(passkeys) && passkeys.every(isPasskeyRow))
}

/** A known state, mode and theme, and a host. */
function hasValidHeader(r: Partial<State>): boolean {
	return (r.state === 'locked' || r.state === 'unlocked')
		&& INLINE_MODES.includes(r.mode as InlineMode)
		&& typeof r.host === 'string'
		&& THEMES.includes(r.theme as never)
}

function isState(reply: unknown): reply is State {
	const r = reply as Partial<State> | null
	return isObject(r) && hasValidHeader(r) && hasValidRows(r) && hasValidPasskeys(r)
}

/** A message from the parent that names a key. */
type KeyedMessage = Partial<ContentToInlineList> & { key: string }

function isKeyed(message: Partial<ContentToInlineList> | null): message is KeyedMessage {
	return isObject(message) && typeof message.key === 'string'
}

const rowIds = (rows: InlineRow[]) => rows.map((row) => row.id).join(' ')

/** When the earliest shown code ends. */
const earliestCodeEnd = (rows: InlineRow[]) => Math.min(...rows.map((row) => (row.code?.digits ? row.code.validUntil : Infinity)))

/** What the status region says once a state arrives. */
function statusText(reply: State): string {
	if (reply.state === 'locked') return i18n.t('inlineList.status.locked')
	const count = shownRows(reply).length
	if (reply.passkeys) return count === 0 ? i18n.t('inlineList.status.noMatchPasskeys', { host: reply.host }) : i18n.t('inlineList.status.countPasskeys', count)
	return count === 0 ? TEXTS[reply.mode].statusNoMatch(reply.host) : TEXTS[reply.mode].count(count)
}

/** Passkeys above the logins (spec `inline-list`, Passkeys in the list during a conditional request). */
const shownRows = ({ rows, passkeys = [] }: State): Row[] => [
	...passkeys.map((passkey) => ({ id: passkey.itemId, name: passkey.name, username: passkey.userName || null, credentialId: passkey.credentialId })),
	...rows,
]

/** The answers a pick carries: a login's insecure page, or every answer so far for a card or identity. */
type Answers = { confirmedInsecure?: true; confirmed?: FormConfirmation }

/** A passkey names its credential and carries no answers. */
function pickMessage(key: string, row: Row, input: PickInput, answers: Answers): InlineListToBackground {
	if (row.credentialId !== undefined) return { kind: 'inline.pick', key, itemId: row.id, credentialId: row.credentialId, input }
	return { kind: 'inline.pick', key, itemId: row.id, input, ...answers }
}

type PickAnswer = Partial<{ needsInsecureConfirm: true; needsFormConfirm: FormQuestion; refused: string }> | null | undefined

const isFormQuestion = (value: unknown): value is FormQuestion => {
	const q = value as Partial<FormQuestion> | null | undefined
	return typeof q?.insecure === 'boolean' && typeof q.host === 'string' && typeof q.origin === 'string'
		&& Array.isArray(q.frameHosts) && q.frameHosts.every((host) => typeof host === 'string')
}

interface ModeTexts {
	/** The listbox's name. */
	label: (host: string) => string
	noMatch: (host: string) => string
	locked: () => string
	count: (n: number) => string
	statusNoMatch: (host: string) => string
}

/** Cards and identities are not matched by site, so their texts name none. */
const TEXTS: Record<InlineMode, ModeTexts> = {
	login: {
		label: (host) => i18n.t('inlineList.label', { host }),
		noMatch: (host) => i18n.t('inlineList.noMatch', { host }),
		locked: () => i18n.t('inlineList.locked'),
		count: (n) => i18n.t('inlineList.status.count', n),
		statusNoMatch: (host) => i18n.t('inlineList.status.noMatch', { host }),
	},
	code: {
		label: (host) => i18n.t('inlineList.code.label', { host }),
		noMatch: (host) => i18n.t('inlineList.code.noMatch', { host }),
		locked: () => i18n.t('inlineList.code.locked'),
		count: (n) => i18n.t('inlineList.code.status.count', n),
		statusNoMatch: (host) => i18n.t('inlineList.code.status.noMatch', { host }),
	},
	card: {
		label: () => i18n.t(INLINE_FRAME_TITLES.card),
		noMatch: () => i18n.t('inlineList.card.noMatch'),
		locked: () => i18n.t('inlineList.card.locked'),
		count: (n) => i18n.t('inlineList.card.status.count', n),
		statusNoMatch: () => i18n.t('inlineList.card.noMatch'),
	},
	identity: {
		label: () => i18n.t(INLINE_FRAME_TITLES.identity),
		noMatch: () => i18n.t('inlineList.identity.noMatch'),
		locked: () => i18n.t('inlineList.identity.locked'),
		count: (n) => i18n.t('inlineList.identity.status.count', n),
		statusNoMatch: () => i18n.t('inlineList.identity.noMatch'),
	},
}

/** The rows `content` asks the host for, as the background counted them at `inline.open`, or null for content sized by its own height. */
function rowsFor(content: Element): number | null {
	if (content.getAttribute('role') === 'listbox') return content.childElementCount
	return content.matches('.message--locked, .message--muted') ? 1 : null
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

type Shape = [tag: 'circle' | 'rect' | 'path', attributes: Record<string, string>]

/** A globe for a login, a clock for a login's code, a key for a passkey, a card, a person for an identity. */
const ICONS: Record<InlineMode | 'passkey', Shape[]> = {
	login: [['circle', { cx: '12', cy: '12', r: '9' }], ['path', { d: 'M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18' }]],
	code: [['circle', { cx: '12', cy: '12', r: '9' }], ['path', { d: 'M12 7v5l3 2' }]],
	passkey: [['circle', { cx: '8', cy: '15', r: '4' }], ['path', { d: 'M10.8 12.2 20 3M17 6l3 3M14.5 8.5l2 2' }]],
	card: [['rect', { x: '2', y: '5', width: '20', height: '14', rx: '2' }], ['path', { d: 'M2 10h20M6 15h4' }]],
	identity: [['circle', { cx: '12', cy: '8', r: '4' }], ['path', { d: 'M4 21a8 8 0 0 1 16 0' }]],
}

function svgElement<K extends 'svg' | Shape[0]>(tag: K, attributes: Record<string, string>): SVGElementTagNameMap[K] {
	const node = document.createElementNS('http://www.w3.org/2000/svg', tag)
	for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value)
	return node
}

function rowIcon(kind: InlineMode | 'passkey'): SVGSVGElement {
	const svg = svgElement('svg', { class: 'row__icon', width: '32', height: '32', viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'aria-hidden': 'true' })
	svg.append(...ICONS[kind].map(([tag, attributes]) => svgElement(tag, attributes)))
	return svg
}

/** A row's second line: a username, an identity's detail or a card's brand and last four. */
function secondLine({ username, detail, card }: Row): string | null {
	if (card) return card.brand ? i18n.t('inlineList.card.detail', { brand: card.brand, last4: card.last4 }) : i18n.t('inlineList.card.detailNoBrand', { last4: card.last4 })
	return username || detail || null
}

/** `line`: the second line shown. A card's name says "ending in" instead, as screen readers read "*" as "star". */
function passkeyLabel(name: string, line: string | null): string {
	return line ? i18n.t('inlineList.passkeyRow', { name, username: line }) : i18n.t('inlineList.passkeyRowNoUser', { name })
}

function cardLabel(name: string, card: InlineCard): string {
	return card.brand ? i18n.t('inlineList.card.row', { name, brand: card.brand, last4: card.last4 }) : i18n.t('inlineList.card.rowNoBrand', { name, last4: card.last4 })
}

function optionLabel({ name, credentialId, card }: Row, line: string | null): string {
	if (credentialId !== undefined) return passkeyLabel(name, line)
	if (card) return cardLabel(name, card)
	return line ? i18n.t('inlineList.row', { name, username: line }) : name
}

/** The frame height the content needs. Locked and no match stay one row whatever their text, so the frame never tells them from one match. */
function heightFor(content: Element): number | null {
	const rows = rowsFor(content)
	return rows !== null ? inlineHeight(rows) : inlineClampHeight(content.getBoundingClientRect().height + 2 * INLINE_INSET_PX)
}

function rowText(name: string, line: string | null): HTMLElement {
	const text = element('span', { class: 'row__text' }, element('span', { class: 'row__name' }, name))
	if (line) text.append(element('span', { class: 'row__user' }, line))
	return text
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
	document.title = i18n.t(INLINE_FRAME_TITLES.login)
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
	let question: Question | null = null
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
	function askHeight(listKey: string, height: number | null) {
		if (height === null || height === requested) return
		requested = height
		notify({ kind: 'inline.resize', key: listKey, height })
	}

	/** Where the frame has no room for the whole question, its warning must still scroll into view from the keyboard. */
	function keepQuestionReachable(open: Question) {
		if (root.scrollHeight > root.clientHeight) open.text.tabIndex = 0
		else open.text.removeAttribute('tabindex')
	}

	function fit() {
		const content = root.firstElementChild
		if (!key || !content) return
		askHeight(key, heightFor(content))
		if (question) keepQuestionReachable(question)
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

	function rowOption(row: Row, mode: InlineMode): HTMLElement {
		const invalid = row.code?.digits === null
		const line = invalid ? i18n.t('inlineList.code.invalid') : secondLine(row)
		return element('li', {
			class: 'row', role: 'option', tabindex: '-1', 'aria-selected': 'false',
			'aria-label': optionLabel(row, line),
			...(invalid && { 'aria-disabled': 'true' }),
		}, rowIcon(row.credentialId === undefined ? mode : 'passkey'), rowText(row.name, line), ...(row.code ? codeParts(row.code) : []))
	}

	function renderRows(rows: Row[], host: string, mode: InlineMode) {
		rowsByElement.clear()
		const listbox = element('ul', { class: 'listbox', role: 'listbox', 'aria-label': TEXTS[mode].label(host) })
		for (const row of rows) {
			const option = rowOption(row, mode)
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
			tickCountdowns(now)
			if (!refreshing && state && now >= earliestCodeEnd(state.rows)) void refreshCodes()
		}, 1000)
	}

	function tickCountdowns(now: number) {
		for (const [option, row] of rowsByElement) {
			const timer = option.querySelector('.row__timer')
			if (timer && row.code) timer.textContent = String(secondsLeft(row.code.validUntil, now))
		}
	}

	/** Same rows: the codes change in place, so focus and the pick guard stay; other rows: rendered anew with the guard restarted, focus kept by id. */
	async function refreshCodes() {
		if (!key) return
		refreshing = true
		const reply = await send({ kind: 'inline.state', key })
		refreshing = false
		if (!stillCodes(reply) || !state) return
		const sameRows = rowIds(state.rows) === rowIds(reply.rows)
		state = reply
		if (sameRows) updateCodes(reply)
		else rerenderCodes(reply)
		watchCodes()
	}

	/** Whether a refresh reply still applies: nothing stopped the list or asked a question meanwhile, and it holds codes. */
	function stillCodes(reply: unknown): reply is State {
		if (stopped || question || !isState(reply)) return false
		return reply.mode === 'code' && reply.state === 'unlocked'
	}

	function updateCodes(reply: State) {
		for (const [option, row] of [...rowsByElement]) {
			const fresh = reply.rows.find((candidate) => candidate.id === row.id)!
			rowsByElement.set(option, fresh)
			option.querySelectorAll('.row__code, .row__timer').forEach((part) => part.remove())
			option.append(...(fresh.code ? codeParts(fresh.code) : []))
		}
	}

	function rerenderCodes(reply: State) {
		const focused = rowsByElement.get(document.activeElement as Element)?.id
		if (reply.rows.length) renderRows(reply.rows, reply.host, 'code')
		else render(reply)
		fit()
		restartGuard()
		const again = [...rowsByElement].find(([, row]) => row.id === focused)?.[0] as HTMLElement | undefined
		again?.focus()
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
			root.replaceChildren(element('div', { class: 'message message--locked' }, element('p', { id: 'locked-text' }, TEXTS[mode].locked()), unlock))
		} else if (rows.length === 0) {
			entry = element('p', { class: 'message message--muted', tabindex: '-1' }, shown.passkeys ? i18n.t('inlineList.noMatchPasskeys', { host }) : TEXTS[mode].noMatch(host))
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
		document.title = i18n.t(INLINE_FRAME_TITLES[reply.mode])
		applyTheme(reply.theme)
		render(reply)
		fit()
		watchCodes()
		announce(statusText(reply))
		if (earlyFocusKey === key) focusEntry()
		earlyFocusKey = null
	}

	/** The list's key when a pick may go ahead: too soon after showing or moving stays silent, as a second press works. */
	function pickKey(row: Row, input: PickInput): string | null {
		if (!key || pickLocked || !settled(input)) return null
		return row.code?.digits === null ? null : key
	}

	async function pick(row: Row, input: PickInput, answers: Answers = {}) {
		const listKey = pickKey(row, input)
		if (listKey === null) return
		pickLocked = true
		// Hidden by the page, it says why.
		if (!visibility.allows(input.at)) return showNotVisible()
		const reply = await send(pickMessage(listKey, row, input, answers))
		if (stopped) return
		settlePick({ row, answers }, reply as PickAnswer)
	}

	/** No answer unlocks the list for another pick; one with a question due asks it first. */
	function settlePick(picked: { row: Row; answers: Answers }, answer: PickAnswer) {
		const asking = questionFor(picked, answer)
		if (answer === undefined || asking) pickLocked = false
		if (asking) asking()
		else if (answer?.refused === 'not_visible') showNotVisible()
	}

	/** A card's or identity's next question builds on the answers this pick sent, since the background keeps none. */
	function questionFor({ row, answers }: { row: Row; answers: Answers }, answer: PickAnswer): (() => void) | null {
		if (answer?.needsInsecureConfirm === true) return () => askInsecure(row)
		const form = answer?.needsFormConfirm
		return isFormQuestion(form) ? () => askForm(row, { question: form, previous: answers.confirmed }) : null
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

	interface Action {
		label: string
		secondary?: true
		answer: (input: PickInput) => void
	}

	/** Focus starts on Cancel, so a stray Enter answers nothing. */
	function ask(row: Row, words: { title: string; detail: string }, actions: Action[]) {
		const title = element('p', { class: 'question__title', id: 'question-title' }, words.title)
		const text = element('p', { class: 'question__text', id: 'question-text' }, words.detail)
		const cancel = element('button', { type: 'button', class: 'btn btn--secondary' }, i18n.t('common.cancel'))
		const buttons = actions.map((action) => element('button', { type: 'button', class: `btn btn--${action.secondary ? 'secondary' : 'primary'}` }, action.label))
		cancel.addEventListener('click', cancelQuestion)
		root.replaceChildren(element('div', { class: 'question', role: 'alertdialog', 'aria-labelledby': 'question-title', 'aria-describedby': 'question-text' },
			title, text, element('div', { class: 'question__actions' }, cancel, ...buttons)))
		question = { row, text, cancel, actions: new Map(buttons.map((button, index) => [button, actions[index]!.answer])) }
		fit()
		restartGuard()
		cancel.focus()
	}

	function askInsecure(row: Row) {
		const fill = { label: i18n.t('inlineList.fill'), answer: (input: PickInput) => void pick(row, input, { confirmedInsecure: true }) }
		ask(row, { title: i18n.t('inlineList.insecureQuestion'), detail: i18n.t('inlineList.insecureDetail') }, [fill])
	}

	/** The insecure question first, for a card; its Fill goes on to the cross-site question when one is due, as in the popup. */
	function askForm(row: Row, form: { question: FormQuestion; previous: FormConfirmation | undefined; insecureAnswered?: true }) {
		const { question: asked, previous } = form
		const fillWith = (confirmed: FormConfirmation) => (input: PickInput) => void pick(row, input, { confirmed })
		const fill = { label: i18n.t('inlineList.fill'), answer: fillWith(answered(previous, asked)) }
		if (asked.insecure && !form.insecureAnswered) {
			const next = (input: PickInput) => settled(input) && askForm(row, { ...form, insecureAnswered: true })
			const words = { title: i18n.t('item.cardInsecureTitle'), detail: i18n.t('item.cardInsecureWarning') }
			return ask(row, words, [asked.frameHosts.length ? { ...fill, answer: next } : fill])
		}
		const hosts = asked.frameHosts.join(', ')
		ask(row, { title: i18n.t('item.crossSiteTitle', { hosts }), detail: i18n.t('item.crossSiteWarning', { hosts, host: asked.host }) }, [
			{ label: i18n.t('item.onlyHost', { host: asked.host }), secondary: true, answer: fillWith(answeredOnlyHost(previous, asked)) },
			fill,
		])
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

	/** Tab keeps the focus among the question's stops. */
	function tabInQuestion(event: KeyboardEvent, target: Element) {
		if (!root.contains(target)) return
		event.preventDefault()
		const stops = [...root.querySelectorAll<HTMLElement>('[tabindex="0"], button')]
		const next = stops.indexOf(target as HTMLElement) + (event.shiftKey ? -1 : 1)
		stops[(next + stops.length) % stops.length]?.focus()
	}

	/** Enter or Space on an answer button answers, once for a held key. */
	function answerByKey(event: KeyboardEvent, open: Question) {
		const target = event.target as Element
		if (!(event.key === 'Enter' || event.key === ' ') || !open.actions.has(target)) return
		event.preventDefault()
		if (!event.repeat) open.actions.get(target)!(keyInput(target))
	}

	function questionKey(event: KeyboardEvent, open: Question) {
		if (event.key === 'Escape') {
			event.preventDefault()
			cancelQuestion()
		} else if (event.key === 'Tab') tabInQuestion(event, event.target as Element)
		else answerByKey(event, open)
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

	const onRowOrEntry = (target: HTMLElement) => root.contains(target) && (rowsByElement.has(target) || target === entry)

	/** Keys on the body, as after a page focused this window, do nothing but Escape, which cannot pick. */
	function offListKey(event: KeyboardEvent) {
		if (event.key !== 'Escape' || !key) return
		event.preventDefault()
		notify({ kind: 'inline.close', key, refocus: true })
	}

	function onKeydown(event: KeyboardEvent) {
		if (question) return questionKey(event, question)
		const target = event.target as HTMLElement
		if (!onRowOrEntry(target)) return offListKey(event)
		const rows = [...rowsByElement.keys()] as HTMLElement[]
		rowKeyActions.get(event.key)?.({ event, rows, index: rows.indexOf(target), target })
	}

	const pickTarget = (event: Event): Element | null => {
		const target = (event.target as Element).closest?.('[role="option"], button') ?? null
		return target && (rowsByElement.has(target) || question?.actions.has(target)) ? target : null
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
		const answer = question?.actions.get(target)
		if (answer) answer(pressed.input)
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

	/** Before a key is accepted, only an init, or a focus to apply once it is. */
	function onMessageBeforeKey(message: { kind?: string; key: string }) {
		if (message.kind === 'inline.init') void present(message.key)
		else if (message.kind === 'inline.focus') earlyFocusKey = message.key
	}

	function onMessageForKey(message: { kind?: string }) {
		if (message.kind === 'inline.focus') focusEntry()
		else if (message.kind === 'inline.moved') restartGuard()
	}

	function onMessage(event: MessageEvent) {
		if (event.source !== window.parent) return
		const message = event.data as Partial<ContentToInlineList> | null
		if (!isKeyed(message)) return
		if (key === null) return onMessageBeforeKey(message)
		if (message.key === key) onMessageForKey(message)
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
