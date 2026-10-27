import { i18n } from '#i18n'
import { MIN_KEEPIQ_VERSION } from '@/src/api/keepiq-version'
import {
	BAR_COMPACT_HEIGHT_PX, THEMES,
	type CaptureRefused, type CaptureStateReply, type ContentToNotification, type NotificationToBackground, type PickInput, type VaultWriteError, type WriteResult,
} from '@/src/messages'
import { GUARD_MS } from '@/src/autofill/visibility'
import { trackVisibility } from '@/src/autofill/visibility-tracker'
import { applyTheme, followMirror, mirroredTheme } from '../popup/theme'

/** A status region filled at once after it appears is not announced. */
const ANNOUNCE_MS = 250
/** A save that takes longer than this says so. */
const SLOW_MS = 1000
/** `send` could not reach the background, as opposed to its `null` reply. */
const UNREACHABLE = Symbol('unreachable')

type Mode = CaptureStateReply['mode']

const optional = (value: unknown) => value === undefined || typeof value === 'string'

function isState(reply: unknown): reply is CaptureStateReply {
	const r = reply as Partial<CaptureStateReply> | null
	return typeof r === 'object' && r !== null
		&& (r.mode === 'add' || (r.mode === 'update' && typeof r.itemName === 'string'))
		&& typeof r.host === 'string'
		&& optional(r.tabHost) && optional(r.itemName) && optional(r.username) && optional(r.shortcut)
		&& THEMES.includes(r.theme as never)
		&& Array.isArray(r.folders)
		&& r.folders.every((folder) => typeof folder?.id === 'string' && typeof folder.name === 'string')
}

function isWriteResult(reply: unknown): reply is WriteResult<null> {
	const r = reply as { ok?: unknown; error?: { code?: unknown } } | null
	return typeof r === 'object' && r !== null && (r.ok === true || (r.ok === false && typeof r.error?.code === 'string'))
}

const isRefusal = (reply: unknown): reply is CaptureRefused => (reply as Partial<CaptureRefused> | null)?.refused === 'not_visible'

async function send(message: NotificationToBackground): Promise<unknown> {
	try {
		return await browser.runtime.sendMessage(message)
	} catch {
		return UNREACHABLE
	}
}

/** What the bar says for a failed save or update; `undefined` is the background not answering. */
export function writeErrorText(error: VaultWriteError | undefined, mode: Mode): string {
	switch (error?.code) {
		case undefined: return i18n.t('notificationBar.errors.notResponding')
		case 'write_locked': return i18n.t('notificationBar.errors.writeLocked')
		case 'unverified': return i18n.t('notificationBar.errors.unverified')
		case 'view_only': return i18n.t('notificationBar.errors.viewOnly')
		// A save names only a folder that could have gone.
		case 'not_found': return mode === 'update' ? i18n.t('notificationBar.errors.loginGone') : i18n.t('writeErrors.folderGone')
		case 'locked': return i18n.t('notificationBar.errors.locked')
		case 'session_revoked': return i18n.t('errors.sessionRevoked')
		case 'account_changed': return i18n.t('notificationBar.errors.accountChanged')
		case 'offline': return i18n.t('writeErrors.offline')
		case 'server_error': return i18n.t('writeErrors.serverError')
		case 'forbidden': return i18n.t('writeErrors.forbidden')
		case 'policy': return i18n.t('writeErrors.policy')
		case 'invalid':
		case 'conflict': return i18n.t('writeErrors.invalid')
		case 'restricted': return i18n.t('writeErrors.restricted')
		case 'key_mismatch': return i18n.t('notificationBar.errors.keyMismatch')
		case 'stale_source': return i18n.t('notificationBar.errors.staleSource')
		case 'incomplete': return i18n.t('notificationBar.errors.incomplete')
		case 'bad_certificate': return i18n.t('notificationBar.errors.badCertificate')
		case 'keepiq_outdated': return i18n.t('notificationBar.errors.keepiqOutdated', { version: MIN_KEEPIQ_VERSION })
		default: return i18n.t('errors.unknown')
	}
}

