// Field detection and classification, spec `autofill` § Field detection; precedence in design.md.

export type FieldRole = 'username' | 'currentPassword' | 'newPassword' | 'oneTimeCode' | 'other'
export type GroupKind = 'login' | 'passwordChange' | 'signUp' | 'other'
type Root = Document | ShadowRoot
type Keyword = 'login' | 'next' | 'signUp' | 'subscribe' | 'ambiguous'

export interface FieldGroup {
	kind: GroupKind
	/** The `<form>`, or for formless fields the element or root they are grouped on. */
	anchor: Element | Root
	/** Usable fields in DOM order, open shadow roots included. */
	fields: HTMLInputElement[]
	username: HTMLInputElement | null
	currentPassword: HTMLInputElement | null
	newPasswords: HTMLInputElement[]
	submit: HTMLElement | null
	roles: Map<HTMLInputElement, FieldRole>
}

const FIELD_TYPES = new Set(['text', 'email', 'tel', 'number', 'password'])
const BUTTONS = 'button:not([type="reset"]), input[type="submit"], input[type="button"], input[type="image"], [role="button"]'

// First match wins: "Aanmelden voor nieuwsbrief" is a subscription, "Log in or sign up" a login.
const KEYWORDS: Array<[Keyword, RegExp]> = [
	['subscribe', /subscribe|inschrijven|nieuwsbrief|newsletter/],
	['login', /\b(log ?in|sign ?in|inloggen)\b/],
	['signUp', /\b(register|registreren|registreer|sign ?up|create (an |your )?account|account aanmaken)\b/],
	['next', /\b(next|continue|volgende|doorgaan)\b/],
	// Dutch for both log in and sign up; it counts only beside a password field.
	['ambiguous', /\baanmelden\b/],
]

const USERNAME_TEXT = /user|login|log in|e ?mail|gebruiker|account|inlog|identifier/
const PHONE_TEXT = /phone|telefoon|mobiel|mobile/
const NOT_USERNAME_TEXT = /search|zoek|captcha|coupon|voucher|promo/
const OTP_TEXT = /\b(otp|totp|2fa|mfa|code|token)\b|verif|authenticat|eenmalig/
const NEW_TEXT = /new|nieuw|confirm|bevestig|repeat|herhaal|again|retype/
const CURRENT_TEXT = /current|old|huidig|oud|existing/
const OTHER_TOKENS =
	/^(name|honorific-.+|given-name|additional-name|family-name|nickname|organization(-title)?|street-address|address-.+|country(-name)?|postal-code|cc-.+|transaction-.+|language|bday.*|sex|url|photo|tel.*|impp)$/

const normalize = (text: string) =>
	text.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase().replace(/[\s_\-.:]+/g, ' ').trim()

const keywordOf = (text: string): Keyword | null => KEYWORDS.find(([, pattern]) => pattern.test(text))?.[0] ?? null

/** The recognised WHATWG field name; `off`, `false` and unknown tokens give null. */
function tokenOf(input: HTMLInputElement): string | null {
	for (const token of (input.getAttribute('autocomplete') ?? '').toLowerCase().split(/\s+/)) {
		if (['username', 'email', 'current-password', 'new-password', 'one-time-code'].includes(token)) return token
		if (OTHER_TOKENS.test(token)) return token
	}
	return null
}

function fieldText(input: HTMLInputElement): string {
	const root = input.getRootNode() as Root
	const labelledBy = (input.getAttribute('aria-labelledby') ?? '').split(/\s+/).filter(Boolean)
	return normalize(
		[
			input.name,
			input.id,
			input.placeholder,
			input.getAttribute('aria-label') ?? '',
			...[...(input.labels ?? [])].map((label) => label.textContent ?? ''),
			...labelledBy.map((id) => root.getElementById(id)?.textContent ?? ''),
		].join(' '),
	)
}

const buttonText = (button: Element) =>
	normalize(
		[button.textContent, (button as HTMLInputElement).value, ...['aria-label', 'title', 'alt'].map((name) => button.getAttribute(name))].join(' '),
	)

