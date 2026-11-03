// Content script side of a fill; it keeps nothing of the credential once the fields hold it.
import type { FillExecute, FillExecuteReply, FillOutcome } from '@/src/messages'
import { codeGroup, fillField, findCodeGroups, findLoginFields, groupOf, type FieldGroup } from './fields'

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
