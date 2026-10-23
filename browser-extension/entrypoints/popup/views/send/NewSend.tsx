import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { SendPayloadType, SendRow, VaultWriteError } from '@/src/messages'
import {
	CUSTOM_HOURS, DEFAULT_EXPIRY, DEFAULT_MAX_VIEWS, EXPIRY_PRESETS, MAX_VIEWS_CAP, isValidMaxViews, presetToTtlSeconds, type ExpiryPreset,
} from '@/src/send/expiry'
import { serializeCredential } from '@/src/send/payload'
import { Button } from '../../components/Button'
import { Banner } from '../../components/ErrorBanner'
import { PageFooter } from '../../components/PageFooter'
import { Select } from '../../components/Select'
import { SubTabs, subTabId } from '../../components/SubTabs'
import { TextArea } from '../../components/TextArea'
import { TextField } from '../../components/TextField'
import { writeErrorText } from '../../errors'
import { useSends } from '../../hooks/useSends'
import { useUnsavedChangesGuard } from '../../hooks/useUnsavedChangesGuard'
import { useShell } from '../../shell-context'

export interface SendPrefill {
	username: string
	password: string
}

interface Props {
	/** From an item's Send action: a credential send with its login filled in. */
	prefill?: SendPrefill
	onCreated: (row: SendRow, link: string) => void
	onCancel: () => void
	/** While a create runs, so Shell keeps the user from leaving the form under it. */
	onBusy: (busy: boolean) => void
}

function expiryLabel(preset: ExpiryPreset): string {
	switch (preset) {
		case '1h': return i18n.t('send.expiry1h')
		case '1d': return i18n.t('send.expiry1d')
		case '2d': return i18n.t('send.expiry2d')
		case '3d': return i18n.t('send.expiry3d')
		case '7d': return i18n.t('send.expiry7d')
		case '30d': return i18n.t('send.expiry30d')
		case 'custom': return i18n.t('send.expiryCustom')
	}
}

function Section({ title, children }: { title: string; children: ReactNode }) {
	return (
		<section className="section">
			<h2 className="section__title">{title}</h2>
			<div className="card form-card">{children}</div>
		</section>
	)
}

const DEFAULT_CUSTOM_HOURS = '24'

/** A whole number from a field that may hold anything while typed. */
const whole = (text: string) => (/^\d+$/.test(text.trim()) ? Number(text.trim()) : NaN)

