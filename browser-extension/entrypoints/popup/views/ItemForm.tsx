import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { EditableItem, ShareSaveNote, VaultSnapshotReply, VaultWriteError, WriteResult } from '@/src/messages'
import { parseTotpSeed, type TotpAlgorithm } from '@/src/totp/totp'
import { CARD_FIELDS, emptyValues, formKind, formSections, fromItem, hasLineBreak, IDENTITY_FIELDS, isMultiline, isNewItemType, keepLineBreaks, type CardField, type FormValues, type IdentityField } from '@/src/vault/fields'
import { folderPath } from '@/src/vault/list'
import { AdditionalFieldsEditor } from '../components/AdditionalFieldsEditor'
import { AuthenticatorKeyField } from '../components/AuthenticatorKeyField'
import { Button } from '../components/Button'
import { Banner } from '../components/ErrorBanner'
import { Icon } from '../components/Icon'
import { Loading } from '../components/Loading'
import { PageFooter } from '../components/PageFooter'
import { Select } from '../components/Select'
import { TextArea } from '../components/TextArea'
import { TextField } from '../components/TextField'
import { TypeSelect } from '../components/TypeSelect'
import { fieldErrorText, writeErrorText } from '../errors'
import { useItemDraft } from '../hooks/useItemDraft'
import { request } from '../hooks/useMessage'
import { useUnsavedChangesGuard } from '../hooks/useUnsavedChangesGuard'
import { useShell } from '../shell-context'
import { FolderPicker } from './FolderPicker'

type Unlocked = Extract<VaultSnapshotReply, { state: 'unlocked' }>

export type FormMode = 'add' | 'edit' | 'clone'

interface Props {
	mode: FormMode
	/** The item to edit or clone. */
	id?: string
	snapshot: Unlocked
	/** The page the popup opened on, which a new item's Website URL starts as. */
	tabUrl?: string
	/** Awaited, so Save stays busy until the shell shows the result. `shareSave`: what an edit left to others. */
	onSaved: (id: string, shareSave?: ShareSaveNote) => Promise<void> | void
	onCancel: () => void
	/** The item was deleted elsewhere. */
	onGone: () => void
}

const isRetryable = (error: VaultWriteError | undefined) => !error || error.code === 'offline' || error.code === 'server_error' || error.code === 'write_locked'

type Partner = NonNullable<VaultWriteError['partners']>[number]

/** The patch fields share-save sends to every copy. */
const VALUE_FIELDS = ['key', 'login', 'additionalFields', 'totp'] as const

const unionOf = (earlier: Partner[], later: Partner[]) => [...earlier, ...later.filter((partner) => !earlier.some((known) => known.shareId === partner.shareId))]

function FormSection({ title, children }: { title: string; children: ReactNode }) {
	return (
		<section className="section">
			<h2 className="section__title">{title}</h2>
			<div className="card form-card">{children}</div>
		</section>
	)
}

/** The user's default type, else `login`; never one a new item may not have. */
function defaultTypeId(snapshot: Unlocked): string {
	const usable = snapshot.types.filter((type) => isNewItemType(type.name))
	return (usable.find((type) => type.name === snapshot.defaultSecretType) ?? usable.find((type) => type.name === 'login') ?? usable[0])?.id ?? ''
}

export function ItemForm(props: Props) {
	if (props.mode === 'add') return <Form {...props} initial={emptyValues(defaultTypeId(props.snapshot), props.tabUrl ?? '')} />
	return <Fetched {...props} id={props.id!} />
}

