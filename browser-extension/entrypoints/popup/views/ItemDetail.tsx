import { useEffect, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { USE_ONLY_FIELDS, type DecryptField, type DecryptedItem, type FolderMeta, type ItemMeta, type ShareSaveNote as Note, type TypeMeta } from '@/src/messages'
import { typeIcon } from '@/src/vault/icons'
import { folderPath, launchUrl } from '@/src/vault/list'
import { cardBrand, cardLast4, parseObject, parsePayload, text } from '@/src/vault/payloads'
import { IconButton } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Icon, type IconName } from '../components/Icon'
import { itemActions } from '../components/ItemCard'
import { Loading } from '../components/Loading'
import { asLines, MaskedField } from '../components/MaskedField'
import { Menu, type MenuItem } from '../components/Menu'
import { PageFooter } from '../components/PageFooter'
import { ShareSaveNote } from '../components/ShareSaveNote'
import { TotpCode } from '../components/TotpCode'
import { blockedText } from '../errors'
import { useDecryptedFields } from '../hooks/useDecryptedFields'
import { request } from '../hooks/useMessage'
import { language } from '../i18n'
import { useShell } from '../shell-context'

interface Props {
	item: ItemMeta
	type: TypeMeta | undefined
	folders: FolderMeta[]
	webAppUrl: string
	/** What the last save of this item left to others; held by Shell. */
	shareSave?: Note
}

/** Types with their own section instead of Login credentials. */
const OWN_SECTION = new Set(['totp', 'card', 'identity', 'passkey', 'note'])

const dateTime = (iso: string) => new Date(iso).toLocaleString(language())

function Section({ title, children }: { title: string; children: ReactNode }) {
	return (
		<section className="section">
			<h2 className="section__title">{title}</h2>
			<div className="card fields">{children}</div>
		</section>
	)
}

function Unreadable({ title, message }: { title: string; message: string }) {
	return <Section title={title}><p className="field-row field-row__error">{message}</p></Section>
}

/** What a non-composite type's `key` is called. */
function secretLabel(type: TypeMeta | undefined): string {
	if (!type || type.name === 'login' || type.name === 'database') return i18n.t('detail.password')
	return type.label
}

function Card({ payload }: { payload: Record<string, unknown> }) {
	const number = text(payload, 'number')
	const brand = cardBrand(number)
	const fields = [
		[i18n.t('detail.cardholder'), text(payload, 'cardholder'), false],
		[i18n.t('detail.expiry'), text(payload, 'expiry'), false],
		[i18n.t('detail.cvv'), text(payload, 'cvv'), true],
		[i18n.t('detail.pin'), text(payload, 'pin'), true],
	] as const
	return (
		<Section title={i18n.t('detail.cardDetails')}>
			{number && <MaskedField label={brand ? i18n.t('detail.numberWithBrand', { brand }) : i18n.t('detail.number')} what={i18n.t('detail.cardNumber')} value={number} masked maskedText={`•••• •••• •••• ${cardLast4(number)}`} />}
			{fields.map(([label, value, masked]) => value && <MaskedField key={label} label={label} value={value} masked={masked} />)}
		</Section>
	)
}

function Identity({ payload }: { payload: Record<string, unknown> }) {
	const fields = [
		[i18n.t('detail.firstName'), text(payload, 'firstName'), false],
		[i18n.t('detail.lastName'), text(payload, 'lastName'), false],
		[i18n.t('detail.address'), text(payload, 'address'), false],
		[i18n.t('detail.phone'), text(payload, 'phone'), false],
		[i18n.t('detail.email'), text(payload, 'email'), false],
		[i18n.t('detail.bsn'), text(payload, 'bsn'), true],
	] as const
	return (
		<Section title={i18n.t('detail.identity')}>
			{fields.map(([label, value, masked]) => value && <MaskedField key={label} label={label} value={value} masked={masked} />)}
		</Section>
	)
}

/** Shows who and where; the private key is never rendered. */
function Passkey({ payload }: { payload: Record<string, unknown> }) {
	const rpId = text(payload, 'rpId')
	const rpName = text(payload, 'rpName')
	const user = text(payload, 'userName') || text(payload, 'userDisplayName')
	const created = text(payload, 'createdAt')
	return (
		<Section title={i18n.t('detail.passkey')}>
			<MaskedField label={i18n.t('detail.relyingParty')} value={rpName ? `${rpName} (${rpId})` : rpId} copyable={false} />
			{user && <MaskedField label={i18n.t('detail.passkeyUser')} value={user} copyable={false} />}
			{created && <MaskedField label={i18n.t('detail.created')} value={dateTime(created)} copyable={false} />}
			<p className="field-row field-row__note">{i18n.t('detail.passkeyUnsupported')}</p>
		</Section>
	)
}