export function NewSend({ prefill, onCreated, onCancel, onBusy }: Props) {
	const shell = useShell()
	const { passwordProtection, load, create } = useSends()
	const formId = useId()
	const panelId = useId()
	const panel = useRef<HTMLDivElement>(null)
	// A reply after the form is gone, e.g. by a lock, must not open the link's page; the list's copy stays in `useSends`.
	const live = useRef(true)
	// Set on every mount too: StrictMode's mount, cleanup, mount would otherwise leave it false for good.
	useEffect(() => {
		live.current = true
		return () => {
			live.current = false
		}
	}, [])
	const [type, setType] = useState<SendPayloadType>(prefill ? 'credential' : 'text')
	const [text, setText] = useState('')
	const [username, setUsername] = useState(prefill?.username ?? '')
	const [password, setPassword] = useState(prefill?.password ?? '')
	const [maxViews, setMaxViews] = useState(String(DEFAULT_MAX_VIEWS))
	const [expiry, setExpiry] = useState<ExpiryPreset>(DEFAULT_EXPIRY)
	const [customHours, setCustomHours] = useState(DEFAULT_CUSTOM_HOURS)
	const [accessPassword, setAccessPassword] = useState('')
	const [busy, setBusy] = useState(false)
	// `null` is no failure; `undefined` inside is the background not answering.
	const [failure, setFailure] = useState<{ error: VaultWriteError | undefined } | null>(null)

	// For `passwordProtection`, when the form opened straight from an item.
	useEffect(load, [load])

	// The control that opened the form is gone.
	useEffect(() => {
		panel.current?.querySelector<HTMLElement>('textarea, input')?.focus()
	}, [])

	useEffect(() => {
		onBusy(busy)
		return () => onBusy(false)
	}, [busy, onBusy])

	useUnsavedChangesGuard(
		type !== (prefill ? 'credential' : 'text') || text !== '' || username !== (prefill?.username ?? '') || password !== (prefill?.password ?? '')
		|| maxViews !== String(DEFAULT_MAX_VIEWS) || expiry !== DEFAULT_EXPIRY || customHours !== DEFAULT_CUSTOM_HOURS || accessPassword !== '',
	)

	const views = whole(maxViews)
	const viewsError = isValidMaxViews(views) ? null : i18n.t('send.maxViewsRange', { max: String(MAX_VIEWS_CAP) })
	const hours = whole(customHours)
	const ttlSeconds = presetToTtlSeconds(expiry, hours)
	let hoursError: string | null = null
	if (expiry === 'custom' && ttlSeconds === null) hoursError = hours > CUSTOM_HOURS.max ? i18n.t('send.customHoursMax') : i18n.t('send.customHoursMin')
	const passwordError = accessPassword !== '' && !passwordProtection ? i18n.t('writeErrors.argon2Unavailable') : null
	// The background refuses a blank credential password too.
	const empty = type === 'text' ? text.trim() === '' : password.trim() === ''
	const blocked = empty || viewsError !== null || ttlSeconds === null || passwordError !== null

	async function submit() {
		if (blocked || busy || shell.offline || ttlSeconds === null) return
		setBusy(true)
		setFailure(null)
		const reply = await create({
			payloadType: type,
			content: type === 'text' ? text : serializeCredential(username, password),
			maxViews: views,
			ttlSeconds,
			...(accessPassword !== '' && { password: accessPassword }),
		})
		if (!live.current) return
		setBusy(false)
		if (reply?.ok) onCreated(reply.value.row, reply.value.link)
		else if (reply?.error.code !== 'locked' && reply?.error.code !== 'session_revoked') setFailure({ error: reply?.error })
	}

	return (
		<>
			<form id={formId} className="stack" noValidate onSubmit={(event) => {
				event.preventDefault()
				void submit()
			}}>
				{shell.offline && (
					<Banner tone="warning" role="status" action={<Button variant="link" onClick={shell.syncNow}>{i18n.t('vault.syncNow')}</Button>}>
						{i18n.t('send.offline')}
					</Banner>
				)}
				{failure && <Banner tone="error" role="alert">{writeErrorText(failure.error, 'send')}</Banner>}
				<Section title={i18n.t('send.contentSection')}>
					<SubTabs
						label={i18n.t('send.type')}
						tabs={[{ id: 'text', label: i18n.t('send.text') }, { id: 'credential', label: i18n.t('send.credential') }]}
						value={type}
						onChange={setType}
						panelId={panelId}
					/>
					<div className="stack stack--tight" id={panelId} ref={panel} role="tabpanel" aria-labelledby={subTabId(panelId, type)}>
						{type === 'text'
							? <TextArea label={i18n.t('send.content')} value={text} onChange={setText} rows={5} />
							: (
								<>
									<TextField label={i18n.t('detail.username')} value={username} onChange={setUsername} autoComplete="off" verbatim />
									<TextField label={i18n.t('detail.password')} type="password" value={password} onChange={setPassword} autoComplete="off" />
								</>
							)}
					</div>
				</Section>
				<Section title={i18n.t('send.optionsSection')}>
					<div className="field-pair">
						<TextField label={i18n.t('send.maxViews')} type="number" inputMode="numeric" min={1} max={MAX_VIEWS_CAP} value={maxViews} onChange={setMaxViews} error={viewsError} />
						<Select<ExpiryPreset> label={i18n.t('send.expiry')} value={expiry} options={EXPIRY_PRESETS.map((value) => ({ value, label: expiryLabel(value) }))} onChange={setExpiry} />
					</div>
					{expiry === 'custom' && (
						<TextField label={i18n.t('send.customHours')} type="number" inputMode="numeric" min={CUSTOM_HOURS.min} max={CUSTOM_HOURS.max} value={customHours} onChange={setCustomHours} error={hoursError} />
					)}
					<TextField
						label={i18n.t('send.accessPassword')}
						type="password"
						value={accessPassword}
						onChange={setAccessPassword}
						autoComplete="new-password"
						hint={passwordError ? undefined : i18n.t('send.accessPasswordHint')}
						error={passwordError}
					/>
				</Section>
				{/* Mounted throughout, so the status is announced when its text arrives. */}
				<p className={busy && accessPassword !== '' ? 'hint' : 'sr-only'} role="status">{busy && accessPassword !== '' ? i18n.t('send.protecting') : ''}</p>
			</form>
			<PageFooter>
				<Button type="submit" form={formId} busy={busy} disabled={blocked || shell.offline} title={shell.offline ? i18n.t('send.offline') : undefined}>{i18n.t('send.create')}</Button>
				<Button variant="secondary" onClick={onCancel} disabled={busy}>{i18n.t('common.cancel')}</Button>
			</PageFooter>
		</>
	)
}