/** Edit and clone start from a fresh fetch, never the cache (ADR-002). */
function Fetched(props: Props & { id: string }) {
	const { id, mode, snapshot } = props
	const shell = useShell()
	const [state, setState] = useState<{ item?: EditableItem; failure?: { error: VaultWriteError | undefined } }>({})
	const [attempt, setAttempt] = useState(0)
	// Read at reply time, so a new callback from a re-render does not fetch again.
	const latest = useRef({ shell, onGone: props.onGone })
	useEffect(() => {
		latest.current = { shell, onGone: props.onGone }
	})

	useEffect(() => {
		let live = true
		void request({ kind: 'item.fetch', accountId: latest.current.shell.accountId, id, purpose: mode === 'clone' ? 'clone' : 'edit' }).then((reply) => {
			if (!live) return
			if (reply?.ok) return setState({ item: reply.value })
			if (reply?.error.code === 'not_found') {
				latest.current.shell.toast(i18n.t('item.gone'))
				return latest.current.onGone()
			}
			if (reply?.error.code === 'locked' || reply?.error.code === 'session_revoked') latest.current.shell.refreshState()
			setState({ failure: { error: reply?.error } })
		})
		return () => {
			live = false
		}
	}, [id, mode, attempt])

	const { item } = state
	if (item) {
		// By the type the fetch opened it under: the snapshot's may be renamed since, which would hide or drop fields.
		const initial = fromItem(item, item.typeName)
		if (mode === 'clone') initial.name = i18n.t('item.cloneName', { name: item.name })
		return <Form {...props} item={item} initial={initial} />
	}
	if (!state.failure) return <Loading />
	const { error } = state.failure
	return (
		<div className="stack">
			{error?.code === 'blocked'
				? (
					<Banner tone="error" role="alert">
						{writeErrorText(error)}
						<br /><a href={snapshot.webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a>
					</Banner>
				)
				: (
					// A conflict here can be a row written again while it opened, which the next attempt finds synced.
					<Banner tone="error" role="alert" action={(isRetryable(error) || error?.code === 'conflict') && <Button variant="link" onClick={() => { setState({}); setAttempt((n) => n + 1) }}>{i18n.t('vault.tryAgain')}</Button>}>
						{writeErrorText(error, 'open')}
					</Banner>
				)}
		</div>
	)
}

const ALGORITHMS: TotpAlgorithm[] = ['SHA1', 'SHA256', 'SHA512']

function Form({ mode, item, initial, snapshot, onSaved, onCancel }: Props & { item?: EditableItem; initial: FormValues }) {
	const shell = useShell()
	const draft = useItemDraft(initial, snapshot.types, item && { id: item.typeId, name: item.typeName })
	useUnsavedChangesGuard(draft.dirty)
	const [busy, setBusy] = useState(false)
	/** `without` and `valueKey`: what the failed attempt sent, so Try again sends it again while the values are the same. */
	const [failure, setFailure] = useState<{ error: VaultWriteError | undefined; without?: Partner[]; valueKey?: string } | null>(null)
	const [picking, setPicking] = useState(false)
	const formId = useId()
	const { values, update } = draft
	const kind = formKind(draft.typeName)
	const sections = formSections(kind, values)
	const error = (path: string) => (draft.errors[path] ? fieldErrorText(draft.errors[path]) : null)
	// The partners agreed to so far hold for these values only; a later partner_unreachable names them with any new ones, so one click covers them all.
	// Wrapped, so an untouched field and a cleared one differ.
	const valueKey = JSON.stringify(VALUE_FIELDS.map((field) => (field in draft.patch ? [draft.patch[field]] : [])))
	const [consent, setConsent] = useState<{ valueKey: string; partners: Partner[] }>()
	if (consent && consent.valueKey !== valueKey) setConsent(undefined)

	/** `without`: the partner shares to suspend, which the user agreed to after a `partner_unreachable` failure. */
	async function save(without?: Partner[]) {
		const withoutUnreachable = without?.map((partner) => partner.shareId)
		const next = draft.submit()
		if (!next || shell.offline || busy) return
		setBusy(true)
		setFailure(null)
		let reply: WriteResult<{ id: string; shareSave?: ShareSaveNote }> | undefined
		if (mode === 'edit' && item) {
			if (!draft.dirty) {
				await onSaved(item.id)
				return
			}
			reply = await request({ kind: 'item.update', accountId: shell.accountId, id: item.id, patch: draft.patch, baseUpdatedAt: item.updatedAt, recordId: item.recordId, ...(withoutUnreachable && { withoutUnreachable }) })
		} else {
			reply = await request({ kind: 'item.create', accountId: shell.accountId, draft: next })
		}
		if (reply?.ok) {
			await onSaved(reply.value.id, reply.value.shareSave)
			return
		}
		setBusy(false)
		let failed = reply?.error
		if (failed?.code === 'partner_unreachable') failed = { ...failed, partners: unionOf(without ?? consent?.partners ?? [], failed.partners ?? []) }
		if (failed?.code === 'locked' || failed?.code === 'session_revoked') shell.refreshState()
		setFailure({ error: failed, without, valueKey })
	}

	function failureAction({ error, without, valueKey: failedKey }: NonNullable<typeof failure>): ReactNode {
		const partners = error?.code === 'partner_unreachable' ? error.partners : undefined
		if (partners?.length) {
			const onClick = () => {
				setConsent({ valueKey, partners })
				void save(partners)
			}
			return <Button variant="link" onClick={onClick}>{i18n.t('shareSave.saveWithout')}</Button>
		}
		return isRetryable(error) && <Button variant="link" onClick={() => void save(failedKey === valueKey ? without : undefined)}>{i18n.t('vault.tryAgain')}</Button>
	}

	const card = (member: CardField) => (value: string) => update({ card: { ...values.card, [member]: value } })
	const identity = (member: IdentityField) => (value: string) => update({ identity: { ...values.identity, [member]: value } })
	const cardLabels: Record<CardField, string> = {
		number: i18n.t('detail.number'), expiry: i18n.t('detail.expiry'), cvv: i18n.t('detail.cvv'), pin: i18n.t('detail.pin'), cardholder: i18n.t('detail.cardholder'),
	}
	const identityLabels: Record<IdentityField, string> = {
		firstName: i18n.t('detail.firstName'), lastName: i18n.t('detail.lastName'), address: i18n.t('detail.address'),
		phone: i18n.t('detail.phone'), email: i18n.t('detail.email'), bsn: i18n.t('detail.bsn'),
	}
	const masked: ReadonlySet<string> = new Set(['number', 'cvv', 'pin', 'bsn'])
	const keyboards: Partial<Record<IdentityField, 'email' | 'tel'>> = { email: 'email', phone: 'tel' }

	let seed = { algorithm: 'SHA1' as TotpAlgorithm, digits: 6 as 6 | 8, period: 30 }
	try {
		seed = parseTotpSeed(values.secret)
	} catch {
		// The defaults stand in until the key parses.
	}
	const totp = { ...seed, ...values.totp }
	// Kept once on, so a key typed under SSH key keeps its lines under Login, and deleting the last break keeps the focus.
	const [multiline, setMultiline] = useState(() => hasLineBreak(values.secret))
	if (!multiline && hasLineBreak(values.secret)) setMultiline(true)
	const folderLabel = folderPath(snapshot.folders, values.folderId) ?? i18n.t('filters.noFolder')
	// Labelled as the type the form renders by, which after a rename the snapshot may hold under another id.
	const rendered = draft.typeName === undefined ? undefined : snapshot.types.find((type) => type.id === values.typeId && type.name === draft.typeName) ?? snapshot.types.find((type) => type.name === draft.typeName)
	const typeOptions = draft.typeName === undefined ? snapshot.types : snapshot.types.map((type) => (type.id === values.typeId ? { ...type, label: rendered?.label ?? draft.typeName! } : type))
	const secretLabel = draft.typeName === 'login' || draft.typeName === 'database' || !draft.typeName
		? i18n.t('detail.password')
		: rendered?.label ?? i18n.t('form.secret')
	// Only a login takes a new key; any other type that holds one may only drop it.
	const isLogin = draft.typeName === 'login'
	const authKeyField = (isLogin || values.totpSaved) && (
		<AuthenticatorKeyField
			saved={values.totpSaved}
			mode={values.authKeyMode}
			removeOnly={!isLogin}
			value={values.authKey}
			error={error('authKey')}
			leftOut={mode === 'clone' && item?.totpLeftOut}
			onMode={(authKeyMode) => update({ authKeyMode, authKey: '' })}
			onValue={(authKey) => update({ authKey })}
		/>
	)
	const areaChange = (field: 'secret' | 'notes') => (value: string) => update({ [field]: keepLineBreaks(value, initial[field]) })
	const secretField = (label: string, autoComplete: string, area = multiline, generate = false) => area
		? <TextArea label={label} value={values.secret} onChange={areaChange('secret')} error={error('secret')} rows={6} mono concealed />
		: <TextField label={label} type="password" value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} autoComplete={autoComplete} action={generate ? { icon: 'generate', label: i18n.t('generator.generatePassword'), onClick: (button) => shell.generate('password', (secret) => update({ secret }), button) } : undefined} />

	return (
		<>
			<form id={formId} className="stack" noValidate onSubmit={(event) => {
				event.preventDefault()
				void save()
			}}>
				{shell.offline && (
					<Banner tone="warning" role="status" action={<Button variant="link" onClick={shell.syncNow}>{i18n.t('vault.syncNow')}</Button>}>
						{i18n.t('form.offline')}
					</Banner>
				)}
				{failure && (
					<Banner tone="error" role="alert" action={failureAction(failure)}>
						{writeErrorText(failure.error, mode === 'edit' ? 'edit' : 'item')}
						{failure.error?.code === 'unverified' && <><br /><a href={snapshot.webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a></>}
					</Banner>
				)}
				<FormSection title={i18n.t('form.itemDetails')}>
					<TypeSelect types={typeOptions} value={values.typeId} onChange={(typeId) => update({ typeId })} disabled={mode === 'edit'} />
					<TextField label={i18n.t('form.name')} value={values.name} onChange={(name) => update({ name })} error={error('name')} autoComplete="off" />
					<div className="field">
						<span className="field__label" aria-hidden="true">{i18n.t('form.folder')}</span>
						<button type="button" className="picker-field" aria-label={i18n.t('form.chooseFolder', { folder: folderLabel })} onClick={() => setPicking(true)}>
							<Icon name="folder" />
							<span className="picker-field__value">{folderLabel}</span>
							<Icon name="chevronRight" />
						</button>
					</div>
					{kind === 'note' && <TextArea label={i18n.t('form.note')} value={values.secret} onChange={areaChange('secret')} error={error('secret')} rows={8} />}
				</FormSection>

				{kind === 'passkey' && <p className="hint">{i18n.t('form.passkeyReadOnly')}</p>}
				{kind === 'credential' && (
					<FormSection title={i18n.t('detail.loginCredentials')}>
						{sections.login && <TextField label={i18n.t('detail.username')} value={values.login} onChange={(login) => update({ login })} error={error('login')} autoComplete="off" verbatim action={{ icon: 'generate', label: i18n.t('generator.generateUsername'), onClick: (button) => shell.generate('username', (login) => update({ login }), button) }} />}
						{secretField(secretLabel, 'new-password', isMultiline(draft.typeName) || multiline, true)}
						{authKeyField}
					</FormSection>
				)}
				{kind === 'totp' && (
					<FormSection title={i18n.t('form.authenticator')}>
						{secretField(i18n.t('detail.authenticatorKey'), 'off')}
						<details className="advanced">
							<summary>{i18n.t('form.advanced')}</summary>
							<Select<TotpAlgorithm> label={i18n.t('form.algorithm')} value={totp.algorithm} options={ALGORITHMS.map((value) => ({ value, label: value }))} onChange={(algorithm) => update({ totp: { ...values.totp, algorithm } })} />
							<Select label={i18n.t('form.digits')} value={String(totp.digits)} options={['6', '8'].map((value) => ({ value, label: value }))} onChange={(digits) => update({ totp: { ...values.totp, digits: digits === '8' ? 8 : 6 } })} />
							<Select label={i18n.t('form.period')} value={String(totp.period)} options={[...new Set([30, 60, totp.period])].map((value) => ({ value: String(value), label: String(value) }))} onChange={(period) => update({ totp: { ...values.totp, period: Number(period) } })} />
						</details>
					</FormSection>
				)}
				{(kind === 'card' || kind === 'identity') && (
					<FormSection title={kind === 'card' ? i18n.t('detail.cardDetails') : i18n.t('detail.identity')}>
						{sections.key === 'members'
							? (
								<>
									{kind === 'card' && CARD_FIELDS.map((member) => (
										<TextField key={member} label={cardLabels[member]} type={masked.has(member) ? 'password' : 'text'} value={values.card[member]} onChange={card(member)} error={error(`card.${member}`)} autoComplete="off" />
									))}
									{kind === 'identity' && IDENTITY_FIELDS.map((member) => (
										<TextField key={member} label={identityLabels[member]} type={masked.has(member) ? 'password' : 'text'} value={values.identity[member]} onChange={identity(member)} error={error(`identity.${member}`)} autoComplete="off" inputMode={keyboards[member]} verbatim={member in keyboards} />
									))}
									{error('secret') && <p className="field__error">{error('secret')}</p>}
								</>
							)
							: secretField(secretLabel, 'off')}
					</FormSection>
				)}

				<FormSection title={i18n.t('form.autofillOptions')}>
					{kind !== 'credential' && authKeyField}
					{kind !== 'credential' && sections.login && <TextField label={i18n.t('detail.username')} value={values.login} onChange={(login) => update({ login })} error={error('login')} autoComplete="off" verbatim />}
					<TextField label={i18n.t('form.website')} value={values.url} onChange={(url) => update({ url })} error={error('url')} inputMode="url" autoComplete="off" verbatim />
				</FormSection>
				{sections.fields && (
					<FormSection title={i18n.t('detail.additionalFields')}>
						<AdditionalFieldsEditor fields={values.fields} errors={draft.errors} onChange={(fields) => update({ fields })} />
						{error('additionalFields') && <p className="field__error">{error('additionalFields')}</p>}
					</FormSection>
				)}
				{sections.notes && (
					<FormSection title={i18n.t('form.additionalOptions')}>
						<TextArea label={i18n.t('detail.notes')} value={values.notes} onChange={areaChange('notes')} error={error('notes')} />
					</FormSection>
				)}
			</form>
			<PageFooter>
				<Button type="submit" form={formId} busy={busy} disabled={draft.blocked || shell.offline}>{i18n.t('form.save')}</Button>
				<Button variant="secondary" onClick={onCancel}>{i18n.t('common.cancel')}</Button>
			</PageFooter>
			{picking && (
				<FolderPicker
					title={i18n.t('folders.pickTitle')}
					folders={snapshot.folders}
					current={values.folderId}
					onPick={(folderId) => {
						update({ folderId })
						setPicking(false)
					}}
					onCancel={() => setPicking(false)}
				/>
			)}
		</>
	)
}
