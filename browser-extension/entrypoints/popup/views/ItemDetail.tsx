import { useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import type { DecryptedItem, FolderMeta, ItemMeta, TypeMeta } from '@/src/messages'
import { typeIcon } from '@/src/vault/icons'
import { folderPath, launchUrl } from '@/src/vault/list'
import { cardBrand, cardLast4, parseObject, text } from '@/src/vault/payloads'
import { IconButton } from '../components/Button'
import { Banner, ErrorBanner } from '../components/ErrorBanner'
import { Icon } from '../components/Icon'
import { Loading } from '../components/Loading'
import { MaskedField } from '../components/MaskedField'
import { TotpCode } from '../components/TotpCode'
import { blockedText } from '../errors'
import { useDecryptedFields } from '../hooks/useDecryptedFields'
import { language } from '../i18n'
import { useShell } from '../shell-context'

interface Props {
	item: ItemMeta
	type: TypeMeta | undefined
	folders: FolderMeta[]
	webAppUrl: string
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
				<Section title={i18n.t('detail.loginCredentials')}>
					{values.login !== undefined && <MaskedField label={i18n.t('detail.username')} value={values.login} />}
					<MaskedField label={secretLabel(type)} value={key} masked />
				</Section>
			)}
			{name === 'totp' && <Section title={i18n.t('detail.authenticatorKey')}><TotpCode seed={key} /></Section>}
			{name === 'card' && (payload ? <Card payload={payload} /> : <Unreadable title={i18n.t('detail.cardDetails')} message={i18n.t('detail.unreadable')} />)}
			{name === 'identity' && (payload ? <Identity payload={payload} /> : <Unreadable title={i18n.t('detail.identity')} message={i18n.t('detail.unreadable')} />)}
			{name === 'passkey' && (payload ? <Passkey payload={payload} /> : <Unreadable title={i18n.t('detail.passkey')} message={i18n.t('detail.unreadablePasskey')} />)}
			{item.url && (
				<Section title={i18n.t('detail.website')}>
					<MaskedField
						label={i18n.t('detail.website')}
						value={item.url}
						actions={launch && <IconButton icon="external" label={i18n.t('item.launch', { name: item.name })} onClick={() => shell.launch(launch)} />}
					/>
				</Section>
			)}
			{extra === null
				? <Unreadable title={i18n.t('detail.additionalFields')} message={i18n.t('detail.unreadableFields')} />
				: fields.length > 0 && (
					<Section title={i18n.t('detail.additionalFields')}>
						{fields.map(([field, value]) => <MaskedField key={field} label={field} value={value} masked />)}
					</Section>
				)}
			{notes.length > 0 && (
				<Section title={i18n.t('detail.notes')}>
					{notes.map((note, i) => <p key={i} className="field-row notes">{note}</p>)}
				</Section>
			)}
		</>
	)
}

function Decrypted({ item, type, passive }: { item: ItemMeta; type: TypeMeta | undefined; passive: boolean }) {
	const decrypted = useDecryptedFields(item.id, ['login', 'key', 'additionalFields'], true, passive)
	if (decrypted.status === 'loading') return <Loading>{i18n.t('detail.decrypting')}</Loading>
	if (decrypted.status === 'error') return <ErrorBanner>{i18n.t('detail.decryptFailed')}</ErrorBanner>
	return <Values item={item} type={type} values={decrypted.item} />
}

export function ItemDetail({ item, type, folders, webAppUrl }: Props) {
	// The version the user opened; a later one arrived by sync.
	const [openedVersion] = useState(item.updatedAt)
	const dates: Array<[string, string]> = [[i18n.t('detail.created'), item.createdAt], [i18n.t('detail.updated'), item.updatedAt]]
	if (item.expiresAt) dates.push([i18n.t('detail.expires'), item.expiresAt])
	const later = i18n.t('common.later')
	return (
		<div className="stack">
			<header className="detail-head">
				<span className="item__icon item__icon--large"><Icon name={typeIcon(type?.name)} size={22} /></span>
				<div className="detail-head__text">
					<h2 className="detail-head__name">{item.name}</h2>
					<p className="detail-head__meta">{type?.label ?? i18n.t('detail.item')} · {folderPath(folders, item.folderId) ?? i18n.t('filters.noFolder')}</p>
				</div>
			</header>
			{item.blocked
				? (
					<Banner tone="error" role="status">
						{item.blockedReason ? blockedText(item.blockedReason) : i18n.t('blocked.generic')}
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
			<div className="detail-actions">
				<button type="button" className="btn btn--primary" aria-disabled="true" title={later}>{i18n.t('item.edit')}</button>
				<button type="button" className="btn btn--danger" aria-disabled="true" title={later}>{i18n.t('item.delete')}</button>
			</div>
		</div>
	)
}
