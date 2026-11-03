/**
 * The content-script half of login capture (spec `login-capture`, design.md § Content script): reports a group's
 * username and password at a click on its own submit button, Enter in its password field or its form's submit,
 * and at `beforeunload` for a group whose password was still empty then. Only the user submits: whether a bar
 * follows tells the page whether the username is saved, so a script's submit must never be a probe.
 */
import type { CaptureSubmitted } from '@/src/messages'
import { findGroups, groupOf, type FieldGroup } from './fields'

type Anchor = FieldGroup['anchor']

const BUTTON = 'button, input[type="submit"], input[type="button"], input[type="image"], [role="button"]'
/** The browsers' transient activation lifetime, for those without `navigator.userActivation`. */
export const ACTIVATION_MS = 5000
/** Activation-triggering input (HTML § Tracking user activation). */
const ACTIVATION_EVENTS = ['keydown', 'mousedown', 'pointerdown', 'pointerup', 'touchend'] as const

/** The password a submission of this group sets, or `null` for a group that has none. */
function passwordOf(group: FieldGroup): { field: HTMLInputElement; changed: boolean } | null {
	if (group.kind === 'login' && group.currentPassword) return { field: group.currentPassword, changed: false }
	const [field] = group.newPasswords
	// A sign-up is a new login.
	if (field && (group.kind === 'passwordChange' || group.kind === 'signUp')) return { field, changed: group.kind === 'passwordChange' }
	return null
}

// Node types rather than `instanceof`, as in fields.ts.
const isShadowRoot = (node: Node): node is ShadowRoot => node.nodeType === Node.DOCUMENT_FRAGMENT_NODE && 'host' in node
const isElement = (target: unknown): target is Element => (target as Node | null)?.nodeType === Node.ELEMENT_NODE
const isInput = (target: unknown): target is HTMLInputElement => isElement(target) && target.localName === 'input'

const contains = (anchor: Anchor, node: Node) => {
	for (let at: Node | null = node; at; at = isShadowRoot(at) ? at.host : at.parentNode) if (at === anchor) return true
	return false
}

export interface CaptureCollector {
	stop(): void
}

export function createCaptureCollector(send: (message: CaptureSubmitted) => void): CaptureCollector {
	/** The per-group flag: reported since its fields last changed, so one submission's click, Enter and submit report once. */
	const reported = new Set<Anchor>()
	/** Submitted while the password was empty; reported at `beforeunload` if it holds one by then. */
	const armed = new Set<Anchor>()
	const watchedForms = new WeakSet<HTMLFormElement>()
	/** Counts activations; a report uses one up, so the page gets at most one decision per user action. */
	let activation = 0
	let activatedAt = Number.NEGATIVE_INFINITY
	let usedActivation = -1

	function onActivation(event: Event): void {
		if (!event.isTrusted || (event as KeyboardEvent).key === 'Escape') return
		activation++
		activatedAt = Date.now()
	}

	const userActive = () => {
		const state = (navigator as { userActivation?: { isActive: boolean } }).userActivation
		return state ? state.isActive : Date.now() - activatedAt < ACTIVATION_MS
	}

	function submitted(group: FieldGroup, event: Event): void {
		// A script's `click()` or dispatched event is untrusted; its `requestSubmit()` is trusted but has no activation.
		if (!event.isTrusted) return
		const password = passwordOf(group)
		if (!password || reported.has(group.anchor)) return
		if (password.field.value === '') {
			armed.add(group.anchor)
			window.addEventListener('beforeunload', onUnload)
			return
		}
		if (!userActive() || usedActivation === activation) return
		usedActivation = activation
		armed.delete(group.anchor)
		reported.add(group.anchor)
		send({ kind: 'capture.submitted', username: group.username?.value || null, password: password.field.value, changed: password.changed })
	}

	function onClick(event: Event): void {
		const path = event.composedPath()
		if (!path.some((target) => isElement(target) && target.matches(BUTTON))) return
		// Only the group's own submit button: a header "Sign up" submits nothing.
		const group = findGroups(document).find((candidate) => candidate.submit !== null && path.includes(candidate.submit))
		if (group) submitted(group, event)
	}

	function onKeydown(event: KeyboardEvent): void {
		if (event.key !== 'Enter' || event.isComposing) return
		const field = event.composedPath()[0]
		if (!isInput(field) || field.type !== 'password') return
		const group = groupOf(field)
		if (group) submitted(group, event)
	}

	function onSubmit(event: Event): void {
		const form = event.target
		const group = findGroups(document).find((candidate) => candidate.anchor === form)
		if (group) submitted(group, event)
	}

	function onInput(event: Event): void {
		const field = event.composedPath()[0]
		if (!isElement(field)) return
		for (const anchor of reported) if (contains(anchor, field)) reported.delete(anchor)
		for (const anchor of armed) if (contains(anchor, field)) armed.delete(anchor)
		if (armed.size === 0) window.removeEventListener('beforeunload', onUnload)
		// A form in a shadow root sends its submit nowhere near the document.
		const form = isInput(field) ? field.form : null
		if (form && isShadowRoot(form.getRootNode()) && !watchedForms.has(form)) {
			watchedForms.add(form)
			form.addEventListener('submit', onSubmit, true)
		}
	}

	function onUnload(event: Event): void {
		for (const group of findGroups(document)) if (armed.has(group.anchor)) submitted(group, event)
		armed.clear()
		window.removeEventListener('beforeunload', onUnload)
	}

	// On the window, so an activation is counted before the document's listeners report on the same event.
	for (const type of ACTIVATION_EVENTS) window.addEventListener(type, onActivation, true)
	document.addEventListener('click', onClick, true)
	document.addEventListener('keydown', onKeydown, true)
	document.addEventListener('submit', onSubmit, true)
	document.addEventListener('input', onInput, true)
	return {
		stop() {
			for (const type of ACTIVATION_EVENTS) window.removeEventListener(type, onActivation, true)
			document.removeEventListener('click', onClick, true)
			document.removeEventListener('keydown', onKeydown, true)
			document.removeEventListener('submit', onSubmit, true)
			document.removeEventListener('input', onInput, true)
			window.removeEventListener('beforeunload', onUnload)
		},
	}
}
