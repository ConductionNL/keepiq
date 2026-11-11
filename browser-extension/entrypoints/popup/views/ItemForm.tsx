import { useEffect, useId, useRef, useState, type ReactNode, type RefObject } from 'react'
import { i18n } from '#i18n'
import type { EditableItem, ShareSaveNote, VaultSnapshotReply, VaultWriteError, WriteResult } from '@/src/messages'
import { parseTotpSeed, type TotpAlgorithm } from '@/src/totp/totp'
import { cardNumberHint, securityCodeHint } from '@/src/vault/cards'
import { CARD_FIELDS, emptyValues, formKind, formSections, fromItem, hasLineBreak, IDENTITY_FIELDS, isMultiline, isNewItemType, keepLineBreaks, type CardField, type FormKind, type FormSections, type FormValues, type IdentityField } from '@/src/vault/fields'
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
/** An add form for one type, without the Type select, from the context menu's Add. */
export type FixedType = 'login' | 'card' | 'identity'

const DIGITS: ReadonlySet<CardField> = new Set(['number', 'cvv', 'pin'])

/** The card number's and security code's advice; it never blocks Save. */
function cardHint(card: Record<CardField, string>, member: CardField): string | undefined {
	if (member === 'cvv') {
		const code = securityCodeHint(card.number, card.cvv)
		return code ? i18n.t('form.cardCodeLength', { brand: code.brand, name: code.name, size: String(code.size) }) : undefined
	}
	if (member !== 'number') return undefined
	const hint = cardNumberHint(card.number)
	if (hint?.code === 'length') return i18n.t('form.cardNumberLength', { brand: hint.brand })
	if (hint?.code === 'lengthUnknown') return i18n.t('form.cardNumberLengthUnknown')
	return hint ? i18n.t('form.cardNumberChecksum') : undefined
}

interface Props {
	mode: FormMode
	/** The item to edit or clone. */
	id?: string
	/** Add mode only; the Shell opens the vault list instead when the cached types lack it. */
	fixedType?: FixedType
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

/** The popup asks for the vault state again after these, which shows the unlock or sign-in page. */
const needsUnlock = (error: VaultWriteError | undefined) => error?.code === 'locked' || error?.code === 'session_revoked'

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
	if (props.mode === 'add') {
		const typeId = props.fixedType ? props.snapshot.types.find((type) => type.name === props.fixedType)?.id ?? '' : defaultTypeId(props.snapshot)
		return <Form {...props} initial={emptyValues(typeId, props.tabUrl ?? '')} />
	}
	return <Fetched {...props} id={props.id!} />
}

type FetchState = {
	item?: EditableItem
	failure?: { error: VaultWriteError | undefined }
}

/** Where a fetch reply goes; `latest` is read at reply time, so a new callback from a re-render does not fetch again. */
type FetchSink = {
	latest: RefObject<{ shell: ReturnType<typeof useShell>; onGone: () => void }>
	setState: (state: FetchState) => void
}

function settleFetch(reply: WriteResult<EditableItem> | undefined, sink: FetchSink): void {
	if (reply?.ok) return sink.setState({ item: reply.value })
	const { shell, onGone } = sink.latest.current
	if (reply?.error.code === 'not_found') {
		shell.toast(i18n.t('item.gone'))
		return onGone()
	}
	if (needsUnlock(reply?.error)) shell.refreshState()
	sink.setState({ failure: { error: reply?.error } })
}

/** By the type the fetch opened it under: the snapshot's may be renamed since, which would hide or drop fields. */
function initialOf(item: EditableItem, mode: FormMode): FormValues {
	const initial = fromItem(item, item.typeName)
	if (mode === 'clone') initial.name = i18n.t('item.cloneName', { name: item.name })
	return initial
}