/** The question, naming the login's own host where the tab shows another site. */
export function questionText({ mode, host, tabHost, itemName, username }: CaptureStateReply): string {
	if (mode === 'add') return i18n.t('notificationBar.saveQuestion', { host })
	const name = itemName ?? ''
	if (username) {
		return tabHost ? i18n.t('notificationBar.updateQuestionUserHost', { name, username, host }) : i18n.t('notificationBar.updateQuestionUser', { name, username })
	}
	return tabHost ? i18n.t('notificationBar.updateQuestionHost', { name, host }) : i18n.t('notificationBar.updateQuestion', { name })
}

function element<K extends keyof HTMLElementTagNameMap>(tag: K, attributes: Record<string, string>, ...children: Array<Node | string>) {
	const node = document.createElement(tag)
	for (const [name, value] of Object.entries(attributes)) node.setAttribute(name, value)
	node.append(...children)
	return node
}

const SVG = 'http://www.w3.org/2000/svg'

function svg(attributes: Record<string, string>, shapes: Array<[string, Record<string, string>]>): SVGSVGElement {
	const root = document.createElementNS(SVG, 'svg')
	for (const [name, value] of Object.entries({ 'aria-hidden': 'true', ...attributes })) root.setAttribute(name, value)
	for (const [tag, shape] of shapes) {
		const node = document.createElementNS(SVG, tag)
		for (const [name, value] of Object.entries(shape)) node.setAttribute(name, value)
		root.append(node)
	}
	return root
}

const stroke = { 'fill': 'none', 'stroke': 'currentColor', 'stroke-width': '2', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }

/** The app icon, as the popup's `Logo`. */
function logo(): SVGSVGElement {
	const icon = svg({ class: 'logo', width: '24', height: '24', viewBox: '0 0 512 512' }, [
		['polygon', { points: '256,26 455.19,141 455.19,371 256,486 56.81,371 56.81,141', fill: 'currentColor' }],
	])
	const lock = svg({ x: '136', y: '116', width: '240', height: '240', viewBox: '0 0 24 24', fill: '#ffffff' }, [
		['rect', { x: '5', y: '11', width: '14', height: '10', rx: '2' }],
		['path', { 'fill-rule': 'evenodd', d: 'M12 2A5 5 0 0 0 7 7v4h2V7a3 3 0 0 1 6 0v4h2V7A5 5 0 0 0 12 2Z' }],
	])
	icon.append(lock)
	return icon
}

/**
 * The save and update bar (design.md, Notification bar). It learns its key only from its parent's `postMessage`,
 * never from its URL, hears from the background only in replies, and never holds the password. Returns a teardown.
 */