function Website({ item, username }: { item: ItemMeta; username?: string | null }) {
	const launch = launchUrl(item.url)
	const shell = useShell()
	if (!item.url && !username) return null
	return (
		<Section title={username ? i18n.t('form.autofillOptions') : i18n.t('detail.website')}>
			{username && <MaskedField label={i18n.t('detail.username')} value={username} />}
			{item.url && (
				<MaskedField
					label={i18n.t('detail.website')}
					value={item.url}
					actions={launch && <IconButton icon="external" label={i18n.t('item.launch', { name: item.name })} onClick={() => shell.launch(launch)} />}
				/>
			)}
		</Section>
	)
}

/** Whatever its type, a use-only copy shows its login name and site only. */
function UseOnly({ item, values }: { item: ItemMeta; values: DecryptedItem }) {
	return (
		<>
			<Section title={i18n.t('detail.loginCredentials')}>
				{values.login !== undefined && <MaskedField label={i18n.t('detail.username')} value={values.login} />}
				<p className="field-row field-row__note">{i18n.t('detail.useOnly')}</p>
			</Section>
			<Website item={item} />
		</>
	)
}

const ALL_FIELDS: readonly DecryptField[] = ['login', 'key', 'additionalFields']

function Values({ item, type, values }: { item: ItemMeta; type: TypeMeta | undefined; values: DecryptedItem }) {
	const name = type?.name ?? ''
	const key = values.key ?? ''
	let extra: Array<[string, string]> | null = []
	if (values.additionalFields) {
		const parsed = parseObject(values.additionalFields)
		extra = parsed && Object.entries(parsed).map(([field, value]) => [field, typeof value === 'string' ? value : JSON.stringify(value)])
	}
	const notes = [...(name === 'note' && key ? [key] : []), ...(extra ?? []).filter(([field]) => field.toLowerCase() === 'notes').map(([, value]) => value)]
	const fields = (extra ?? []).filter(([field]) => field.toLowerCase() !== 'notes')
	// As the edit form reads them, so both show a key that is not members as one secret.
	const members = name === 'card' || name === 'identity' ? parsePayload(key) : null
	const passkey = name === 'passkey' ? parseObject(key) : null
	// Outside Login credentials, the username goes with the website, as in the edit form's Autofill options.
	const username = OWN_SECTION.has(name) && name !== 'passkey' && values.login ? values.login : null
	const plainKey = (title: string) => (key ? <Section title={title}><MaskedField label={secretLabel(type)} value={key} masked /></Section> : null)

	return (
		<>
			{!OWN_SECTION.has(name) && (
				<Section title={i18n.t('detail.loginCredentials')}>
					{values.login !== undefined && <MaskedField label={i18n.t('detail.username')} value={values.login} />}
					<MaskedField label={secretLabel(type)} value={key} masked />
				</Section>
			)}
			{name === 'totp' && <Section title={i18n.t('detail.authenticatorKey')}><TotpCode seed={key} /></Section>}
			{name === 'card' && (members ? <Card payload={members} /> : plainKey(i18n.t('detail.cardDetails')))}
			{name === 'identity' && (members ? <Identity payload={members} /> : plainKey(i18n.t('detail.identity')))}
			{name === 'passkey' && (passkey ? <Passkey payload={passkey} /> : <Unreadable title={i18n.t('detail.passkey')} message={i18n.t('detail.unreadablePasskey')} />)}
			<Website item={item} username={username} />
			{extra === null
				? <Unreadable title={i18n.t('detail.additionalFields')} message={i18n.t('detail.unreadableFields')} />
				: fields.length > 0 && (
					<Section title={i18n.t('detail.additionalFields')}>
						{fields.map(([field, value]) => <MaskedField key={field} label={field} value={value} masked />)}
					</Section>
				)}
			{notes.length > 0 && (
				<Section title={i18n.t('detail.notes')}>
					{notes.map((note, i) => <p key={i} className="field-row notes">{asLines(note)}</p>)}
				</Section>
			)}
		</>
	)
}