function isVisible(el: Element): boolean {
	if (el.closest('[hidden]') || el.getAttribute('aria-hidden') === 'true') return false
	if (el.checkVisibility?.({ checkVisibilityCSS: true, visibilityProperty: true }) === false) return false
	const { width, height } = el.getBoundingClientRect()
	return width > 0 && height > 0
}

const isUsable = (input: HTMLInputElement) =>
	FIELD_TYPES.has(input.type) && !input.disabled && !input.readOnly && !input.closest('fieldset[disabled]') && isVisible(input)

const isSubmitButton = (el: Element) =>
	(el.localName === 'button' || el.localName === 'input') && ['submit', 'image'].includes((el as HTMLInputElement).type)

// Composed tree: a shadow root's parent is its host, so groups span design-system components.
const isShadowRoot = (node: Node): node is ShadowRoot => node.nodeType === Node.DOCUMENT_FRAGMENT_NODE && 'host' in node
const parentOf = (node: Node): Node | null => (isShadowRoot(node) ? node.host : node.parentNode)

function contains(ancestor: Node, node: Node): boolean {
	for (let at: Node | null = node; at; at = parentOf(at)) if (at === ancestor) return true
	return false
}

function formOf(el: Element): HTMLFormElement | null {
	const owner = (el as HTMLInputElement).form
	if (owner) return owner
	for (let at: Node | null = el; at; at = parentOf(at)) if ((at as Element).localName === 'form') return at as HTMLFormElement
	return null
}

interface Facts {
	text: string
	token: string | null
	otp: boolean
}

function factsOf(input: HTMLInputElement): Facts {
	const text = fieldText(input)
	const token = tokenOf(input)
	// The token alone never decides: it needs a second signal.
	let otp = token === 'one-time-code' ? 2 : 0
	if (OTP_TEXT.test(text) && !/post|zip/.test(text)) otp += 2
	if (input.getAttribute('inputmode') === 'numeric' || input.type === 'number') otp += 1
	const max = Number(input.getAttribute('maxlength'))
	if (max >= 4 && max <= 8) otp += 1
	return { text, token, otp: otp >= 3 }
}

function usernameScore({ text, token }: Facts, phone: boolean): number {
	const fits = token === 'username' || token === 'email' || (phone && token?.startsWith('tel'))
	let score = fits ? 1 : token ? -1 : 0
	if (USERNAME_TEXT.test(text) || (phone && PHONE_TEXT.test(text))) score += 2
	if (NOT_USERNAME_TEXT.test(text)) score -= 2
	return score
}

/** Above zero leans new password, below zero current. */
function newPasswordScore({ text, token }: Facts): number {
	let score = token === 'new-password' ? 1 : token === 'current-password' ? -1 : 0
	if (NEW_TEXT.test(text)) score += 2
	if (CURRENT_TEXT.test(text)) score -= 2
	return score
}

type Follows = (a: Element, b: Element) => boolean