function FetchFailure({ error, webAppUrl, onRetry }: { error: VaultWriteError | undefined; webAppUrl: string; onRetry: () => void }) {
	if (error?.code === 'blocked') {
		return (
			<Banner tone="error" role="alert">
				{writeErrorText(error)}
				<br /><a href={webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a>
			</Banner>
		)
	}
	// A conflict here can be a row written again while it opened, which the next attempt finds synced.
	return (
		<Banner tone="error" role="alert" action={(isRetryable(error) || error?.code === 'conflict') && <Button variant="link" onClick={onRetry}>{i18n.t('vault.tryAgain')}</Button>}>
			{writeErrorText(error, 'open')}
		</Banner>
	)
}

/** Edit and clone start from a fresh fetch, never the cache (ADR-002). */
function Fetched(props: Props & { id: string }) {
	const { id, mode, snapshot } = props
	const shell = useShell()
	const [state, setState] = useState<FetchState>({})
	const [attempt, setAttempt] = useState(0)
	const latest = useRef({ shell, onGone: props.onGone })
	useEffect(() => {
		latest.current = { shell, onGone: props.onGone }
	})

	useEffect(() => {
		let live = true
		void request({ kind: 'item.fetch', accountId: latest.current.shell.accountId, id, purpose: mode === 'clone' ? 'clone' : 'edit' }).then((reply) => {
			if (live) settleFetch(reply, { latest, setState })
		})
		return () => {
			live = false
		}
	}, [id, mode, attempt])

	const { item } = state
	if (item) return <Form {...props} item={item} initial={initialOf(item, mode)} />
	if (!state.failure) return <Loading />
	const retry = () => {
		setState({})
		setAttempt((n) => n + 1)
	}
	return (
		<div className="stack">
			<FetchFailure error={state.failure.error} webAppUrl={snapshot.webAppUrl} onRetry={retry} />
		</div>
	)
}

const ALGORITHMS: TotpAlgorithm[] = ['SHA1', 'SHA256', 'SHA512']

/** `without` and `valueKey`: what the failed attempt sent, so Try again sends it again while the values are the same. */
interface SaveFailure {
	error: VaultWriteError | undefined
	without?: Partner[]
	valueKey?: string
}

function OfflineNotice({ offline, onSync }: { offline: boolean; onSync: () => void }) {
	if (!offline) return null
	return (
		<Banner tone="warning" role="status" action={<Button variant="link" onClick={onSync}>{i18n.t('vault.syncNow')}</Button>}>
			{i18n.t('form.offline')}
		</Banner>
	)
}

/** The last save's failure; an edit reads a conflict as a stale copy. */
function SaveFailureNotice({ failure, editing, actionFor, webAppUrl }: { failure: SaveFailure | null; editing: boolean; actionFor: (failure: SaveFailure) => ReactNode; webAppUrl: string }) {
	if (!failure) return null
	return (
		<Banner tone="error" role="alert" action={actionFor(failure)}>
			{writeErrorText(failure.error, editing ? 'edit' : 'item')}
			{failure.error?.code === 'unverified' && <><br /><a href={webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a></>}
		</Banner>
	)
}

/** For a limit the fields reach only together, such as card members over the byte limit, which no single field can show. */
function GroupError({ text }: { text: string | null }) {
	return text ? <p className="field__error">{text}</p> : null
}

type FormProps = Props & { item?: EditableItem; initial: FormValues }
type Draft = ReturnType<typeof useItemDraft>
type DraftItem = NonNullable<ReturnType<Draft['submit']>>
type SaveReply = WriteResult<{ id: string; shareSave?: ShareSaveNote }> | undefined
type TypeRow = Unlocked['types'][number]

/** An edit without changes only closes the form. */
const UNCHANGED = Symbol('unchanged')

/** A partner_unreachable failure names the partners agreed to so far with any new ones, so one click covers them all. */
function withAgreed(error: VaultWriteError | undefined, agreed: Partner[]): VaultWriteError | undefined {
	if (error?.code !== 'partner_unreachable') return error
	return { ...error, partners: unionOf(agreed, error.partners ?? []) }
}

/** The partner shares an update suspends, which the user agreed to after a `partner_unreachable` failure. */
function suspending(without: Partner[] | undefined): { withoutUnreachable?: string[] } {
	const withoutUnreachable = without?.map((partner) => partner.shareId)
	return withoutUnreachable ? { withoutUnreachable } : {}
}

/** Save, its failure and the partner shares the user agreed to suspend. */
function useSave({ mode, item, onSaved }: FormProps, draft: Draft) {
	const shell = useShell()
	const [busy, setBusy] = useState(false)
	const [failure, setFailure] = useState<SaveFailure | null>(null)
	// The partners agreed to so far hold for these values only. Wrapped, so an untouched field and a cleared one differ.
	const valueKey = JSON.stringify(VALUE_FIELDS.map((field) => (field in draft.patch ? [draft.patch[field]] : [])))
	const [consent, setConsent] = useState<{ valueKey: string; partners: Partner[] }>()
	if (consent && consent.valueKey !== valueKey) setConsent(undefined)

	const idle = () => !shell.offline && !busy

	/** `without`: the partner shares to suspend, which the user agreed to after a `partner_unreachable` failure. */
	async function save(without?: Partner[]) {
		const next = draft.submit()
		if (!next || !idle()) return
		setBusy(true)
		setFailure(null)
		const reply = await write(next, without)
		if (reply === UNCHANGED) return
		if (reply?.ok) {
			await onSaved(reply.value.id, reply.value.shareSave)
			return
		}
		fail(reply?.error, without)
	}

	async function write(next: DraftItem, without?: Partner[]): Promise<SaveReply | typeof UNCHANGED> {
		if (mode !== 'edit' || !item) return request({ kind: 'item.create', accountId: shell.accountId, draft: next })
		if (!draft.dirty) {
			await onSaved(item.id)
			return UNCHANGED
		}
		return request({ kind: 'item.update', accountId: shell.accountId, id: item.id, patch: draft.patch, baseUpdatedAt: item.updatedAt, recordId: item.recordId, ...suspending(without) })
	}

	function fail(error: VaultWriteError | undefined, without?: Partner[]) {
		setBusy(false)
		const failed = withAgreed(error, without ?? consent?.partners ?? [])
		if (needsUnlock(failed)) shell.refreshState()
		setFailure({ error: failed, without, valueKey })
	}

	function failureAction({ error, without, valueKey: failedKey }: SaveFailure): ReactNode {
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

	return { busy, failure, save, failureAction }
}

/** Kept once on, so a key typed under SSH key keeps its lines under Login, and deleting the last break keeps the focus. */
function useKeptMultiline(secret: string): boolean {
	const [multiline, setMultiline] = useState(() => hasLineBreak(secret))
	if (!multiline && hasLineBreak(secret)) setMultiline(true)
	return multiline
}

/** Labelled as the type the form renders by, which after a rename the snapshot may hold under another id. */
function renderedType(types: TypeRow[], draft: Draft): TypeRow | undefined {
	if (draft.typeName === undefined) return undefined
	return types.find((type) => type.id === draft.values.typeId && type.name === draft.typeName) ?? types.find((type) => type.name === draft.typeName)
}

function typeOptionsOf(types: TypeRow[], draft: Draft): TypeRow[] {
	if (draft.typeName === undefined) return types
	const label = renderedType(types, draft)?.label ?? draft.typeName
	return types.map((type) => (type.id === draft.values.typeId ? { ...type, label } : type))
}

function secretLabelOf(typeName: string | undefined, rendered: TypeRow | undefined): string {
	if (typeName === 'login' || typeName === 'database' || !typeName) return i18n.t('detail.password')
	return rendered?.label ?? i18n.t('form.secret')
}

/** What the form's sections render from. */
type FormView = {
	values: FormValues
	update: Draft['update']
	errors: Draft['errors']
	error: (path: string) => string | null
	kind: FormKind
	typeName: string | undefined
	sections: FormSections
	multiline: boolean
	initial: FormValues
	mode: FormMode
	totpLeftOut: boolean | undefined
	generate: ReturnType<typeof useShell>['generate']
	secretLabel: string
	folderLabel: string
	typeSelect: ReactNode
}

function useFormView(props: FormProps, draft: Draft): FormView {
	const shell = useShell()
	const multiline = useKeptMultiline(draft.values.secret)
	const { values, errors, typeName } = draft
	const { snapshot } = props
	const kind = formKind(typeName)
	return {
		values, update: draft.update, errors, error: (path) => (errors[path] ? fieldErrorText(errors[path]) : null),
		kind, typeName, sections: formSections(kind, values), multiline, initial: props.initial, mode: props.mode,
		totpLeftOut: props.item?.totpLeftOut, generate: shell.generate,
		secretLabel: secretLabelOf(typeName, renderedType(snapshot.types, draft)),
		folderLabel: folderPath(snapshot.folders, values.folderId) ?? i18n.t('filters.noFolder'),
		typeSelect: !props.fixedType && <TypeSelect types={typeOptionsOf(snapshot.types, draft)} value={values.typeId} onChange={(typeId) => draft.update({ typeId })} disabled={props.mode === 'edit'} />,
	}
}

const areaChange = (view: FormView, field: 'secret' | 'notes') => (value: string) => view.update({ [field]: keepLineBreaks(value, view.initial[field]) })

type SecretFieldProps = {
	view: FormView
	label: string
	autoComplete: string
	area?: boolean
	generate?: boolean
}

function SecretField({ view, label, autoComplete, area = view.multiline, generate = false }: SecretFieldProps) {
	const { values, update, error } = view
	if (area) return <TextArea label={label} value={values.secret} onChange={areaChange(view, 'secret')} mono concealed error={error('secret')} rows={6} />
	return <TextField label={label} type="password" value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} autoComplete={autoComplete} action={generate ? { icon: 'generate', label: i18n.t('generator.generatePassword'), onClick: (button) => view.generate('password', (secret) => update({ secret }), button) } : undefined} />
}

/** Only a login takes a new key; any other type that holds one may only drop it. */
function AuthKey({ view }: { view: FormView }) {
	const { values, update } = view
	const isLogin = view.typeName === 'login'
	if (!isLogin && !values.totpSaved) return null
	return (
		<AuthenticatorKeyField
			saved={values.totpSaved}
			mode={values.authKeyMode}
			removeOnly={!isLogin}
			value={values.authKey}
			error={view.error('authKey')}
			leftOut={view.mode === 'clone' && view.totpLeftOut}
			onMode={(authKeyMode) => update({ authKeyMode, authKey: '' })}
			onValue={(authKey) => update({ authKey })}
		/>
	)
}

function DetailsSection({ view, onPickFolder }: { view: FormView; onPickFolder: () => void }) {
	const { values, update, error } = view
	return (
		<FormSection title={i18n.t('form.itemDetails')}>
			{view.typeSelect}
			<TextField label={i18n.t('form.name')} value={values.name} onChange={(name) => update({ name })} error={error('name')} autoComplete="off" />
			<div className="field">
				<span className="field__label" aria-hidden="true">{i18n.t('form.folder')}</span>
				<button type="button" className="picker-field" aria-label={i18n.t('form.chooseFolder', { folder: view.folderLabel })} onClick={onPickFolder}>
					<Icon name="folder" />
					<span className="picker-field__value">{view.folderLabel}</span>
					<Icon name="chevronRight" />
				</button>
			</div>
			{view.kind === 'note' && <TextArea label={i18n.t('form.note')} value={values.secret} onChange={areaChange(view, 'secret')} error={error('secret')} rows={8} />}
		</FormSection>
	)
}

function PasskeyHint() {
	return (
		<p className="hint">{i18n.t('form.passkeyReadOnly')}</p>
	)
}

function CredentialSection({ view }: { view: FormView }) {
	const { values, update, error } = view
	return (
		<FormSection title={i18n.t('detail.loginCredentials')}>
			{view.sections.login && <TextField label={i18n.t('detail.username')} value={values.login} onChange={(login) => update({ login })} error={error('login')} autoComplete="off" verbatim action={{ icon: 'generate', label: i18n.t('generator.generateUsername'), onClick: (button) => view.generate('username', (login) => update({ login }), button) }} />}
			<SecretField view={view} label={view.secretLabel} autoComplete="new-password" generate area={isMultiline(view.typeName) || view.multiline} />
			<AuthKey view={view} />
		</FormSection>
	)
}

/** The key's own settings, else the defaults until the key parses, under what the user picked. */
function totpSettings(values: FormValues) {
	let seed = { algorithm: 'SHA1' as TotpAlgorithm, digits: 6 as 6 | 8, period: 30 }
	try {
		seed = parseTotpSeed(values.secret)
	} catch {
		// The defaults stand in until the key parses.
	}
	return { ...seed, ...values.totp }
}

function TotpSection({ view }: { view: FormView }) {
	const { values, update } = view
	const totp = totpSettings(values)
	return (
		<FormSection title={i18n.t('form.authenticator')}>
			<SecretField view={view} label={i18n.t('detail.authenticatorKey')} autoComplete="off" />
			<details className="advanced">
				<summary>{i18n.t('form.advanced')}</summary>
				<Select<TotpAlgorithm> label={i18n.t('form.algorithm')} value={totp.algorithm} options={ALGORITHMS.map((value) => ({ value, label: value }))} onChange={(algorithm) => update({ totp: { ...values.totp, algorithm } })} />
				<Select label={i18n.t('form.digits')} value={String(totp.digits)} options={['6', '8'].map((value) => ({ value, label: value }))} onChange={(digits) => update({ totp: { ...values.totp, digits: digits === '8' ? 8 : 6 } })} />
				<Select label={i18n.t('form.period')} value={String(totp.period)} options={[...new Set([30, 60, totp.period])].map((value) => ({ value: String(value), label: String(value) }))} onChange={(period) => update({ totp: { ...values.totp, period: Number(period) } })} />
			</details>
		</FormSection>
	)
}

const MASKED: ReadonlySet<string> = new Set(['number', 'cvv', 'pin', 'bsn'])
const KEYBOARDS: Partial<Record<IdentityField, 'email' | 'tel'>> = { email: 'email', phone: 'tel' }

const cardLabels = (): Record<CardField, string> => ({
	number: i18n.t('detail.number'), expiry: i18n.t('detail.expiry'), cvv: i18n.t('detail.cvv'), pin: i18n.t('detail.pin'), cardholder: i18n.t('detail.cardholder'),
})

const identityLabels = (): Record<IdentityField, string> => ({
	firstName: i18n.t('detail.firstName'), lastName: i18n.t('detail.lastName'), address: i18n.t('detail.address'),
	phone: i18n.t('detail.phone'), email: i18n.t('detail.email'), bsn: i18n.t('detail.bsn'),
})

function CardMembers({ view }: { view: FormView }) {
	const { values, update, error } = view
	const labels = cardLabels()
	const card = (member: CardField) => (value: string) => update({ card: { ...values.card, [member]: value } })
	return CARD_FIELDS.map((member) => (
		<TextField key={member} label={labels[member]} type={MASKED.has(member) ? 'password' : 'text'} value={values.card[member]} onChange={card(member)} error={error(`card.${member}`)} hint={cardHint(values.card, member)} autoComplete="off" inputMode={DIGITS.has(member) ? 'numeric' : undefined} />
	))
}

function IdentityMembers({ view }: { view: FormView }) {
	const { values, update, error } = view
	const labels = identityLabels()
	const identity = (member: IdentityField) => (value: string) => update({ identity: { ...values.identity, [member]: value } })
	return IDENTITY_FIELDS.map((member) => (
		<TextField key={member} label={labels[member]} type={MASKED.has(member) ? 'password' : 'text'} value={values.identity[member]} onChange={identity(member)} error={error(`identity.${member}`)} autoComplete="off" inputMode={KEYBOARDS[member]} verbatim={member in KEYBOARDS} />
	))
}

function MembersSection({ view }: { view: FormView }) {
	return (
		<FormSection title={view.kind === 'card' ? i18n.t('detail.cardDetails') : i18n.t('detail.identity')}>
			{view.sections.key === 'members'
				? (
					<>
						{view.kind === 'card' && <CardMembers view={view} />}
						{view.kind === 'identity' && <IdentityMembers view={view} />}
						<GroupError text={view.error('secret')} />
					</>
				)
				: <SecretField view={view} label={view.secretLabel} autoComplete="off" />}
		</FormSection>
	)
}

const KIND_SECTIONS: Partial<Record<FormKind, (props: { view: FormView }) => ReactNode>> = {
	passkey: PasskeyHint, credential: CredentialSection, totp: TotpSection, card: MembersSection, identity: MembersSection,
}

function KindSection({ view }: { view: FormView }) {
	const Section = KIND_SECTIONS[view.kind]
	return Section ? <Section view={view} /> : null
}

function AutofillSection({ view }: { view: FormView }) {
	const { values, update, error } = view
	const credential = view.kind === 'credential'
	return (
		<FormSection title={i18n.t('form.autofillOptions')}>
			{!credential && <AuthKey view={view} />}
			{!credential && view.sections.login && <TextField label={i18n.t('detail.username')} value={values.login} onChange={(login) => update({ login })} error={error('login')} verbatim autoComplete="off" />}
			<TextField label={i18n.t('form.website')} value={values.url} onChange={(url) => update({ url })} error={error('url')} inputMode="url" verbatim autoComplete="off" />
		</FormSection>
	)
}

function ExtraSections({ view }: { view: FormView }) {
	const { values, update, error, sections } = view
	return (
		<>
			{sections.fields && (
				<FormSection title={i18n.t('detail.additionalFields')}>
					<AdditionalFieldsEditor fields={values.fields} errors={view.errors} onChange={(fields) => update({ fields })} />
					<GroupError text={error('additionalFields')} />
				</FormSection>
			)}
			{sections.notes && (
				<FormSection title={i18n.t('form.additionalOptions')}>
					<TextArea label={i18n.t('detail.notes')} value={values.notes} onChange={areaChange(view, 'notes')} error={error('notes')} />
				</FormSection>
			)}
		</>
	)
}

function FolderChoice({ view, snapshot, onClose }: { view: FormView; snapshot: Unlocked; onClose: () => void }) {
	return (
		<FolderPicker
			title={i18n.t('folders.pickTitle')}
			folders={snapshot.folders}
			current={view.values.folderId}
			onPick={(folderId) => {
				view.update({ folderId })
				onClose()
			}}
			onCancel={onClose}
		/>
	)
}

function Form(props: FormProps) {
	const { mode, item, initial, snapshot, onCancel } = props
	const shell = useShell()
	const draft = useItemDraft(initial, snapshot.types, item && { id: item.typeId, name: item.typeName })
	useUnsavedChangesGuard(draft.dirty)
	const saving = useSave(props, draft)
	const [picking, setPicking] = useState(false)
	const formId = useId()
	const view = useFormView(props, draft)
	return (
		<>
			<form id={formId} className="stack" noValidate onSubmit={(event) => {
				event.preventDefault()
				void saving.save()
			}}>
				<OfflineNotice offline={shell.offline} onSync={shell.syncNow} />
				<SaveFailureNotice failure={saving.failure} editing={mode === 'edit'} actionFor={saving.failureAction} webAppUrl={snapshot.webAppUrl} />
				<DetailsSection view={view} onPickFolder={() => setPicking(true)} />
				<KindSection view={view} />
				<AutofillSection view={view} />
				<ExtraSections view={view} />
			</form>
			<PageFooter>
				<Button type="submit" form={formId} busy={saving.busy} disabled={draft.blocked || shell.offline}>{i18n.t('form.save')}</Button>
				<Button variant="secondary" onClick={onCancel}>{i18n.t('common.cancel')}</Button>
			</PageFooter>
			{picking && <FolderChoice view={view} snapshot={snapshot} onClose={() => setPicking(false)} />}
		</>
	)
}
