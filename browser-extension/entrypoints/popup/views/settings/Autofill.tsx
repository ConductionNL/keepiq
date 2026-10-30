import { useEffect, useId, useState } from 'react'
import { i18n } from '#i18n'
import type { DefaultAutofill } from '@/src/accounts/settings'
import { CLEAR_CLIPBOARD_OPTIONS, URI_MATCHES, type AutofillFeature, type DefaultManagerState, type UriMatch } from '@/src/messages'
import { Button, IconButton } from '../../components/Button'
import { ErrorBanner } from '../../components/ErrorBanner'
import { Loading } from '../../components/Loading'
import { OptionCheckbox } from '../../components/OptionCheckbox'
import { Select } from '../../components/Select'
import { errorText } from '../../errors'
import { useAccountSettings } from '../../hooks/useAccountSettings'
import { useClipboard } from '../../hooks/useClipboard'
import { request } from '../../hooks/useMessage'
import { fieldAction } from '../../i18n'
import { useShell } from '../../shell-context'

const URI_MATCH_LABELS: Record<UriMatch, () => string> = {
	baseDomain: () => i18n.t('settings.uriBaseDomain'),
	host: () => i18n.t('settings.uriHost'),
	startsWith: () => i18n.t('settings.uriStartsWith'),
	exact: () => i18n.t('settings.uriExact'),
	regex: () => i18n.t('settings.uriRegex'),
	never: () => i18n.t('settings.never'),
}

function clipboardLabel(ms: number): string {
	if (ms === 0) return i18n.t('settings.never')
	return ms < 60_000 ? i18n.t('settings.clipboardSeconds', ms / 1000) : i18n.t('settings.timeoutMinutes', ms / 60_000)
}

/** Bitwarden's Settings → Autofill; an entry whose behaviour is not delivered only keeps its value. */
export function Autofill() {
	const { snapshot, failed, update } = useAccountSettings()
	if (!snapshot) return failed ? <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner> : <Loading />
	const { values } = snapshot
	const later = (feature: AutofillFeature) => (snapshot.autofillFeatures.includes(feature) ? undefined : i18n.t('settings.comingLater'))
	const pageLoadLater = later('pageLoad')
	const uriLater = later('uriMatch')

	return (
		<div className="stack">
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
			<div className="card form-card">
				<OptionCheckbox
					label={i18n.t('settings.autofillOnPageLoad')}
					checked={values.autofillOnPageLoad}
					onChange={(autofillOnPageLoad) => void update({ autofillOnPageLoad })}
					lockedBy={pageLoadLater}
					hint={values.autofillOnPageLoad ? i18n.t('settings.autofillOnPageLoadWarning') : undefined}
				/>
				<Select<DefaultAutofill>
					label={i18n.t('settings.defaultAutofill')}
					value={values.defaultAutofill}
					options={[{ value: 'onPageLoad', label: i18n.t('settings.defaultAutofillOnPageLoad') }, { value: 'never', label: i18n.t('settings.never') }]}
					onChange={(defaultAutofill) => void update({ defaultAutofill })}
					disabled={pageLoadLater !== undefined || !values.autofillOnPageLoad}
					hint={pageLoadLater}
				/>
				<Select<UriMatch>
					label={i18n.t('settings.uriMatch')}
					value={values.uriMatch}
					options={URI_MATCHES.map((value) => ({ value, label: URI_MATCH_LABELS[value]() }))}
					onChange={(uriMatch) => void update({ uriMatch })}
					disabled={uriLater !== undefined}
					hint={uriLater ?? (values.uriMatch === 'regex' ? i18n.t('settings.uriRegexNote') : undefined)}
				/>
				<OptionCheckbox label={i18n.t('settings.inlineSuggestions')} checked={values.inlineSuggestions} onChange={(inlineSuggestions) => void update({ inlineSuggestions })} lockedBy={later('inlineSuggestions')} />
				{import.meta.env.BROWSER !== 'firefox' && <DefaultPasswordManager />}
				<OptionCheckbox label={i18n.t('settings.contextMenu')} checked={values.contextMenu} onChange={(contextMenu) => void update({ contextMenu })} lockedBy={later('contextMenu')} />
			</div>
			<div className="card form-card">
				<Shortcut shortcut={snapshot.shortcut} />
				<Select<string>
					label={i18n.t('settings.clearClipboard')}
					value={String(values.clearClipboardMs)}
					options={CLEAR_CLIPBOARD_OPTIONS.map((ms) => ({ value: String(ms), label: clipboardLabel(ms) }))}
					onChange={(ms) => void update({ clearClipboardMs: Number(ms) })}
				/>
			</div>
		</div>
	)
}