/** `buttons` are the group's keyword buttons in DOM order. */
function buildGroup(follows: Follows, anchor: Element | Root, fields: HTMLInputElement[], buttons: HTMLElement[]): FieldGroup {
	const facts = new Map(fields.map((field) => [field, factsOf(field)]))
	const fact = (field: HTMLInputElement) => facts.get(field)!
	const score = (field: HTMLInputElement) => usernameScore(fact(field), field.type === 'tel')
	const isText = (field: HTMLInputElement) => (field.type === 'text' || field.type === 'email') && !fact(field).otp
	// A phone number is a username only with evidence, and only in a login.
	const isCandidate = (field: HTMLInputElement, phone: boolean) =>
		isText(field) || (phone && field.type === 'tel' && !fact(field).otp && score(field) > 0)
	const passwords = fields.filter((field) => field.type === 'password' && !fact(field).otp)
	const [firstPassword] = passwords
	const form = (anchor as Element).localName === 'form' ? (anchor as HTMLFormElement) : null

	// The browser's default button wins over a keyword button placed before it, such as a "Register" link-button.
	const defaultButton = form ? [...form.elements].find(isSubmitButton) : undefined
	const lead = firstPassword ?? fields.find((field) => isCandidate(field, true) && score(field) > 0) ?? fields[0]
	const submit =
		(defaultButton && isVisible(defaultButton) && keywordOf(buttonText(defaultButton)) ? (defaultButton as HTMLElement) : null) ??
		(lead && buttons.find((button) => follows(lead, button))) ??
		null
	const submitKeyword = submit ? keywordOf(buttonText(submit)) : null
	const keywords = new Set([submitKeyword, ...fields.map((field) => keywordOf(fact(field).text))])
	const loginKeyword = keywords.has('login') || keywords.has('next')

	let currentPassword: HTMLInputElement | null = null
	let newPasswords: HTMLInputElement[] = []
	const scores = passwords.map((field) => newPasswordScore(fact(field)))
	if (firstPassword && passwords.length === 1) {
		let total = scores[0]!
		if (submitKeyword === 'signUp') total += 2
		if (submitKeyword === 'login' || submitKeyword === 'next' || submitKeyword === 'ambiguous') total -= 2
		if (total > 0) newPasswords = passwords
		else currentPassword = firstPassword
	} else if (passwords.length > 1) {
		// Shape: three or more hold a current password, the most current-leaning; two hold one only on evidence.
		const min = Math.min(...scores)
		if (passwords.length > 2 || min < 0) currentPassword = passwords[scores.indexOf(min)] ?? null
		newPasswords = passwords.filter((field) => field !== currentPassword)
	}

	const phone = currentPassword !== null || loginKeyword
	let username: HTMLInputElement | null
	if (firstPassword) {
		// Without a form, a field under its own subscribe or sign-up button, such as a header newsletter, is not this password's username.
		const elsewhere = form ? [] : buttons.filter((button) => ['subscribe', 'signUp'].includes(keywordOf(buttonText(button)) ?? ''))
		const ownsPassword = (field: HTMLInputElement) => {
			for (let at = parentOf(field); at && at !== anchor; at = parentOf(at)) {
				const holder = at
				if (elsewhere.some((button) => contains(holder, button))) return contains(holder, firstPassword)
			}
			return true
		}
		const before = fields.filter((field) => isCandidate(field, phone) && follows(field, firstPassword) && score(field) >= 0)
		username = before.filter(ownsPassword).at(-1) ?? null
	} else {
		username = fields.find((field) => isCandidate(field, phone) && score(field) > 0) ?? null
	}

	let kind: GroupKind = 'other'
	if (currentPassword) kind = newPasswords.length ? 'passwordChange' : 'login'
	else if (newPasswords.length) kind = username ? 'signUp' : newPasswords.length > 1 ? 'passwordChange' : 'other'
	else if (username && !keywords.has('signUp') && !keywords.has('subscribe') && !keywords.has('ambiguous')) {
		const token = fact(username).token
		const lone = form ? fields.filter(isText).length === 1 : token === 'username' || token === 'email'
		if (loginKeyword || lone) kind = 'login'
	}

	const roles = new Map<HTMLInputElement, FieldRole>()
	for (const field of fields) {
		if (fact(field).otp) roles.set(field, 'oneTimeCode')
		else if (field === currentPassword) roles.set(field, 'currentPassword')
		else if (newPasswords.includes(field)) roles.set(field, 'newPassword')
		else if (field === username && kind !== 'other') roles.set(field, 'username')
		else roles.set(field, 'other')
	}
	return { kind, anchor, fields, username: kind === 'other' ? null : username, currentPassword, newPasswords, submit, roles }
}

