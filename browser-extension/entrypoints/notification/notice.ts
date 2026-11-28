import { i18n } from '#i18n'
import { PAGE_NOTICE_CODES, type NoticeToBackground, type PageNoticeCode } from '@/src/messages'
import { applyTheme, followMirror, mirroredTheme } from '../popup/theme'
import { element, logo, stroke, svg } from './bar'

/** A status region filled at once after it appears is not announced. */
const ANNOUNCE_MS = 250

const TEXTS: Record<PageNoticeCode, () => string> = {
	no_login_form: () => i18n.t('pageNotice.noLoginForm'),
	no_card_form: () => i18n.t('pageNotice.noCardForm'),
	no_identity_form: () => i18n.t('pageNotice.noIdentityForm'),
}

function close(): void {
	const message: NoticeToBackground = { kind: 'notice.close' }
	void browser.runtime.sendMessage(message).catch(() => {})
}

/** The notice mode of the bar page (ext-context-menu design.md): a code from its URL, worded here. Returns a teardown. */
export function startNotice(code: string): () => void {
	const bar = document.getElementById('bar')!
	const status = document.querySelector('[role="status"]')!
	document.documentElement.lang = i18n.t('language')
	document.title = i18n.t('pageNotice.label')
	bar.setAttribute('aria-label', i18n.t('pageNotice.label'))
	applyTheme(mirroredTheme())
	if (!PAGE_NOTICE_CODES.includes(code as PageNoticeCode)) return () => {}
	const text = TEXTS[code as PageNoticeCode]()
	const button = element('button', { 'type': 'button', 'class': 'icon-btn bar__close', 'aria-label': i18n.t('notificationBar.close') },
		svg({ width: '18', height: '18', viewBox: '0 0 24 24', ...stroke }, [['path', { d: 'M18 6 6 18M6 6l12 12' }]]))
	button.addEventListener('click', close)
	bar.className = 'bar bar--notice bar--page-notice'
	// Read out by the status region, so not twice.
	bar.replaceChildren(logo(), element('p', { 'class': 'bar__text', 'aria-hidden': 'true' }, text), button)
	const timer = setTimeout(() => {
		status.textContent = text
	}, ANNOUNCE_MS)
	const onKeydown = (event: KeyboardEvent) => {
		if (event.key === 'Escape') close()
	}
	document.addEventListener('keydown', onKeydown)
	window.addEventListener('storage', followMirror)
	return () => {
		clearTimeout(timer)
		document.removeEventListener('keydown', onKeydown)
		window.removeEventListener('storage', followMirror)
	}
}