export function startNotificationBar(): () => void {
	const bar = document.getElementById('bar')!
	const status = document.querySelector('[role="status"]')!
	document.documentElement.lang = i18n.t('language')
	document.title = i18n.t('notificationBar.label')
	bar.setAttribute('aria-label', i18n.t('notificationBar.label'))
	applyTheme(mirroredTheme())

	let stopped = false
	let key: string | null = null
	/** A `capture.focus` that came before its key was accepted. */
	let earlyFocusKey: string | null = null
	let state: CaptureStateReply | null = null
	/** One decision at a time; after Close or Never it stays set, as the background then removes the bar. */
	let busy = false
	let guardSince = Date.now()
	let press: { target: HTMLButtonElement; input: PickInput } | null = null
	/** The buttons behind the guard and what each does. */
	const guarded = new Map<HTMLButtonElement, (input: PickInput) => void>()
	let text: HTMLElement | null = null
	let title: HTMLElement | null = null
	let error: HTMLElement | null = null
	let primary: HTMLButtonElement | null = null
	let folder: HTMLSelectElement | null = null
	/** The host's one-row height for a short viewport (`barHostHeight`). */
	let compact = false
	let expandSent = false
	let announceTimer: ReturnType<typeof setTimeout> | undefined
	let slowTimer: ReturnType<typeof setTimeout> | undefined
	const visibility = trackVisibility(bar)

	const restartGuard = () => {
		guardSince = Date.now()
	}
	const settled = (input: PickInput) => input.at - guardSince >= GUARD_MS

	function announce(message: string) {
		clearTimeout(announceTimer)
		status.textContent = ''
		announceTimer = setTimeout(() => {
			status.textContent = message
		}, ANNOUNCE_MS)
	}

	/** Lets a clipped question or error scroll from the keyboard. */
	function scrollable() {
		if (!text || text.classList.contains('bar__saved')) return
		if (text.scrollHeight > text.clientHeight) text.tabIndex = 0
		else if (!text.classList.contains('bar__notice')) text.removeAttribute('tabindex')
	}

	function guardedButton(label: string, variant: 'primary' | 'secondary', act: (target: HTMLButtonElement, input: PickInput) => void) {
		const node = element('button', { type: 'button', class: `btn btn--${variant}` }, label)
		guarded.set(node, (input) => act(node, input))
		return node
	}

	function closeButton() {
		const close = element('button', { type: 'button', class: 'icon-btn bar__close', 'aria-label': i18n.t('notificationBar.close') },
			svg({ width: '18', height: '18', viewBox: '0 0 24 24', ...stroke }, [['path', { d: 'M18 6 6 18M6 6l12 12' }]]))
		close.addEventListener('click', () => void dismiss(false))
		return close
	}

	function folderChoice(folders: CaptureStateReply['folders']) {
		folder = element('select', { id: 'bar-folder', class: 'select__input' },
			element('option', { value: '' }, i18n.t('notificationBar.noFolder')),
			...folders.map((entry) => element('option', { value: entry.id }, entry.name)))
		return element('div', { class: 'bar__folder' },
			element('label', { class: 'bar__label', for: 'bar-folder' }, i18n.t('notificationBar.folder')),
			element('div', { class: 'select' }, folder, svg({ class: 'select__chevron', width: '16', height: '16', viewBox: '0 0 24 24', ...stroke }, [['path', { d: 'm6 9 6 6 6-6' }]])))
	}

	function render(mode: Mode, failure?: string) {
		const current = { ...state!, mode }
		bar.className = 'bar'
		guarded.clear()
		folder = null
		title = element('p', { class: 'bar__title', id: 'bar-title' }, questionText(current))
		error = element('p', { class: 'bar__error', id: 'bar-error' })
		error.hidden = true
		text = element('div', { class: 'bar__text' }, title, error)
		const buttons = element('div', { class: 'bar__buttons' })
		const parts: Node[] = [logo(), text]
		if (mode === 'add') {
			primary = guardedButton(i18n.t('notificationBar.save'), 'primary', (target, input) => void decide(target, 'add', input))
			buttons.append(primary, guardedButton(i18n.t('notificationBar.never'), 'secondary', (_target, input) => void dismiss(true, input)))
			parts.push(folderChoice(current.folders))
		} else {
			primary = guardedButton(i18n.t('notificationBar.update'), 'primary', (target, input) => void decide(target, 'update', input))
			buttons.append(primary)
		}
		// Shown only compact; the question stays whole in the DOM, so screen readers get all of it.
		const more = element('button', { 'type': 'button', 'class': 'icon-btn bar__expand', 'aria-label': i18n.t('notificationBar.expand'), 'aria-expanded': 'false' },
			svg({ width: '18', height: '18', viewBox: '0 0 24 24', ...stroke }, [['path', { d: 'm6 9 6 6 6-6' }]]))
		bar.replaceChildren(...parts, buttons, more, closeButton())
		if (failure) showError(failure)
		restartGuard()
		scrollable()
	}

	/** The error takes the question's place, which stays the button's description, so the smallest bar still shows it. */
	function showError(message: string) {
		error!.textContent = message
		error!.hidden = false
		title!.hidden = true
		primary?.setAttribute('aria-describedby', 'bar-error bar-title')
		// The buttons may have moved with the text.
		restartGuard()
		scrollable()
	}

	/** The question and its buttons go; the background removes the bar three seconds later. */
	function renderSaved(mode: Mode) {
		const hadFocus = bar.contains(document.activeElement)
		const message = mode === 'add' ? i18n.t('notificationBar.saved') : i18n.t('notificationBar.updated')
		text = element('p', { class: 'bar__text bar__saved', tabindex: '-1' },
			svg({ class: 'bar__check', width: '18', height: '18', viewBox: '0 0 24 24', ...stroke }, [['path', { d: 'M20 6 9 17l-5-5' }]]), message)
		guarded.clear()
		primary = null
		folder = null
		bar.className = 'bar bar--saved'
		bar.replaceChildren(logo(), text)
		// Focus would otherwise fall to the body; once moved, it reads the message out itself.
		if (hadFocus) text.focus()
		else announce(message)
	}

	/**
	 * For a press refused as not visible, here or by the background: says why and takes focus, which reads it out.
	 * Only Close stays, so nothing more is decided here, as in the inline list.
	 */
	function showNotVisible() {
		guarded.clear()
		primary = null
		folder = null
		text = element('p', { class: 'bar__text bar__notice', tabindex: '-1' }, i18n.t('notificationBar.notVisible'))
		bar.className = 'bar bar--notice'
		bar.replaceChildren(logo(), text, closeButton())
		text.focus()
	}

	/** Before sending: too soon after showing or moving stays silent, as a second press works; hidden, it says why. */
	function cleared(input: PickInput): boolean {
		if (busy || stopped || !key || !settled(input)) return false
		if (visibility.allows(input.at)) return true
		showNotVisible()
		return false
	}

	async function decide(target: HTMLButtonElement, mode: Mode, input: PickInput) {
		if (!cleared(input)) return
		busy = true
		target.setAttribute('aria-busy', 'true')
		slowTimer = setTimeout(() => announce(mode === 'add' ? i18n.t('notificationBar.saving') : i18n.t('notificationBar.updating')), SLOW_MS)
		const reply = await send(mode === 'add' ? { kind: 'capture.save', key: key!, folderId: folder?.value || null, input } : { kind: 'capture.update', key: key!, input })
		clearTimeout(slowTimer)
		if (stopped) return
		busy = false
		target.removeAttribute('aria-busy')
		if (isRefusal(reply)) return showNotVisible()
		if (!isWriteResult(reply)) return fail(reply === UNREACHABLE ? writeErrorText(undefined, mode) : i18n.t('errors.unknown'))
		if (reply.ok) return renderSaved(mode)
		const message = writeErrorText(reply.error, mode)
		if (mode === 'update' && reply.error.code === 'not_found') {
			// The item went meanwhile, so the bar offers to save the login as new.
			const hadFocus = bar.contains(document.activeElement)
			render('add', message)
			if (hadFocus) primary!.focus()
			return announce(message)
		}
		fail(message)
	}

	/** The buttons stay, so the user can try again. */
	function fail(message: string) {
		showError(message)
		announce(message)
	}

	/** Close and Escape need no guard: the worst a page can make of them is a closed bar. */
	async function dismiss(never: boolean, input?: PickInput) {
		if (never ? !cleared(input!) : busy || stopped || !key) return
		busy = true
		const reply = await send({ kind: 'capture.dismiss', key: key!, never, ...(input ? { input } : {}) })
		if (stopped) return
		if (reply === UNREACHABLE) busy = false
		else if (isRefusal(reply)) {
			busy = false
			showNotVisible()
		}
	}

	function keyInput(target: Element): PickInput {
		const rect = target.getBoundingClientRect()
		return { kind: 'key', at: Date.now(), x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
	}

	/** Compact, the controls are hidden, so focus goes to the expand control, which grows the bar. */
	function focusFirst() {
		const first = bar.querySelector<HTMLElement>(compact ? '.bar__expand, [tabindex="-1"]' : 'select, button:not(.bar__expand), [tabindex="-1"]')
		first?.focus()
	}

	/** Once per compact spell; the host shrinks the bar again when focus leaves it. */
	function expand() {
		if (!compact || expandSent || !key) return
		expandSent = true
		void send({ kind: 'capture.expand', key }).then((reply) => {
			if (reply === UNREACHABLE) expandSent = false
		})
	}

	function onResize() {
		const wasCompact = compact
		compact = window.innerHeight === BAR_COMPACT_HEIGHT_PX
		document.documentElement.classList.toggle('is-compact', compact)
		expandSent = false
		// The expand control that had focus is hidden at full height.
		if (wasCompact && !compact && document.activeElement?.classList.contains('bar__expand')) focusFirst()
		scrollable()
	}

	async function present(candidate: string) {
		const reply = await send({ kind: 'capture.state', key: candidate })
		if (stopped || key !== null || !isState(reply)) return
		key = candidate
		state = reply
		applyTheme(reply.theme)
		render(reply.mode)
		const question = questionText(reply)
		announce(reply.shortcut ? `${question} ${i18n.t('notificationBar.shortcutHint', { shortcut: reply.shortcut })}` : question)
		if (earlyFocusKey === key) focusFirst()
		earlyFocusKey = null
	}

	function onMessage(event: MessageEvent) {
		if (event.source !== window.parent) return
		const message = event.data as Partial<ContentToNotification> | null
		if (typeof message !== 'object' || message === null || typeof message.key !== 'string') return
		if (key === null) {
			if (message.kind === 'capture.init') void present(message.key)
			else if (message.kind === 'capture.focus') earlyFocusKey = message.key
			return
		}
		if (message.key !== key) return
		if (message.kind === 'capture.focus') focusFirst()
		else if (message.kind === 'capture.moved') restartGuard()
	}

	function onKeydown(event: KeyboardEvent) {
		// After a save too: focus then sits on the message, and the background closes such a bar at once on a dismiss.
		if (event.key === 'Escape') {
			if (!state) return
			event.preventDefault()
			return void dismiss(false)
		}
		expand()
		const act = guarded.get(event.target as HTMLButtonElement)
		if (!act || (event.key !== 'Enter' && event.key !== ' ')) return
		// The keydown is the press, so the button's own click from this key must not act again.
		event.preventDefault()
		if (!event.repeat) act(keyInput(event.target as Element))
	}

	function onPointerdown(event: PointerEvent) {
		expand()
		const target = (event.target as Element).closest?.('button') ?? null
		press = target && guarded.has(target) && event.button === 0
			? { target, input: { kind: 'pointer', at: Date.now(), x: event.clientX, y: event.clientY } }
			: null
	}

	/** Only a click with its own primary press acts; a keyboard's click has none, as its keydown already acted. */
	function onClick(event: MouseEvent) {
		const target = (event.target as Element).closest?.('button') ?? null
		const pressed = press
		press = null
		if (!target || pressed?.target !== target) return
		guarded.get(target)?.(pressed.input)
	}

	/** So a `frames[n].focus()` by the page lands on no button an Enter meant for the page would press. The select stays, as blurring would close its open list. */
	function onBlur() {
		earlyFocusKey = null
		press = null
		const active = document.activeElement
		if (active instanceof HTMLButtonElement) active.blur()
	}

	onResize()
	window.addEventListener('message', onMessage)
	window.addEventListener('blur', onBlur)
	window.addEventListener('storage', followMirror)
	window.addEventListener('resize', onResize)
	document.addEventListener('keydown', onKeydown)
	document.addEventListener('pointerdown', onPointerdown)
	document.addEventListener('click', onClick)
	document.addEventListener('focusin', expand)

	return () => {
		if (stopped) return
		stopped = true
		clearTimeout(announceTimer)
		clearTimeout(slowTimer)
		visibility.stop()
		window.removeEventListener('message', onMessage)
		window.removeEventListener('blur', onBlur)
		window.removeEventListener('storage', followMirror)
		window.removeEventListener('resize', onResize)
		document.removeEventListener('keydown', onKeydown)
		document.removeEventListener('pointerdown', onPointerdown)
		document.removeEventListener('click', onClick)
		document.removeEventListener('focusin', expand)
	}
}
