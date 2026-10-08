import { useState, type ReactNode } from 'react'
import type { DecryptedItem, FolderMeta, ItemMeta, TypeMeta } from '@/src/messages'
import { typeIcon } from '@/src/vault/icons'
import { folderPath, launchUrl } from '@/src/vault/list'
import { cardBrand, cardLast4, parseObject, text } from '@/src/vault/payloads'
import { IconButton } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Icon } from '../components/Icon'
import { Loading } from '../components/Loading'
import { LATER } from '../components/ItemCard'
import { MaskedField } from '../components/MaskedField'
import { TotpCode } from '../components/TotpCode'
import { useDecryptedFields } from '../hooks/useDecryptedFields'
import { useShell } from '../shell-context'

interface Props {
	item: ItemMeta
	type: TypeMeta | undefined
	folders: FolderMeta[]
	webAppUrl: string
}

/** Types with their own section instead of Login credentials. */
const OWN_SECTION = new Set(['totp', 'card', 'identity', 'passkey', 'note'])

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
	if (!type || type.name === 'login' || type.name === 'database') return 'Password'
	return type.label
}

function Card({ payload }: { payload: Record<string, unknown> }) {
	const number = text(payload, 'number')
	const fields = [
		['Cardholder', text(payload, 'cardholder'), false],
		['Expiry', text(payload, 'expiry'), false],
		['CVV', text(payload, 'cvv'), true],
		['PIN', text(payload, 'pin'), true],
	] as const
	return (
		<Section title="Card details">
			{number && <MaskedField label={`Number · ${cardBrand(number)}`} what="Card number" value={number} masked maskedText={`•••• •••• •••• ${cardLast4(number)}`} />}
			{fields.map(([label, value, masked]) => value && <MaskedField key={label} label={label} value={value} masked={masked} />)}
		</Section>
	)
}

function Identity({ payload }: { payload: Record<string, unknown> }) {
	const fields = [
		['First name', text(payload, 'firstName'), false],
		['Last name', text(payload, 'lastName'), false],
		['Address', text(payload, 'address'), false],
		['Phone', text(payload, 'phone'), false],
		['Email', text(payload, 'email'), false],
		['BSN', text(payload, 'bsn'), true],
	] as const
	return (
		<Section title="Identity">
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
		<Section title="Passkey">
			<MaskedField label="Relying party" value={rpName ? `${rpName} (${rpId})` : rpId} copyable={false} />
			{user && <MaskedField label="User name" value={user} copyable={false} />}
			{created && <MaskedField label="Created" value={new Date(created).toLocaleString()} copyable={false} />}
			<p className="field-row field-row__note">Signing in with this passkey is not yet supported in the extension</p>
		</Section>
	)
}

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
	const payload = ['card', 'identity', 'passkey'].includes(name) ? parseObject(key) : null
	const launch = launchUrl(item.url)
	const shell = useShell()

	return (
		<>
			{!OWN_SECTION.has(name) && (
				<Section title="Login credentials">
					{values.login !== undefined && <MaskedField label="Username" value={values.login} />}
					<MaskedField label={secretLabel(type)} value={key} masked />
				</Section>
			)}
			{name === 'totp' && <Section title="Authenticator key"><TotpCode seed={key} /></Section>}
			{name === 'card' && (payload ? <Card payload={payload} /> : <Unreadable title="Card details" message="Could not read this item" />)}
			{name === 'identity' && (payload ? <Identity payload={payload} /> : <Unreadable title="Identity" message="Could not read this item" />)}
			{name === 'passkey' && (payload ? <Passkey payload={payload} /> : <Unreadable title="Passkey" message="Could not read this passkey" />)}
			{item.url && (
				<Section title="Website">
					<MaskedField
						label="Website"
						value={item.url}
						actions={launch && <IconButton icon="external" label={`Launch ${item.name}`} onClick={() => shell.launch(launch)} />}
					/>
				</Section>
			)}
			{extra === null
				? <Unreadable title="Additional fields" message="Could not read additional fields" />
				: fields.length > 0 && (
					<Section title="Additional fields">
						{fields.map(([field, value]) => <MaskedField key={field} label={field} value={value} masked />)}
					</Section>
				)}
			{notes.length > 0 && (
				<Section title="Notes">
					{notes.map((note, i) => <p key={i} className="field-row notes">{note}</p>)}
				</Section>
			)}
		</>
	)
}

function Decrypted({ item, type, passive }: { item: ItemMeta; type: TypeMeta | undefined; passive: boolean }) {
	const decrypted = useDecryptedFields(item.id, ['login', 'key', 'additionalFields'], true, passive)
	if (decrypted.status === 'loading') return <Loading>Decrypting…</Loading>
	if (decrypted.status === 'error') return <ErrorBanner>Could not decrypt this item</ErrorBanner>
	return <Values item={item} type={type} values={decrypted.item} />
}

export function ItemDetail({ item, type, folders, webAppUrl }: Props) {
	// The version the user opened; a later one arrived by sync.
	const [openedVersion] = useState(item.updatedAt)
	const dates: Array<[string, string]> = [['Created', item.createdAt], ['Updated', item.updatedAt]]
	if (item.expiresAt) dates.push(['Expires', item.expiresAt])
	return (
		<div className="stack">
			<header className="detail-head">
				<span className="item__icon item__icon--large"><Icon name={typeIcon(type?.name)} size={22} /></span>
				<div className="detail-head__text">
					<h2 className="detail-head__name">{item.name}</h2>
					<p className="detail-head__meta">{type?.label ?? 'Item'} · {folderPath(folders, item.folderId) ?? 'No folder'}</p>
				</div>
			</header>
			{item.blocked
				? (
					<Banner tone="error" role="status">
						{item.blockedReason ?? 'This item is blocked'}
						{item.migrationError && <><br />{item.migrationError}</>}
						<br /><a href={webAppUrl} target="_blank" rel="noreferrer">Open the Keepiq web app</a>
					</Banner>
				)
				// Keyed on the version too, so a sync that changes this item decrypts it again.
				: <Decrypted key={`${item.id}@${item.updatedAt}`} item={item} type={type} passive={item.updatedAt !== openedVersion} />}
			<section className="section">
				<h2 className="section__title">Item history</h2>
				<dl className="card fields meta">
					{dates.map(([label, iso]) => (
						<div key={label} className="field-row">
							<dt className="field-row__label">{label}</dt>
							<dd className="field-row__value">{new Date(iso).toLocaleString()}</dd>
						</div>
					))}
				</dl>
			</section>
			<div className="detail-actions">
				<button type="button" className="btn btn--primary" aria-disabled="true" title={LATER}>Edit</button>
				<button type="button" className="btn btn--danger" aria-disabled="true" title={LATER}>Delete</button>
			</div>
		</div>
	)
}
