import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { EditableItem, VaultSnapshotReply, VaultWriteError } from '@/src/messages'
import { parseTotpSeed, type TotpAlgorithm } from '@/src/totp/totp'
import { CARD_FIELDS, emptyValues, formKind, fromItem, IDENTITY_FIELDS, isMultiline, type CardField, type FormValues, type IdentityField } from '@/src/vault/fields'
import { folderPath } from '@/src/vault/list'
import { AdditionalFieldsEditor } from '../components/AdditionalFieldsEditor'
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
	/** Awaited, so Save stays busy until the shell shows the result. */
	onSaved: (id: string) => Promise<void> | void
	onCancel: () => void
	/** The item was deleted elsewhere. */
	onGone: () => void
}

const isRetryable = (error: VaultWriteError | undefined) => !error || error.code === 'offline' || error.code === 'server_error' || error.code === 'write_locked'

function FormSection({ title, children }: { title: string; children: ReactNode }) {
	return (
		<section className="section">
			<h2 className="section__title">{title}</h2>
			<div className="card form-card">{children}</div>
		</section>
	)
}

/** The user's default type, else `login`; never a passkey, which only a passkey ceremony creates. */
function defaultTypeId(snapshot: Unlocked): string {
	const usable = snapshot.types.filter((type) => type.name !== 'passkey')
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

	if (state.item) {
		const initial = fromItem(state.item)
		if (mode === 'clone') initial.name = i18n.t('item.cloneName', { name: state.item.name })
		return <Form {...props} item={state.item} initial={initial} />
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
					<Banner tone="error" role="alert" action={isRetryable(error) && <Button variant="link" onClick={() => { setState({}); setAttempt((n) => n + 1) }}>{i18n.t('vault.tryAgain')}</Button>}>
						{writeErrorText(error)}
					</Banner>
				)}
		</div>
	)
}

const ALGORITHMS: TotpAlgorithm[] = ['SHA1', 'SHA256', 'SHA512']