function Decrypted({ item, type, passive }: { item: ItemMeta; type: TypeMeta | undefined; passive: boolean }) {
	const decrypted = useDecryptedFields(item.id, item.useOnly ? USE_ONLY_FIELDS : ALL_FIELDS, true, passive)
	if (decrypted.status === 'loading') return <Loading>{i18n.t('detail.decrypting')}</Loading>
	// `use_only` only when a sync made the copy use-only after this view asked.
	if (decrypted.status === 'error') return <ErrorBanner>{i18n.t(decrypted.error === 'use_only' ? 'detail.useOnly' : 'detail.decryptFailed')}</ErrorBanner>
	return item.useOnly ? <UseOnly item={item} values={decrypted.item} /> : <Values item={item} type={type} values={decrypted.item} />
}

/**
 * `aria-disabled` keeps the button focusable, so its reason can be read. With `icon`, the
 * label is only its name and tooltip, and the reason takes the tooltip's place.
 */
function ActionButton({ action, className, icon }: { action: MenuItem; className: string; icon?: IconName }) {
	return (
		<button
			type="button"
			className={className}
			aria-label={icon && action.label}
			aria-disabled={action.disabledReason ? true : undefined}
			title={action.disabledReason ?? (icon && action.label)}
			onClick={action.disabledReason ? undefined : action.onSelect}
		>
			{icon ? <Icon name={icon} /> : action.label}
		</button>
	)
}

export function ItemDetail({ item, type, folders, webAppUrl, shareSave }: Props) {
	// The version the user opened; a later one arrived by sync.
	const [openedVersion] = useState(item.updatedAt)
	const dates: Array<[string, string]> = [[i18n.t('detail.created'), item.createdAt], [i18n.t('detail.updated'), item.updatedAt]]
	if (item.expiresAt) dates.push([i18n.t('detail.expires'), item.expiresAt])
	const { accountId, items, offline } = useShell()
	const [viewOnly, setViewOnly] = useState(false)
	// Until it answers, or when it fails, Edit stays: the edit fetch and the save check again.
	useEffect(() => {
		// Edit is unavailable for these already, so the answer would change nothing.
		if (item.blocked || item.useOnly || item.readOnly) return
		let live = true
		void request({ kind: 'item.editable', accountId, id: item.id }).then((reply) => {
			if (live && reply?.ok) setViewOnly(!reply.value.editable)
		})
		return () => {
			live = false
		}
	}, [accountId, item.id, item.blocked, item.useOnly, item.readOnly])
	const actions = itemActions(item, type, offline, items, viewOnly)
	return (
		<div className="stack">
			<header className="detail-head">
				<span className="item__icon item__icon--large"><Icon name={typeIcon(type?.name)} size={22} /></span>
				<div className="detail-head__text">
					<h2 className="detail-head__name">{item.name}</h2>
					<p className="detail-head__meta">{type?.label ?? i18n.t('detail.item')} · {folderPath(folders, item.folderId) ?? i18n.t('filters.noFolder')}</p>
				</div>
			</header>
			{shareSave && <ShareSaveNote note={shareSave} webAppUrl={webAppUrl} />}
			{item.blocked
				? (
					<Banner tone="error" role="status">
						{blockedText(item.blockedReason)}
						{item.migrationError && <><br />{item.migrationError}</>}
						<br /><a href={webAppUrl} target="_blank" rel="noreferrer">{i18n.t('common.openWebApp')}</a>
					</Banner>
				)
				// Keyed on the version too, so a sync that changes this item decrypts it again.
				: <Decrypted key={`${item.id}@${item.updatedAt}`} item={item} type={type} passive={item.updatedAt !== openedVersion} />}
			<section className="section">
				<h2 className="section__title">{i18n.t('detail.history')}</h2>
				<dl className="card fields meta">
					{dates.map(([label, iso]) => (
						<div key={label} className="field-row">
							<dt className="field-row__label">{label}</dt>
							<dd className="field-row__value">{dateTime(iso)}</dd>
						</div>
					))}
				</dl>
			</section>
			<PageFooter>
				<div className="detail-actions">
					<ActionButton action={actions.edit} className="btn btn--primary" />
					<Menu label={i18n.t('item.moreMenu', { name: item.name })} icon="more" above items={[actions.clone, actions.move]} />
					<ActionButton action={actions.remove} className="icon-btn icon-btn--danger" icon="trash" />
				</div>
			</PageFooter>
		</div>
	)
}
