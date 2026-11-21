import { useEffect, useId, useState } from 'react'
import { i18n } from '#i18n'
import { serverOrigins } from '@/src/accounts/normalize-server-url'
import { SITE_ORIGINS, websiteAccess, type WebsiteAccessState } from '@/src/website-access'
import { useSettings } from '../hooks/useSettings'
import { Button } from './Button'

/** The state, re-read on every permission change, and the Allow button's request. Render only on Safari. */
function useWebsiteAccess(serverUrl: string) {
	const [state, setState] = useState<WebsiteAccessState>()
	// The state a request left ungranted; the steps show while it still is.
	const [refused, setRefused] = useState<WebsiteAccessState>()

	useEffect(() => {
		let live = true
		const read = () => void websiteAccess(serverUrl).then((next) => live && setState(next), () => {})
		read()
		browser.permissions.onAdded.addListener(read)
		browser.permissions.onRemoved.addListener(read)
		return () => {
			live = false
			browser.permissions.onAdded.removeListener(read)
			browser.permissions.onRemoved.removeListener(read)
		}
	}, [serverUrl])

	function allow() {
		const asked = state
		if (asked !== 'server' && asked !== 'sites') return
		// Nothing may be awaited first: the browser only prompts inside the click's user gesture.
		void browser.permissions.request({ origins: asked === 'server' ? serverOrigins(serverUrl) : SITE_ORIGINS }).catch(() => false)
			.then(() => websiteAccess(serverUrl))
			.then((next) => {
				setState(next)
				setRefused(next === asked ? asked : undefined)
			}, () => {})
	}

	return { state, steps: state !== undefined && state === refused, allow }
}

const hostOf = (serverUrl: string) => new URL(serverUrl).host

function allowLabel(state: 'server' | 'sites', serverUrl: string): string {
	return state === 'server' ? i18n.t('websiteAccess.serverAllow', { host: hostOf(serverUrl) }) : i18n.t('websiteAccess.allow')
}

function Steps() {
	return (
		<div className="website-access__steps" role="status">
			<p className="field__hint">{i18n.t('websiteAccess.steps')}</p>
			<p className="field__hint">{i18n.t('websiteAccess.perSite')}</p>
		</div>
	)
}

/** The Vault tab's card; Not now hides the "sites" one only. */
export function WebsiteAccessCard({ serverUrl }: { serverUrl: string }) {
	const id = useId()
	const { state, steps, allow } = useWebsiteAccess(serverUrl)
	const { settings, update } = useSettings()
	const [dismissed, setDismissed] = useState(false)

	if (state === 'server') {
		return (
			<section className="card form-card website-access" aria-labelledby={id}>
				<h2 className="website-access__title" id={id}>{i18n.t('websiteAccess.serverTitle', { host: hostOf(serverUrl) })}</h2>
				<p>{i18n.t('websiteAccess.serverBody')}</p>
				<div className="website-access__actions">
					<Button onClick={allow}>{allowLabel(state, serverUrl)}</Button>
				</div>
				{steps && <Steps />}
			</section>
		)
	}
	// Hidden until the global settings are read, so a dismissed card never flashes.
	if (state !== 'sites' || !settings || settings.websiteAccessDismissed || dismissed) return null

	function dismiss() {
		setDismissed(true)
		void update({ websiteAccessDismissed: true })
	}

	return (
		<section className="card form-card website-access" aria-labelledby={id}>
			<h2 className="website-access__title" id={id}>{i18n.t('websiteAccess.title')}</h2>
			<p>{i18n.t('websiteAccess.body')}</p>
			<div className="website-access__actions">
				<Button onClick={allow}>{allowLabel(state, serverUrl)}</Button>
				<Button variant="secondary" onClick={dismiss}>{i18n.t('websiteAccess.dismiss')}</Button>
			</div>
			{steps && <Steps />}
		</section>
	)
}

const STATE_TEXT: Record<WebsiteAccessState, () => string> = {
	all: () => i18n.t('settings.websiteAccessAll'),
	sites: () => i18n.t('settings.websiteAccessSome'),
	server: () => i18n.t('settings.websiteAccessServer'),
}

/** Settings → Autofill's entry; never dismissible. */
export function WebsiteAccessEntry({ serverUrl }: { serverUrl: string }) {
	const id = useId()
	const { state, steps, allow } = useWebsiteAccess(serverUrl)
	if (!state) return null
	return (
		<div className="field">
			<span className="field__label" id={`${id}-label`}>{i18n.t('settings.websiteAccess')}</span>
			<div className="shortcut">
				<span id={`${id}-state`}>{STATE_TEXT[state]()}</span>
				{state !== 'all' && <Button variant="secondary" aria-describedby={`${id}-label ${id}-state`} onClick={allow}>{allowLabel(state, serverUrl)}</Button>}
			</div>
			{steps && <Steps />}
		</div>
	)
}