function Form({ mode, item, initial, snapshot, onSaved, onCancel }: Props & { item?: EditableItem; initial: FormValues }) {
	const shell = useShell()
	const draft = useItemDraft(initial, snapshot.types)
	useUnsavedChangesGuard(draft.dirty)
	const [busy, setBusy] = useState(false)
	const [failure, setFailure] = useState<{ error: VaultWriteError | undefined } | null>(null)
	const [picking, setPicking] = useState(false)
	const formId = useId()
	const { values, update } = draft
	const kind = formKind(draft.typeName)
	const error = (path: string) => (draft.errors[path] ? fieldErrorText(draft.errors[path]) : null)

	async function save() {
		const next = draft.submit()
		if (!next || shell.offline || busy) return
		setBusy(true)
		setFailure(null)
		let reply
		if (mode === 'edit' && item) {
			if (!draft.dirty) {
				await onSaved(item.id)
				return
			}
			reply = await request({ kind: 'item.update', accountId: shell.accountId, id: item.id, patch: draft.patch, baseUpdatedAt: item.updatedAt })
		} else {
			reply = await request({ kind: 'item.create', accountId: shell.accountId, draft: next })
		}
		if (reply?.ok) {
			await onSaved(reply.value.id)
			return
		}
		setBusy(false)
		if (reply?.error.code === 'locked' || reply?.error.code === 'session_revoked') shell.refreshState()
		setFailure({ error: reply?.error })
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

	let seed = { algorithm: 'SHA1' as TotpAlgorithm, digits: 6 as 6 | 8, period: 30 }
	try {
		seed = parseTotpSeed(values.secret)
	} catch {
		// The defaults stand in until the key parses.
	}
	const totp = { ...seed, ...values.totp }
	const folderLabel = folderPath(snapshot.folders, values.folderId) ?? i18n.t('filters.noFolder')
	const secretLabel = draft.typeName === 'login' || draft.typeName === 'database' || !draft.typeName
		? i18n.t('detail.password')
		: snapshot.types.find((type) => type.id === values.typeId)?.label ?? i18n.t('form.secret')

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
					<Banner tone="error" role="alert" action={isRetryable(failure.error) && <Button variant="link" onClick={() => void save()}>{i18n.t('vault.tryAgain')}</Button>}>
						{writeErrorText(failure.error, mode === 'edit' ? 'edit' : 'item')}
					</Banner>
				)}
				<FormSection title={i18n.t('form.itemDetails')}>
					<TypeSelect types={snapshot.types} value={values.typeId} onChange={(typeId) => update({ typeId })} disabled={mode === 'edit'} />
					<TextField label={i18n.t('form.name')} value={values.name} onChange={(name) => update({ name })} error={error('name')} autoComplete="off" />
					<div className="field">
						<span className="field__label" aria-hidden="true">{i18n.t('form.folder')}</span>
						<button type="button" className="picker-field" aria-label={i18n.t('form.chooseFolder', { folder: folderLabel })} onClick={() => setPicking(true)}>
							<Icon name="folder" />
							<span className="picker-field__value">{folderLabel}</span>
							<Icon name="chevronRight" />
						</button>
					</div>
					{kind === 'note' && <TextArea label={i18n.t('form.note')} value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} rows={8} />}
				</FormSection>

				{kind === 'passkey' && <p className="hint">{i18n.t('form.passkeyReadOnly')}</p>}
				{kind === 'credential' && (
					<FormSection title={i18n.t('detail.loginCredentials')}>
						<TextField label={i18n.t('detail.username')} value={values.login} onChange={(login) => update({ login })} error={error('login')} autoComplete="off" />
						{isMultiline(draft.typeName)
							? <TextArea label={secretLabel} value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} rows={6} spellCheck={false} mono />
							// The generate button joins here with ext-generator's pick mode.
							: <TextField label={secretLabel} type="password" value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} autoComplete="new-password" />}
					</FormSection>
				)}
				{kind === 'totp' && (
					<FormSection title={i18n.t('form.authenticator')}>
						<TextField label={i18n.t('detail.authenticatorKey')} type="password" value={values.secret} onChange={(secret) => update({ secret })} error={error('secret')} autoComplete="off" spellCheck={false} />
						<details className="advanced">
							<summary>{i18n.t('form.advanced')}</summary>
							<Select<TotpAlgorithm> label={i18n.t('form.algorithm')} value={totp.algorithm} options={ALGORITHMS.map((value) => ({ value, label: value }))} onChange={(algorithm) => update({ totp: { ...values.totp, algorithm } })} />
							<Select label={i18n.t('form.digits')} value={String(totp.digits)} options={['6', '8'].map((value) => ({ value, label: value }))} onChange={(digits) => update({ totp: { ...values.totp, digits: digits === '8' ? 8 : 6 } })} />
							<Select label={i18n.t('form.period')} value={String(totp.period)} options={[...new Set([30, 60, totp.period])].map((value) => ({ value: String(value), label: String(value) }))} onChange={(period) => update({ totp: { ...values.totp, period: Number(period) } })} />
						</details>
					</FormSection>
				)}
				{kind === 'card' && (
					<FormSection title={i18n.t('detail.cardDetails')}>
						{CARD_FIELDS.map((member) => (
							<TextField key={member} label={cardLabels[member]} type={masked.has(member) ? 'password' : 'text'} value={values.card[member]} onChange={card(member)} error={error(`card.${member}`)} autoComplete="off" />
						))}
						{error('secret') && <p className="field__error">{error('secret')}</p>}
					</FormSection>
				)}
				{kind === 'identity' && (
					<FormSection title={i18n.t('detail.identity')}>
						{IDENTITY_FIELDS.map((member) => (
							<TextField key={member} label={identityLabels[member]} type={masked.has(member) ? 'password' : 'text'} value={values.identity[member]} onChange={identity(member)} error={error(`identity.${member}`)} autoComplete="off" />
						))}
						{error('secret') && <p className="field__error">{error('secret')}</p>}
					</FormSection>
				)}

				<FormSection title={i18n.t('form.autofillOptions')}>
					<TextField label={i18n.t('form.website')} value={values.url} onChange={(url) => update({ url })} error={error('url')} inputMode="url" autoComplete="off" spellCheck={false} />
				</FormSection>
				{kind !== 'passkey' && (
					<FormSection title={i18n.t('detail.additionalFields')}>
						<AdditionalFieldsEditor fields={values.fields} errors={draft.errors} onChange={(fields) => update({ fields })} />
						{error('additionalFields') && <p className="field__error">{error('additionalFields')}</p>}
					</FormSection>
				)}
				{kind !== 'passkey' && kind !== 'note' && (
					<FormSection title={i18n.t('form.additionalOptions')}>
						<TextArea label={i18n.t('detail.notes')} value={values.notes} onChange={(notes) => update({ notes })} error={error('notes')} />
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
