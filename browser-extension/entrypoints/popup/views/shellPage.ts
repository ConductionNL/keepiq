import { i18n } from '#i18n'
import type { PopupTab } from '@/src/messages'
import { tabLabel } from '../components/TabBar'
import type { FormMode } from './ItemForm'
import { sectionLabel, type SettingsSection } from './settings/SettingsIndex'

/** A page opened over the tabs, from the one that covers the others down. */
export type ShellPage = 'pick' | 'form' | 'detail' | 'section' | 'history' | 'send'

/** What decides which page the shell shows and what its header says. */
export interface ShellView {
	current: PopupTab
	form: { mode: FormMode } | null
	picking: boolean
	detail: boolean
	section: SettingsSection | null
	history: boolean
	sendPage: { kind: 'new' | 'created' } | null
}

/** The page open over the tabs, or null on a tab itself. */
export function openPage(view: ShellView): ShellPage | null {
	if (view.form) return view.picking ? 'pick' : 'form'
	if (view.detail) return 'detail'
	if (view.current === 'settings' && view.section !== null) return 'section'
	if (view.current === 'generator' && view.history) return 'history'
	if (view.current === 'send' && view.sendPage !== null) return 'send'
	return null
}

const TITLES: Record<ShellPage, (view: ShellView) => string> = {
	pick: () => tabLabel('generator'),
	form: (view) => (view.form?.mode === 'edit' ? i18n.t('titles.editItem') : i18n.t('titles.newItem')),
	detail: () => i18n.t('titles.viewItem'),
	section: (view) => sectionLabel(view.section!),
	history: () => i18n.t('generator.history'),
	send: (view) => (view.sendPage?.kind === 'new' ? i18n.t('titles.newSend') : i18n.t('titles.sendCreated')),
}

/** The header title: the open page's, or the tab's. */
export function pageTitle(page: ShellPage | null, view: ShellView): string {
	return page ? TITLES[page](view) : tabLabel(view.current)
}

/** What a header shows on each page and tab: Back per page, New per tab. */
export interface HeaderActions {
	backs: Record<ShellPage, () => void>
	adds: Partial<Record<PopupTab, () => void>>
}

/** The open page, its title, and the header's Back and New: Back only on a page, New only on a tab itself. */
export function headerFor(view: ShellView, actions: HeaderActions) {
	const page = openPage(view)
	return {
		page,
		title: pageTitle(page, view),
		back: page ? actions.backs[page] : undefined,
		add: page ? undefined : actions.adds[view.current],
	}
}
