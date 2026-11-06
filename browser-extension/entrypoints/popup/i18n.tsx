import type { ReactNode } from 'react'
import { i18n } from '#i18n'

/** The catalog's own language rather than the browser's, so dates and numbers match the text around them. */
export const language = (): string => i18n.t('language')

/** Stands in for an element inside a translated sentence; see `rich`. */
export const SLOT = '\u{E000}'

/** A translated sentence with `SLOT` where `node` goes, so translators see the whole sentence. */
export function rich(message: string, node: ReactNode): ReactNode {
	const [before, after = ''] = message.split(SLOT)
	return <>{before}{node}{after}</>
}

/** "Show password", "Wachtwoord tonen": the label keeps its capital only where it starts the sentence. */
export function fieldAction(key: 'field.show' | 'field.hide' | 'field.copy', label: string): string {
	const template = i18n.t(key, { field: SLOT })
	// "API key" and "BSN" keep their capitals mid-sentence too.
	const field = template.startsWith(SLOT) ? label : label.replace(/^[A-Z][a-z]/, (start) => start.toLowerCase())
	return template.replace(SLOT, field)
}
