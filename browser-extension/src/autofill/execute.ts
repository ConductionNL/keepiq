// Content script side of a fill; it keeps nothing of the credential once the fields hold it.
import { i18n } from '#i18n'
import {
	isFormKind, kindSet, type BackgroundToContent, type FillConfirmForm, type FillConfirmInsecure, type FillExecute, type FillExecuteForm, type FillExecuteFormReply,
	type FillExecuteReply, type FillOutcome, type FillProbe, type FillProbeReply, type FormKind, type FormValues,
} from '@/src/messages'
import {
	codeGroup, fillField, fillSelect, findCodeGroups, findGroups, findLoginFields, formRolesIn, groupOf, type FieldGroup, type FormControl,
} from './fields'
import { FORMATTERS, numberParts } from './format'

/** The field the open inline list belongs to, with its key; `null` while no list is open. */
export interface OpenList {
	key: string
	field: Element
}

export function deepActiveElement(): Element | null {
	let active = document.activeElement
	while (active?.shadowRoot?.activeElement) active = active.shadowRoot.activeElement
	return active
}

/** The focused field's login group, else the first with a password field, else the first. */
function chooseGroup(): FieldGroup | null {
	const groups = findLoginFields(document)
	const focused = deepActiveElement()
	return groups.find((group) => focused !== null && group.fields.includes(focused as HTMLInputElement))
		?? groups.find((group) => group.currentPassword !== null)
		?? groups[0]
		?? null
}

/** The list's own code group, else one in the list's form, else the focused one, else the first empty one. */
function chooseCodeGroup(anchor: Element | null): HTMLInputElement[] | null {
	if (anchor) {
		const own = codeGroup(anchor)
		const form = groupOf(anchor)
		return own ?? (form ? findCodeGroups(document, form).find(isEmpty) ?? null : null)
	}
	const focused = deepActiveElement()
	const groups = findCodeGroups(document)
	return groups.find((run) => run.includes(focused as HTMLInputElement)) ?? groups.find(isEmpty) ?? null
}

const isEmpty = (run: HTMLInputElement[]) => run.every((input) => input.value === '')

/** Split by the inputs' lengths when the code fits them exactly, else whole into the first. */
function fillCode(run: HTMLInputElement[], code: string): void {
	const total = run.reduce((sum, input) => sum + input.maxLength, 0)
	if (run.length === 1 || code.length !== total) return fillField(run[0]!, code)
	let at = 0
	for (const input of run) {
		fillField(input, code.slice(at, at + input.maxLength))
		at += input.maxLength
	}
}

export function executeFill(message: FillExecute, list: OpenList | null = null): FillExecuteReply {
	try {
		// The frame navigated since the background matched it.
		if (location.origin !== message.origin) return { filled: 'none', code: false }
		if (message.anchorKey !== undefined && message.anchorKey !== list?.key) return { filled: 'none', code: false }
		const anchor = message.anchorKey !== undefined ? list!.field : null
		const group = anchor ? groupOf(anchor) : chooseGroup()
		let user = false
		let pass = false
		if (group?.kind === 'login' && group.username && message.username !== null) {
			fillField(group.username, message.username)
			user = true
		}
		if (group?.kind === 'login' && group.currentPassword && message.password !== null) {
			fillField(group.currentPassword, message.password)
			pass = true
		}
		let code = false
		const run = typeof message.code === 'string' && message.code !== '' ? chooseCodeGroup(anchor) : null
		// A code group that already holds a value is the user's.
		if (run && isEmpty(run)) {
			fillCode(run, message.code!)
			code = true
		}
		const filled: FillOutcome = user && pass ? 'both' : user ? 'username' : pass ? 'password' : 'none'
		return { filled, code }
	} finally {
		message.username = null
		message.password = null
		message.code = null
	}
}

// Cards and identities, spec `card-identity-fill` § Each frame gets only its own members.

/** The list's field for an `anchorKey`, `null` without one, `undefined` when it is not the open list's. */
function anchorFor(anchorKey: string | undefined, list: OpenList | null): Element | null | undefined {
	if (anchorKey === undefined) return null
	return anchorKey === list?.key ? list.field : undefined
}