function DefaultPasswordManager() {
	const [state, setState] = useState<DefaultManagerState>()
	const [busy, setBusy] = useState(false)
	const [failed, setFailed] = useState(false)

	useEffect(() => {
		let live = true
		void request({ kind: 'defaultManager.get' }).then((reply) => {
			if (!live) return
			setState(reply ?? 'off')
			setFailed(reply === undefined)
		})
		return () => {
			live = false
		}
	}, [])

	async function settle(reply: Promise<DefaultManagerState | undefined>) {
		setBusy(true)
		const next = await reply
		setBusy(false)
		setFailed(next === undefined)
		if (next) setState(next)
	}

	function change(on: boolean) {
		if (!on) {
			void settle(request({ kind: 'defaultManager.disable' }))
			return
		}
		// Nothing may be awaited first: the browser only prompts inside the click's user gesture.
		const granted = browser.permissions.request({ permissions: ['privacy'] }).catch(() => false)
		void granted.then((ok) => {
			// Joins the run `registerDefaultManager` starts on the grant, which also covers a popup Chrome closed at the prompt.
			if (ok) void settle(request({ kind: 'defaultManager.enable' }))
		})
	}

	if (!state || state === 'unsupported') return null
	const managed = i18n.t('settings.defaultPasswordManagerManaged')
	return (
		<>
			<OptionCheckbox
				label={i18n.t('settings.defaultPasswordManager')}
				checked={state === 'on' || state === 'managedOn'}
				onChange={change}
				disabled={busy}
				lockedBy={state === 'managed' ? managed : undefined}
				note={state === 'managedOn' ? managed : undefined}
				hint={i18n.t('settings.defaultPasswordManagerHint')}
			/>
			{failed && <ErrorBanner>{errorText('not_responding', '')}</ErrorBanner>}
		</>
	)
}

const ADDONS = 'about:addons'

/** Firefox refuses to open its shortcut page from an extension, so it gets the steps instead. */
function Shortcut({ shortcut }: { shortcut: string | null }) {
	const id = useId()
	const { launch } = useShell()
	const copy = useClipboard()
	const [steps, setSteps] = useState(false)

	function change() {
		// `BROWSER` rather than `FIREFOX`, which tests can only set as a string.
		if (import.meta.env.BROWSER === 'firefox') setSteps(true)
		else launch('chrome://extensions/shortcuts')
	}

	return (
		<div className="field">
			<span className="field__label" id={`${id}-label`}>{i18n.t('settings.shortcut')}</span>
			<div className="shortcut">
				<span className="shortcut__keys" id={`${id}-keys`}>{shortcut ? <kbd>{shortcut}</kbd> : i18n.t('settings.shortcutNotSet')}</span>
				<Button variant="secondary" aria-describedby={`${id}-label ${id}-keys`} onClick={change}>{i18n.t('settings.shortcutChange')}</Button>
			</div>
			{steps && (
				<div className="shortcut__steps" role="status">
					<p className="field__hint">{i18n.t('settings.shortcutFirefox')}</p>
					<IconButton icon="copy" label={fieldAction('field.copy', ADDONS)} onClick={() => void copy(ADDONS, ADDONS)} />
				</div>
			)}
		</div>
	)
}
