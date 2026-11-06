// Field detection and classification, spec `autofill` § Field detection and `card-identity-fill`; precedence in design.md.
import { isFormRole, type CardRole, type FormKind, type FormRole, type IdentityRole, type InlineMode } from '@/src/messages'
import { isOwnHost } from './frame-host'
import { composedAncestors, hits, none } from './visibility'

export type FieldRole = 'username' | 'currentPassword' | 'newPassword' | 'oneTimeCode' | 'other' | FormRole
export type GroupKind = 'login' | 'passwordChange' | 'signUp' | 'other'
/** `<select>` and `<textarea>` only ever take a card or identity role. */
export type FormControl = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
type Root = Document | ShadowRoot
type Keyword = 'login' | 'next' | 'signUp' | 'subscribe' | 'ambiguous'

export interface FieldGroup {
	kind: GroupKind
	/** The `<form>`, or for formless fields the element or root they are grouped on. */
	anchor: Element | Root
	/** Usable inputs in DOM order, open shadow roots included. */
	fields: HTMLInputElement[]
	/** `fields` and the usable `<select>` and `<textarea>` elements, in DOM order. */
	controls: FormControl[]
	username: HTMLInputElement | null
	currentPassword: HTMLInputElement | null
	newPasswords: HTMLInputElement[]
	submit: HTMLElement | null
	roles: Map<FormControl, FieldRole>
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
// A recovery or backup code is typed once, never generated (spec `totp-autofill`, Code fields).
const RECOVERY_TEXT = /recovery|back ?up|herstel/
const NEW_TEXT = /new|nieuw|confirm|bevestig|repeat|herhaal|again|retype/
const CURRENT_TEXT = /current|old|huidig|oud|existing/
const OTHER_TOKENS =
	/^(name|honorific-.+|given-name|additional-name|family-name|nickname|organization(-title)?|street-address|address-.+|country(-name)?|postal-code|cc-.+|transaction-.+|language|bday.*|sex|url|photo|tel.*|impp)$/

const normalize = (text: string) =>
	text.replace(/([a-z])([A-Z])/g, '$1 $2').toLowerCase().replace(/[\s_\-.:]+/g, ' ').trim()

const keywordOf = (text: string): Keyword | null => KEYWORDS.find(([, pattern]) => pattern.test(text))?.[0] ?? null

/** The recognised WHATWG field name; `off`, `false` and unknown tokens give null. */
function tokenOf(input: Element): string | null {
	for (const token of (input.getAttribute('autocomplete') ?? '').toLowerCase().split(/\s+/)) {
		if (LOGIN_TOKENS.has(token)) return token
		if (OTHER_TOKENS.test(token)) return token
	}
	return null
}

const LOGIN_TOKENS = new Set(['username', 'email', 'current-password', 'new-password', 'one-time-code'])

/** The placeholder and every label of `control`, as written. */
export function labelTexts(control: FormControl): string[] {
	const root = control.getRootNode() as Root
	const labelledBy = (control.getAttribute('aria-labelledby') ?? '').split(/\s+/).filter(Boolean)
	return [
		control.getAttribute('placeholder') ?? '',
		control.getAttribute('aria-label') ?? '',
		...[...(control.labels ?? [])].map((label) => label.textContent ?? ''),
		...labelledBy.map((id) => root.getElementById(id)?.textContent ?? ''),
	]
}

const fieldText = (control: FormControl) => normalize([control.name, control.id, ...labelTexts(control)].join(' '))

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

// Card and identity roles take the stricter test below against the usual ways to hide a field; one drawn in view but invisible to the user still passes.
const MIN_SIDE = 10
const MIN_OPACITY = 0.1

/** `aria-hidden` or `inert` on the field or an ancestor, or faded out by their opacity together. */
function hiddenByAncestors(control: FormControl): boolean {
	let opacity = 1
	for (const element of composedAncestors(control)) {
		if (element.getAttribute('aria-hidden') === 'true' || element.hasAttribute('inert')) return true
		opacity *= Number(getComputedStyle(element).opacity || 1)
	}
	return opacity < MIN_OPACITY
}

function clips(style: CSSStyleDeclaration): boolean {
	const clip = style.getPropertyValue('clip')
	return !none(style.getPropertyValue('clip-path')) || (clip !== '' && clip !== 'auto')
}

/** A `clip-path` or `clip` of its own; an ancestor's shows in the hit test in the viewport, and in `clippedAway` out of it. */
const clipped = (control: FormControl) => clips(getComputedStyle(control))

type Range = [number, number]
type Axis = 'x' | 'y'

const AXES = {
	x: { overflow: 'overflowX', start: 'left', end: 'right', scrolled: 'scrollLeft', extent: 'scrollWidth', inner: 'innerWidth', page: 'scrollX' },
	y: { overflow: 'overflowY', start: 'top', end: 'bottom', scrolled: 'scrollTop', extent: 'scrollHeight', inner: 'innerHeight', page: 'scrollY' },
} as const

const NOTHING: Range = [0, 0]

const showsAll = (overflow: string) => !overflow || overflow === 'visible'
// happy-dom leaves the longhands of an `overflow` shorthand empty; browsers always set them.
const overflowOf = (style: CSSStyleDeclaration, axis: Axis) => style[AXES[axis].overflow] || style.overflow
const scrolls = (overflow: string) => /auto|scroll/.test(overflow)

/** Whose overflow the viewport takes: the root's, or the body's when the root passes it on. */
function viewportOwner(): Element {
	const root = document.documentElement
	const style = getComputedStyle(root)
	return (showsAll(overflowOf(style, 'x')) && showsAll(overflowOf(style, 'y')) && document.body) || root
}

/** The ancestors that may clip `control`; the root, and the body when it gives the viewport its overflow, belong to the viewport. */
function clippingAncestors(control: FormControl): Element[] {
	const root = document.documentElement
	const owner = viewportOwner()
	return [...composedAncestors(control)].filter((element) => element !== control && element !== root && element !== owner)
}

const overlap = ([from, to]: Range, [shownFrom, shownTo]: Range): Range => [Math.max(from, shownFrom), Math.min(to, shownTo)]
const length = ([from, to]: Range) => to - from

/**
 * Where `range` can show along `axis` past `element`: within its box for `hidden` or `clip`; for a scroller, its whole box
 * when its scroll range holds `MIN_SIDE` of it, since scrolling brings that part into the box.
 */
function through(range: Range, element: Element, style: CSSStyleDeclaration, axis: Axis): Range {
	const { start, end, scrolled, extent } = AXES[axis]
	const value = overflowOf(style, axis)
	if (showsAll(value)) return range
	const box = element.getBoundingClientRect()
	if (!scrolls(value)) return overlap(range, [box[start], box[end]])
	const from = box[start] - element[scrolled]
	return length(overlap(range, [from, from + element[extent]])) < MIN_SIDE ? NOTHING : [box[start], box[end]]
}

/** Fixed itself or by an ancestor, so scrolling the page never moves it; also where a transform makes `fixed` scroll after all. */
const pinned = (control: FormControl) => [...composedAncestors(control)].some((element) => getComputedStyle(element).position === 'fixed')

/** What the viewport shows along `axis`, or can scroll to there: the page's whole extent from where it starts. */
function viewportRange(axis: Axis, scrollable: boolean): Range {
	const { inner, page, extent } = AXES[axis]
	if (!scrollable) return [0, window[inner]]
	const scroller = document.scrollingElement ?? document.documentElement
	return [-window[page], Math.max(scroller[extent], window[inner]) - window[page]]
}

/** The viewport's reach for `control`: the page scrolls on an axis its overflow is not `hidden` or `clip` on, and moves only what is not pinned. */
function viewportReach(control: FormControl): Record<Axis, Range> {
	const style = getComputedStyle(viewportOwner())
	const moves = !pinned(control)
	const reach = (axis: Axis) => viewportRange(axis, moves && !/hidden|clip/.test(overflowOf(style, axis)))
	return { x: reach('x'), y: reach('y') }
}

/** Out of the viewport: clipped by an ancestor, or showing less than `MIN_SIDE` where its ancestors and the viewport can bring it. */
function clippedAway(control: FormControl, rect: DOMRect): boolean {
	let x: Range = [rect.left, rect.right]
	let y: Range = [rect.top, rect.bottom]
	for (const element of clippingAncestors(control)) {
		const style = getComputedStyle(element)
		if (clips(style)) return true
		x = through(x, element, style, 'x')
		y = through(y, element, style, 'y')
	}
	const reach = viewportReach(control)
	return Math.min(length(overlap(x, reach.x)), length(overlap(y, reach.y))) < MIN_SIDE
}

/** A point not pushed off the page's top or left, where no scrolling reaches it. */
const inPage = (x: number, y: number) => x + window.scrollX >= 0 && y + window.scrollY >= 0

const inViewport = (x: number, y: number) => x >= 0 && y >= 0 && x < window.innerWidth && y < window.innerHeight

/** Whether `control` is what the user sees at the point, looking through our own list and bar and through its own labels, which may float over it. */
function onTop(control: FormControl, x: number, y: number): boolean {
	const labels = [...(control.labels ?? [])]
	return hits(control, x, y, (element) => isOwnHost(element) || (element !== control && labels.some((label) => label.contains(element))))
}

/** Whether the page shows `scroller`'s own content at the point, looking through our own list and bar. */
function showsInside(scroller: Element, x: number, y: number): boolean {
	const root = scroller.getRootNode() as Document | ShadowRoot
	const top = root.elementsFromPoint?.(x, y).find((found) => !isOwnHost(found))
	return top !== undefined && scroller.contains(top)
}

const outside = (box: DOMRect, x: number, y: number) => x < box.left || x >= box.right || y < box.top || y >= box.bottom
const nearest = (value: number, from: number, to: number) => Math.min(Math.max(value, from), to - 1)

/** Missed by the hit test only because a scroller has it scrolled out of its box, which the page shows uncovered nearest to it. */
function scrolledAway(control: FormControl, x: number, y: number): boolean {
	const scroller = clippingAncestors(control).find((element) => {
		const style = getComputedStyle(element)
		return (scrolls(overflowOf(style, 'x')) || scrolls(overflowOf(style, 'y'))) && outside(element.getBoundingClientRect(), x, y)
	})
	if (!scroller) return false
	const box = scroller.getBoundingClientRect()
	return showsInside(scroller, nearest(x, box.left, box.right), nearest(y, box.top, box.bottom))
}

/** A field the user can see, or can scroll to: hit testing works only on what is in view, so a field scrolled out of it is judged by the rest. */
function isSeen(control: FormControl): boolean {
	if (hiddenByAncestors(control) || clipped(control)) return false
	const rect = control.getBoundingClientRect()
	const x = rect.left + rect.width / 2
	const y = rect.top + rect.height / 2
	if (Math.min(rect.width, rect.height) < MIN_SIDE || !inPage(x, y)) return false
	if (!inViewport(x, y)) return !clippedAway(control, rect)
	return onTop(control, x, y) || (scrolledAway(control, x, y) && !clippedAway(control, rect))
}

const isInput = (control: FormControl): control is HTMLInputElement => control.localName === 'input'

const hasUsableType = (control: FormControl) =>
	isInput(control) ? FIELD_TYPES.has(control.type) : control.localName === 'textarea' || !(control as HTMLSelectElement).multiple

const isUsable = (control: FormControl) =>
	hasUsableType(control) && !control.disabled && !(control as HTMLInputElement).readOnly && !control.closest('fieldset[disabled]') && isVisible(control)

const isControl = (el: Element): el is FormControl => ['input', 'select', 'textarea'].includes(el.localName)

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

function factsOf(input: FormControl): Facts {
	const text = fieldText(input)
	const token = tokenOf(input)
	// The token alone never decides: it needs a second signal.
	let otp = token === 'one-time-code' ? 2 : 0
	if (OTP_TEXT.test(text) && !/post|zip/.test(text)) otp += 2
	if (input.getAttribute('inputmode') === 'numeric' || input.type === 'number') otp += 1
	const max = Number(input.getAttribute('maxlength'))
	if (max >= 4 && max <= 8) otp += 1
	return { text, token, otp: otp >= 3 && !RECOVERY_TEXT.test(text) }
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
function buildGroup(follows: Follows, anchor: Element | Root, controls: FormControl[], buttons: HTMLElement[]): FieldGroup {
	const fields = controls.filter(isInput)
	const facts = new Map(controls.map((control) => [control, factsOf(control)]))
	const fact = (field: FormControl) => facts.get(field)!
	// The card guard: a card field never takes a login role, though it still counts toward the group's shape.
	const cards = cardRolesOf(controls, fact)
	const score = (field: HTMLInputElement) => usernameScore(fact(field), field.type === 'tel')
	const isText = (field: HTMLInputElement) => (field.type === 'text' || field.type === 'email') && !fact(field).otp
	// A phone number is a username only with evidence, and only in a login.
	const isCandidate = (field: HTMLInputElement, phone: boolean) =>
		!cards.has(field) && (isText(field) || (phone && field.type === 'tel' && !fact(field).otp && score(field) > 0))
	const passwords = fields.filter((field) => field.type === 'password' && !fact(field).otp && !cards.has(field))
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

	// A group whose only password fields are card fields is a payment form, never a username-only step.
	const cardPasswordsOnly = fields.some((field) => field.type === 'password' && cards.has(field))
	let kind: GroupKind = 'other'
	if (currentPassword) kind = newPasswords.length ? 'passwordChange' : 'login'
	else if (newPasswords.length) kind = username ? 'signUp' : newPasswords.length > 1 ? 'passwordChange' : 'other'
	else if (username && !cardPasswordsOnly && !keywords.has('signUp') && !keywords.has('subscribe') && !keywords.has('ambiguous')) {
		const token = fact(username).token
		const lone = form ? fields.filter(isText).length === 1 : token === 'username' || token === 'email'
		if (loginKeyword || lone) kind = 'login'
	}

	const loginRole = (field: FormControl): FieldRole => {
		if (!isInput(field)) return 'other'
		if (fact(field).otp) return 'oneTimeCode'
		if (field === currentPassword) return 'currentPassword'
		if (newPasswords.includes(field)) return 'newPassword'
		return field === username && kind !== 'other' ? 'username' : 'other'
	}
	const roles = new Map<FormControl, FieldRole>(controls.map((control) => [control, cards.get(control) ?? loginRole(control)]))
	const group: FieldGroup = { kind, anchor, fields, controls, username: kind === 'other' ? null : username, currentPassword, newPasswords, submit, roles }
	addIdentityRoles(group, fact)
	return group
}

// Card and identity roles, spec `card-identity-fill` § Card field roles and § Identity field roles.

const CARD_TOKENS = new Map<string, CardRole>([
	['cc-name', 'cardholder'], ['cc-number', 'number'], ['cc-exp', 'expiry'], ['cc-exp-month', 'expMonth'],
	['cc-exp-year', 'expYear'], ['cc-csc', 'securityCode'], ['cc-type', 'brand'],
])

const CARD_TEXT: Array<[CardRole, RegExp]> = [
	['securityCode', /\b(cvc2?|cvv2?|cid|csc)\b|security code|card code|beveiligingscode|controlegetal/],
	['number', /card ?(number|no\b)|\bcc ?num|kaartnummer|cardnummer/],
	['cardholder', /name on (the )?card|card ?holder|naam op (de )?kaart|kaarthouder/],
	['brand', /card ?(type|brand)|kaarttype|kaartsoort/],
]

const EXPIRY_TEXT = /expir|\bexp\b|valid (thru|through)|vervaldatum|geldig tot|\bmm? ?\/? ?(yy|yyyy|jj|jjjj)\b/
const MONTH_TEXT = /month|maand|\bmm\b/
const YEAR_TEXT = /year|jaar|\b(yy|yyyy|jj|jjjj)\b/
// A birth or start date has a month and year too, but never a card's expiry.
const DATE_TEXT = /\b(dd|day|dag|dob)\b|birth|geboorte|start|valid from|geldig (van|vanaf)\b/
const DAY_TEXT = /\b(dd|day|dag)\b/
const SELECT_CARD_ROLES = new Set<CardRole>(['expMonth', 'expYear', 'brand'])

/** `strong`: evidence of a card on its own; a bare "Month" or "Year" counts only beside such a field. `token`: from `cc-*`. */
interface CardHit {
	role: CardRole
	strong: boolean
	token: boolean
}

function expiryHit(text: string): CardHit | null {
	if (DATE_TEXT.test(text)) return null
	const expiry = EXPIRY_TEXT.test(text)
	const month = MONTH_TEXT.test(text)
	const year = YEAR_TEXT.test(text)
	if (month !== year) return { role: month ? 'expMonth' : 'expYear', strong: expiry, token: false }
	if (expiry || month) return { role: 'expiry', strong: expiry, token: false }
	return null
}

function cardHitOf(control: FormControl, { text, token }: Facts): CardHit | null {
	const byToken = token ? CARD_TOKENS.get(token) : undefined
	if (byToken) return { role: byToken, strong: true, token: true }
	// Any other token already names the field: a one-time code labelled "Security code" is no CVC.
	if (token) return null
	const named = CARD_TEXT.find(([, pattern]) => pattern.test(text))?.[0]
	const hit = named ? { role: named, strong: true, token: false } : expiryHit(text)
	if (!hit || control.localName === 'textarea') return null
	return isInput(control) || SELECT_CARD_ROLES.has(hit.role) ? hit : null
}

type FactOf = (control: FormControl) => Facts

/** Card wording counts beside a `cc-*` token, a second card role or a split number; alone only as "Name on card" or a CVC, so "Customer ID" or a lone text-typed "Security code" is no card field. */
function cardHitsOf(controls: FormControl[], fact: FactOf): Map<FormControl, CardHit> {
	const hits = new Map<FormControl, CardHit>()
	for (const control of controls) {
		const hit = cardHitOf(control, fact(control))
		if (hit) hits.set(control, hit)
	}
	addSecurityCode(controls, hits, fact)
	return wordingCounts(controls, hits, fact) ? hits : new Map([...hits].filter(([, hit]) => hit.token))
}

const CVC_TEXT = /\b(cvc2?|cvv2?|csc)\b/

/** A one-time code has six to eight digits, so a password-typed security code of three or four is a saved card's CVC. */
const isCvc = (control: FormControl, { text, otp }: Facts) =>
	CVC_TEXT.test(text) || (control.type === 'password' && !otp && [3, 4].includes((control as HTMLInputElement).maxLength))

const standsAlone = (control: FormControl, hit: CardHit, facts: Facts) => hit.role === 'cardholder' || (hit.role === 'securityCode' && isCvc(control, facts))

function wordingCounts(controls: FormControl[], hits: Map<FormControl, CardHit>, fact: FactOf): boolean {
	const found = [...hits]
	// Beside a login password only strong wording counts, so "Cardholder ID" or a "Year" select keeps a login's username.
	const loginPassword = controls.some((control) => control.type === 'password' && !hits.has(control))
	if (found.some(([, hit]) => hit.token) || new Set(found.filter(([, hit]) => hit.strong || !loginPassword).map(([, hit]) => hit.role)).size > 1) return true
	if (loginPassword) return false
	if (found.some(([control, hit]) => standsAlone(control, hit, fact(control)))) return true
	const roles = new Map(found.map(([control, hit]) => [control, hit.role]))
	return controls.some((control, at) => roles.get(control) === 'number' && splitRun(controls.slice(at), roles) !== null)
}

const PASSWORD_TEXT = /pass|wachtwoord|\bpin\b|\bpwd?\b/
const EXPIRY_ROLES = new Set<CardRole>(['expiry', 'expMonth', 'expYear'])

/** Beside a card number and a named expiry, the one password-typed field after the number without a name of its own, such as "3 digits on the back", is the security code. */
function addSecurityCode(controls: FormControl[], hits: Map<FormControl, CardHit>, fact: FactOf): void {
	const found = [...hits.values()]
	if (!found.some((hit) => hit.role === 'number') || found.some((hit) => hit.role === 'securityCode') || !found.some((hit) => hit.strong && EXPIRY_ROLES.has(hit.role))) return
	const first = controls.findIndex((control) => hits.get(control)?.role === 'number')
	const unnamed = controls.slice(first).filter((control) => control.type === 'password' && !hits.has(control) && !fact(control).token && !PASSWORD_TEXT.test(fact(control).text))
	if (unnamed.length === 1) hits.set(unnamed[0]!, { role: 'securityCode', strong: true, token: false })
}

// A day, month and year in a row, in the orders dates are written in; an expiry elsewhere in the form stays one.
const DATE_ORDERS = ['day expMonth expYear', 'expMonth day expYear', 'expYear expMonth day']

/** The bare month and year fields that sit in a date with a day field. */
function datedControls(controls: FormControl[], hits: Map<FormControl, CardHit>, fact: FactOf): Set<FormControl> {
	const parts = controls.map((control) => (DAY_TEXT.test(fact(control).text) ? 'day' : hits.get(control)?.strong === false ? hits.get(control)!.role : ''))
	const dated = new Set<FormControl>()
	for (let at = 0; at + 3 <= controls.length; at++) {
		if (DATE_ORDERS.includes(parts.slice(at, at + 3).join(' '))) controls.slice(at, at + 3).forEach((control) => dated.add(control))
	}
	return dated
}

/** Strong hits, and weak ones beside a strong one unless they are part of a date. */
function countedHits(hits: Map<FormControl, CardHit>, dated: Set<FormControl>): Map<FormControl, CardRole> {
	const strong = [...hits.values()].some((hit) => hit.strong)
	const roles = new Map<FormControl, CardRole>()
	for (const [control, hit] of hits) if (hit.strong || (strong && !dated.has(control))) roles.set(control, hit.role)
	return roles
}

/** Every counted card field, so none takes a login role; those the user cannot see get `other`. */
function cardRolesOf(controls: FormControl[], fact: FactOf): Map<FormControl, CardRole | 'other'> {
	const hits = cardHitsOf(controls, fact)
	const dated = datedControls(controls, hits, fact)
	const guard = [...countedHits(hits, dated).keys()].map((control) => [control, 'other'] as const)
	const seen = countedHits(new Map([...hits].filter(([control]) => isSeen(control))), dated)
	if (seen.size) markSplitNumbers(controls, seen)
	return new Map<FormControl, CardRole | 'other'>([...guard, ...seen])
}

const SPLIT_PART_TYPES = new Set(['text', 'tel', 'number', 'password'])
const isSplitPart = (control: FormControl) =>
	isInput(control) && SPLIT_PART_TYPES.has(control.type) && control.maxLength >= 1 && control.maxLength <= 8 && isSeen(control)

/** Up to four inputs in a row from the first of `controls` whose lengths together hold a card number, or null. */
function splitRun(controls: FormControl[], roles: Map<FormControl, CardRole>): HTMLInputElement[] | null {
	const run: HTMLInputElement[] = []
	for (const control of controls.slice(0, 4)) {
		if (!isSplitPart(control) || (run.length && roles.has(control) && roles.get(control) !== 'number')) return null
		run.push(control as HTMLInputElement)
		const total = run.reduce((sum, input) => sum + input.maxLength, 0)
		if (run.length > 1 && total >= 14 && total <= 19) return run
	}
	return null
}

function markSplitNumbers(controls: FormControl[], roles: Map<FormControl, CardRole>): void {
	for (const [at, control] of controls.entries()) {
		if (roles.get(control) !== 'number' || (at > 0 && roles.get(controls[at - 1]!) === 'number')) continue
		for (const part of splitRun(controls.slice(at), roles) ?? []) roles.set(part, 'number')
	}
}

const IDENTITY_TOKENS = new Map<string, IdentityRole>([
	['name', 'fullName'], ['given-name', 'firstName'], ['family-name', 'lastName'], ['email', 'email'],
	['tel', 'phone'], ['tel-national', 'phone'], ['street-address', 'streetAddress'], ['address-line1', 'addressLine1'],
])

// Most specific first: "E-mailadres" is an email, "Address line 1" a first line.
const IDENTITY_TEXT: Array<[IdentityRole, RegExp]> = [
	['email', /\be ?mail/],
	['firstName', /first ?name|given ?name|voornaam|\bfname\b/],
	['lastName', /last ?name|surname|family ?name|achternaam|\blname\b/],
	['fullName', /full ?name|your name|\bnaam\b/],
	['phone', /phone|telefoon|mobiel|mobile|\btel\b/],
	['addressLine1', /address line ?1|\bstreet\b|straat/],
	['streetAddress', /address|adres/],
]

const NOT_IDENTITY_TEXT = /search|zoek|captcha|coupon|voucher|promo|company|bedrijf|organi[sz]ation|(line|regel) ?[23]\b|(address|adres) ?[23]\b/
const IDENTITY_TYPES = new Set(['text', 'email', 'tel', 'number'])

const roleOfType = (control: FormControl): IdentityRole | null => (control.type === 'email' ? 'email' : control.type === 'tel' ? 'phone' : null)

/** An input of up to four characters is one box of a split code or number, whatever its label says. */
const isBox = (control: FormControl) => isInput(control) && control.maxLength >= 1 && control.maxLength <= 4

function identityEvidence(control: FormControl, { text, token }: Facts): IdentityRole | null {
	if (NOT_IDENTITY_TEXT.test(text) || isBox(control)) return null
	if (token && (token === 'email' || !LOGIN_TOKENS.has(token))) return IDENTITY_TOKENS.get(token) ?? null
	return roleOfType(control) ?? IDENTITY_TEXT.find(([, pattern]) => pattern.test(text))?.[0] ?? null
}

function identityRoleOf(control: FormControl, facts: Facts): IdentityRole | null {
	const role = identityEvidence(control, facts)
	if (control.localName === 'textarea') return role === 'streetAddress' || role === 'addressLine1' ? 'streetAddress' : null
	return isInput(control) && IDENTITY_TYPES.has(control.type) ? role : null
}

/** Which identity roles `control` may take: any without a login role, only `email` on a sign-up's username, which is the person's email too. */
function identityRange(group: FieldGroup, control: FormControl): 'any' | 'email' | null {
	if (group.kind === 'signUp' && control === group.username) return 'email'
	return group.roles.get(control) === 'other' ? 'any' : null
}

/** Never in a login, and only on fields the user can see. */
function addIdentityRoles(group: FieldGroup, fact: (control: FormControl) => Facts): void {
	if (group.kind === 'login') return
	for (const control of group.controls) {
		const range = identityRange(group, control)
		const role = range && identityRoleOf(control, fact(control))
		if (role && (range === 'any' || role === 'email') && isSeen(control)) group.roles.set(control, role)
	}
}

/** The controls of `group` with a role of `kind`, in DOM order. */
export function formRolesIn(group: FieldGroup, kind: FormKind): Map<FormControl, FormRole> {
	const found = new Map<FormControl, FormRole>()
	for (const [control, role] of group.roles) if (isFormRole(kind, role)) found.set(control, role)
	return found
}

/** Groups of `root` and the open shadow roots below it, as one composed tree. */
function groupsIn(root: Root): FieldGroup[] {
	const order = new Map<Element, number>()
	const fields: FormControl[] = []
	const buttons: HTMLElement[] = []
	// Rendered order: a host's light children count where their slot is, not after its shadow root.
	const visit = (elements: Iterable<Element>) => {
		for (const el of elements) {
			order.set(el, order.size)
			if (isControl(el) && isUsable(el)) fields.push(el)
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

	const forms = new Map<HTMLFormElement, FormControl[]>()
	const formless: FormControl[] = []
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
	const anchorOf = (field: FormControl): Element | Root => {
		for (let at = parentOf(field); at && at !== root; at = parentOf(at)) if (holders.has(at)) return at as Element | ShadowRoot
		return root
	}
	const passwordAnchors = new Set(formless.filter((field) => field.type === 'password').map(anchorOf))
	const byAnchor = new Map<Element | Root, FormControl[]>()
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

/** The group holding `field`, or null for a hidden, disabled or read-only field, or one Keepiq never fills. */
export function groupOf(field: Element): FieldGroup | null {
	let root = field.getRootNode()
	while (isShadowRoot(root)) root = root.host.getRootNode()
	if (root.nodeType !== Node.DOCUMENT_NODE) return null
	return groupsIn(root as Document).find((group) => group.controls.includes(field as FormControl)) ?? null
}

export function classifyField(field: Element): FieldRole {
	return groupOf(field)?.roles.get(field as FormControl) ?? 'other'
}

const SPLIT_TYPES = new Set(['text', 'tel', 'number'])

/** 1: a one-character input, 2: one of two to four characters, 0: neither. */
function splitKind(input: HTMLInputElement): 0 | 1 | 2 {
	if (!SPLIT_TYPES.has(input.type) || RECOVERY_TEXT.test(fieldText(input))) return 0
	const max = input.maxLength
	return max === 1 ? 1 : max >= 2 && max <= 4 ? 2 : 0
}

/** The code group holding `field` in `group`: its run of short inputs, else the field alone when it is a one-time code. */
function codeGroupIn(group: FieldGroup, field: HTMLInputElement): HTMLInputElement[] | null {
	const kind = splitKind(field)
	const at = group.fields.indexOf(field)
	if (kind !== 0 && at >= 0) {
		let start = at
		let end = at
		while (start > 0 && splitKind(group.fields[start - 1]!) === kind) start--
		while (end < group.fields.length - 1 && splitKind(group.fields[end + 1]!) === kind) end++
		const run = group.fields.slice(start, end + 1)
		const total = run.reduce((sum, input) => sum + input.maxLength, 0)
		// A run of longer inputs needs a one-time-code member, so a birth date in three inputs is no code.
		if (kind === 1 ? run.length >= 4 && run.length <= 8
			: run.length >= 2 && run.length <= 4 && total >= 4 && total <= 8 && run.some((input) => group.roles.get(input) === 'oneTimeCode')) return run
	}
	return group.roles.get(field) === 'oneTimeCode' ? [field] : null
}

/** The code group `field` belongs to (spec `totp-autofill`, Code fields), or null. */
export function codeGroup(field: Element): HTMLInputElement[] | null {
	const group = groupOf(field)
	return group ? codeGroupIn(group, field as HTMLInputElement) : null
}

/** Every code group in `root`, in document order; `within` keeps those of one field group. */
export function findCodeGroups(root: Root = document, within?: FieldGroup): HTMLInputElement[][] {
	const found: HTMLInputElement[][] = []
	for (const group of within ? [within] : findGroups(root)) {
		for (const field of group.fields) {
			if (found.some((run) => run.includes(field))) continue
			const run = codeGroupIn(group, field)
			if (run) found.push(run)
		}
	}
	return found
}

/** An `autocomplete` with the `webauthn` token: such a field may open the list while a conditional passkey request is pending. */
export const isWebauthnField = (field: Element): boolean =>
	(field.getAttribute('autocomplete') ?? '').toLowerCase().split(/\s+/).includes('webauthn')

/**
 * Whether the inline list may open on `field`, and in which mode (spec `inline-list`, Which fields open the list, and `card-identity-fill`, Card and identity inline list).
 * Never on a `<select>`, which has its own dropdown. A code box comes before an identity field, since a split code's boxes may look like phone numbers.
 */
export function isQualifying(field: Element): InlineMode | null {
	const group = groupOf(field)
	if (!group) return null
	if (group.kind === 'login' && (field === group.username || field === group.currentPassword)) return 'login'
	const role = group.roles.get(field as FormControl)
	if (isFormRole('card', role)) return role === 'brand' || field.localName === 'select' ? null : 'card'
	return codeGroupIn(group, field as HTMLInputElement) ? 'code' : identityListOf(group, field)
}

function identityListOf(group: FieldGroup, field: Element): 'identity' | null {
	const role = group.roles.get(field as FormControl)
	if (field.localName === 'select') return null
	return isFormRole('identity', role) && formRolesIn(group, 'identity').size >= 2 ? 'identity' : null
}

/** The native setter, so a framework's value tracker sees a change, then the events a typing user sends. */
export function fillField(input: HTMLInputElement | HTMLTextAreaElement, value: string): void {
	const prototype = input.localName === 'textarea' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
	Object.getOwnPropertyDescriptor(prototype, 'value')!.set!.call(input, value)
	for (const type of ['keydown', 'keypress', 'keyup']) {
		input.dispatchEvent(new KeyboardEvent(type, { bubbles: true, cancelable: true }))
	}
	dispatchChange(input)
}

function dispatchChange(control: FormControl): void {
	control.dispatchEvent(new Event('input', { bubbles: true }))
	control.dispatchEvent(new Event('change', { bubbles: true }))
}

const fold = (text: string) => text.trim().toLowerCase()

/** Selects the first enabled option whose value or text matches one of `candidates`; false when none does. */
export function fillSelect(select: HTMLSelectElement, candidates: readonly string[]): boolean {
	const wanted = new Set(candidates.map(fold).filter(Boolean))
	const option = [...select.options].find((each) => !each.disabled && (wanted.has(fold(each.value)) || wanted.has(fold(each.text))))
	if (!option) return false
	option.selected = true
	dispatchChange(select)
	return true
}