/** The list's group, else the focused one, else the first with fields of `kind`; for an identity one with two goes first, so a lone newsletter email does not win. */
function chooseFormGroup(kind: FormKind, anchor: Element | null): FieldGroup | null {
	if (anchor) return groupOf(anchor)
	const groups = findGroups(document).filter((group) => formRolesIn(group, kind).size > 0)
	const focused = deepActiveElement() as FormControl | null
	return groups.find((group) => focused !== null && group.controls.includes(focused))
		?? (kind === 'identity' ? groups.find((group) => formRolesIn(group, kind).size >= 2) : undefined)
		?? groups[0]
		?? null
}

function formGroupFor(message: FillProbe | FillExecuteForm, list: OpenList | null): FieldGroup | null {
	const anchor = anchorFor(message.anchorKey, list)
	return isFormKind(message.form) && anchor !== undefined ? chooseFormGroup(message.form, anchor) : null
}

/** The roles of the group a fill would write, and nothing else of the page; it dispatches no event. */
export function probeForm(message: FillProbe, list: OpenList | null = null): FillProbeReply {
	const group = formGroupFor(message, list)
	return { roles: group ? [...new Set(formRolesIn(group, message.form).values())] : [], origin: location.origin }
}

function writeControl(control: FormControl, candidates: readonly string[]): boolean {
	if (!candidates.length) return false
	if (control.localName === 'select') return fillSelect(control as HTMLSelectElement, candidates)
	fillField(control as HTMLInputElement | HTMLTextAreaElement, candidates[0]!)
	return true
}

function writeForm(group: FieldGroup, message: FillExecuteForm): number {
	const values: FormValues = message.values ?? {}
	const roles = formRolesIn(group, message.form)
	const numbers = [...roles].filter(([, role]) => role === 'number').map(([control]) => control as HTMLInputElement)
	const parts = numberParts(numbers, values.number) ?? []
	let filled = 0
	for (const [control, role] of roles) {
		const part = parts[numbers.indexOf(control as HTMLInputElement)]
		const candidates = role === 'number' ? (part ? [part] : []) : FORMATTERS[role](control, values)
		if (writeControl(control, candidates)) filled++
	}
	return filled
}

export function executeForm(message: FillExecuteForm, list: OpenList | null = null): FillExecuteFormReply {
	try {
		// The frame navigated since the background probed it.
		if (location.origin !== message.origin) return { filled: 0 }
		const group = formGroupFor(message, list)
		return { filled: group ? writeForm(group, message) : 0 }
	} finally {
		message.values = {}
	}
}

const hostsOf = (hosts: unknown): string[] => (Array.isArray(hosts) ? hosts.filter((host): host is string => typeof host === 'string' && host !== '') : [])

/** The context menu's questions, asked in turn; OK at each means Fill. Only the top frame asks. */
export function confirmForm(message: FillConfirmForm): boolean {
	if (window !== window.top) return false
	const host = typeof message.host === 'string' ? message.host : ''
	const hosts = hostsOf(message.frameHosts).join(', ')
	if (message.insecure === true && !window.confirm(i18n.t('pageFill.cardInsecure'))) return false
	return hosts === '' || window.confirm(i18n.t('pageFill.crossSite', { hosts, host }))
}

/** The insecure-page question of a login fill from the menu, shortcut or page load. */
function confirmInsecure(message: FillConfirmInsecure): boolean {
	const host = typeof message.itemHost === 'string' ? message.itemHost : ''
	return window.confirm(host ? i18n.t('pageFill.insecure', { host }) : i18n.t('pageFill.insecureNoHost'))
}

type FillMessage = Extract<BackgroundToContent, { kind: `fill.${string}` }>

const FILL_KINDS = kindSet<FillMessage['kind']>({
	'fill.confirmInsecure': true, 'fill.execute': true, 'fill.probe': true, 'fill.executeForm': true, 'fill.confirmForm': true,
})

export const isFillMessage = (message: BackgroundToContent | null): message is FillMessage => typeof message?.kind === 'string' && FILL_KINDS.has(message.kind)

/** The content script's answer to every fill message from the background. */
export function answerFill(message: FillMessage, list: OpenList | null): FillExecuteReply | FillProbeReply | FillExecuteFormReply | boolean {
	switch (message.kind) {
		case 'fill.confirmInsecure': return confirmInsecure(message)
		case 'fill.execute': return executeFill(message, list)
		case 'fill.probe': return probeForm(message, list)
		case 'fill.executeForm': return executeForm(message, list)
		case 'fill.confirmForm': return confirmForm(message)
	}
}