/** Groups of `root` and the open shadow roots below it, as one composed tree. */
function groupsIn(root: Root): FieldGroup[] {
	const order = new Map<Element, number>()
	const fields: HTMLInputElement[] = []
	const buttons: HTMLElement[] = []
	// Rendered order: a host's light children count where their slot is, not after its shadow root.
	const visit = (elements: Iterable<Element>) => {
		for (const el of elements) {
			order.set(el, order.size)
			if (el.localName === 'input' && isUsable(el as HTMLInputElement)) fields.push(el as HTMLInputElement)
			else if (el.matches(BUTTONS) && keywordOf(buttonText(el)) && isVisible(el)) buttons.push(el as HTMLElement)
			if (el.localName === 'slot') {
				// Assigned text, whitespace too, replaces the fallback.
				const slot = el as HTMLSlotElement
				visit(slot.assignedNodes({ flatten: true }).length ? slot.assignedElements({ flatten: true }) : el.children)
			} else visit((el.shadowRoot ?? el).children)
		}
	}
	visit(root.children)
	const follows: Follows = (a, b) => order.get(b)! > order.get(a)!
	const groups: FieldGroup[] = []

	const forms = new Map<HTMLFormElement, HTMLInputElement[]>()
	const formless: HTMLInputElement[] = []
	for (const field of fields) {
		const form = formOf(field)
		if (form) forms.set(form, [...(forms.get(form) ?? []), field])
		else formless.push(field)
	}
	for (const [form, members] of forms) {
		groups.push(buildGroup(follows, form, members, buttons.filter((button) => formOf(button) === form)))
	}

	const loose = buttons.filter((button) => !formOf(button))
	const holders = new Set<Node>()
	for (const button of loose) {
		if (keywordOf(buttonText(button)) === 'subscribe') continue
		for (let at = parentOf(button); at; at = parentOf(at)) holders.add(at)
	}
	const anchorOf = (field: HTMLInputElement): Element | Root => {
		for (let at = parentOf(field); at && at !== root; at = parentOf(at)) if (holders.has(at)) return at as Element | ShadowRoot
		return root
	}
	const passwordAnchors = new Set(formless.filter((field) => field.type === 'password').map(anchorOf))
	const byAnchor = new Map<Element | Root, HTMLInputElement[]>()
	for (const field of formless) {
		const containing = [...passwordAnchors].filter((anchor) => contains(anchor, field))
		const anchor = containing.length ? containing.reduce((deepest, a) => (contains(deepest, a) ? a : deepest)) : anchorOf(field)
		byAnchor.set(anchor, [...(byAnchor.get(anchor) ?? []), field])
	}
	for (const [anchor, members] of byAnchor) {
		groups.push(buildGroup(follows, anchor, members, loose.filter((button) => contains(anchor, button))))
	}
	return groups
}

/** Every group in `root`, open shadow roots included. */
export function findGroups(root: Root = document): FieldGroup[] {
	return groupsIn(root)
}

export function findLoginFields(root: Root = document): FieldGroup[] {
	return findGroups(root).filter((group) => group.kind === 'login')
}

/** The group holding `field`, or null for a hidden, disabled, read-only or non-text field. */
export function groupOf(field: Element): FieldGroup | null {
	let root = field.getRootNode()
	while (isShadowRoot(root)) root = root.host.getRootNode()
	if (root.nodeType !== Node.DOCUMENT_NODE) return null
	return groupsIn(root as Document).find((group) => group.fields.includes(field as HTMLInputElement)) ?? null
}

export function classifyField(field: Element): FieldRole {
	return groupOf(field)?.roles.get(field as HTMLInputElement) ?? 'other'
}

/** Whether the inline list may open on `field` (spec `inline-list`, Which fields open the list). */
export function isQualifying(field: Element): boolean {
	const group = groupOf(field)
	return group?.kind === 'login' && (field === group.username || field === group.currentPassword)
}

/** The native setter, so a framework's value tracker sees a change, then the events a typing user sends. */
export function fillField(input: HTMLInputElement, value: string): void {
	Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input, value)
	for (const type of ['keydown', 'keypress', 'keyup']) {
		input.dispatchEvent(new KeyboardEvent(type, { bubbles: true, cancelable: true }))
	}
	input.dispatchEvent(new Event('input', { bubbles: true }))
	input.dispatchEvent(new Event('change', { bubbles: true }))
}
