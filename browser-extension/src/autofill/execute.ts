// Content script side of a fill; it keeps nothing of the credential once the fields hold it.
import type { FillExecute, FillExecuteReply, FillOutcome } from '@/src/messages'
import { fillField, findLoginFields, groupOf, type FieldGroup } from './fields'

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

export function executeFill(message: FillExecute, list: OpenList | null = null): FillExecuteReply {
	try {
		// The frame navigated since the background matched it.
		if (location.origin !== message.origin) return { filled: 'none' }
		if (message.anchorKey !== undefined && message.anchorKey !== list?.key) return { filled: 'none' }
		const group = message.anchorKey !== undefined ? groupOf(list!.field) : chooseGroup()
		if (group?.kind !== 'login') return { filled: 'none' }
		let user = false
		let pass = false
		if (group.username && message.username !== null) {
			fillField(group.username, message.username)
			user = true
		}
		if (group.currentPassword && message.password !== null) {
			fillField(group.currentPassword, message.password)
			pass = true
		}
		const filled: FillOutcome = user && pass ? 'both' : user ? 'username' : pass ? 'password' : 'none'
		return { filled }
	} finally {
		message.username = null
		message.password = null
	}
}
