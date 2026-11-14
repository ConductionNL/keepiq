import { useId } from 'react'
import { i18n } from '#i18n'
import type { DecryptField, ItemMeta, TypeMeta } from '@/src/messages'
import { generateCode, parseTotpSeed } from '@/src/totp/totp'
import { typeIcon } from '@/src/vault/icons'
import { launchUrl } from '@/src/vault/list'
import { useClipboard } from '../hooks/useClipboard'
import { language } from '../i18n'
import { loginKey } from '../hooks/useDecryptedFields'
import { request } from '../hooks/useMessage'
import { useSettings } from '../hooks/useSettings'
import { useShell, type ItemActions } from '../shell-context'
import { IconButton } from './Button'
import { Icon } from './Icon'
import { Menu, type MenuItem } from './Menu'

/** Types whose `key` is a structured payload or a seed, not something to paste as a password. */
const NO_PASSWORD = new Set(['totp', 'card', 'identity', 'passkey'])

/** What `itemActions` needs. `viewOnly`: the detail's `item.editable` answer, which the list does not ask. */
interface ActionsRequest {
	item: ItemMeta
	type: TypeMeta | undefined
	offline: boolean
	actions: ItemActions
	viewOnly?: boolean
}

type Reasons = ReturnType<typeof refusalReasons>

/** The date access to a shared copy ends, as the user reads dates. */
function accessEndDate(item: ItemMeta) {
	return item.accessExpiresAt ? new Date(item.accessExpiresAt).toLocaleDateString(language()) : undefined
}

/** The share's limits, which the background enforces too (Keepiq `use-only-shares`, `expiring-shares`). */
function shareReasons(item: ItemMeta) {
	const accessEnds = accessEndDate(item)
	return {
		blocked: item.blocked ? i18n.t('blocked.generic') : undefined,
		useOnly: item.useOnly ? i18n.t('item.useOnly') : undefined,
		readOnly: item.readOnly ? i18n.t('item.readOnly') : undefined,
		accessEnds,
		expiring: accessEnds && i18n.t('item.expiringNoClone', { date: accessEnds }),
	}
}

/** Every reason an action can be unavailable, each `undefined` where it does not apply. */
function refusalReasons({ item, type, offline, viewOnly }: ActionsRequest) {
	return {
		offline: offline ? i18n.t('form.offline') : undefined,
		passkey: type?.name === 'passkey' ? i18n.t('item.passkeyNoClone') : undefined,
		...shareReasons(item),
		viewOnly: viewOnly ? i18n.t('item.viewOnly') : undefined,
	}
}

/** Blocked, use-only or read-only: what keeps an item from being changed. */
function changeRefusal(reasons: Reasons) {
	return reasons.blocked ?? reasons.useOnly ?? reasons.readOnly
}

function cloneRefusal(reasons: Reasons) {
	return reasons.passkey ?? changeRefusal(reasons) ?? reasons.expiring ?? reasons.offline
}

function sendExpiry(accessEnds: string | undefined) {
	return accessEnds && i18n.t('item.expiringNoSend', { date: accessEnds })
}

function sendOffline(offline: boolean) {
	return offline ? i18n.t('send.offline') : undefined
}

/** A use-only copy's password can't be revealed, and an expiring one would outlive the access in a send. */
function sendRefusal(reasons: Reasons, offline: boolean) {
	return reasons.blocked ?? reasons.useOnly ?? sendExpiry(reasons.accessEnds) ?? sendOffline(offline)
}

/** Send is for logins only. */
function sendAction(request: ActionsRequest, reasons: Reasons): MenuItem | undefined {
	if (request.type?.name !== 'login') return undefined
	const { item, actions, offline } = request
	return { label: i18n.t('item.send'), onSelect: () => actions.send(item), disabledReason: sendRefusal(reasons, offline) }
}

/** Edit, Clone, Move, Send and Delete with the reason each is unavailable, for the More menu and item detail alike. */
export function itemActions(request: ActionsRequest) {
	const { item, actions } = request
	const reasons = refusalReasons(request)
	return {
		// Edit reads the item first and refuses there when offline, so it is not refused for that up front.
		edit: { label: i18n.t('item.edit'), onSelect: () => actions.edit(item.id), disabledReason: changeRefusal(reasons) ?? reasons.viewOnly },
		clone: { label: i18n.t('item.clone'), onSelect: () => actions.clone(item.id), disabledReason: cloneRefusal(reasons) },
		move: { label: i18n.t('item.move'), onSelect: () => actions.move(item), disabledReason: reasons.useOnly ?? reasons.offline },
		remove: { label: i18n.t('item.delete'), onSelect: () => actions.remove(item), disabledReason: reasons.offline },
		send: sendAction(request, reasons),
	} satisfies Record<string, MenuItem | undefined>
}

interface Props {
	item: ItemMeta
	type: TypeMeta | undefined
	/** The decrypted login once its row was in view. */
	login: string | undefined
	onOpen: (id: string) => void
	/** Set in Autofill suggestions: the card click fills instead of opening. */
	onFill?: (id: string) => void
}

export function ItemCard({ item, type, login, onOpen, onFill }: Props) {
	const fillId = useId()
	const copy = useClipboard()
	const shell = useShell()
	const quickCopy = useSettings().settings?.quickCopy !== false
	const { toast, refreshState } = shell
	const launch = launchUrl(item.url)

	async function decrypted(field: DecryptField): Promise<string | undefined> {
		const reply = await request({ kind: 'item.decrypt', ids: [item.id], fields: [field] })
		if (reply?.ok) return reply.items[item.id]?.[field]
		if (reply?.error === 'locked') refreshState()
		return undefined
	}

	async function copyField(field: DecryptField, what: string) {
		const value = await decrypted(field)
		if (value === undefined) toast(i18n.t('clipboard.failed'))
		else await copy(value, what)
	}

	async function copyCode() {
		const seed = await decrypted('key')
		let code: string | undefined
		try {
			code = seed === undefined ? undefined : await generateCode(parseTotpSeed(seed))
		} catch {
			code = undefined
		}
		if (code === undefined) toast(i18n.t('clipboard.failed'))
		else await copy(code, i18n.t('totp.code'))
	}

	const copyItems: MenuItem[] = []
	if (item.hasLogin) copyItems.push({ label: i18n.t('item.copyUsername'), onSelect: () => void copyField('login', i18n.t('detail.username')) })
	if (!item.useOnly && !NO_PASSWORD.has(type?.name ?? '')) copyItems.push({ label: i18n.t('item.copyPassword'), onSelect: () => void copyField('key', i18n.t('detail.password')) })
	if (!item.useOnly && type?.name === 'totp') copyItems.push({ label: i18n.t('item.copyCode'), onSelect: () => void copyCode() })
	const actions = itemActions({ item, type, offline: shell.offline, actions: shell.items })

	const subtitle = item.hasLogin ? login : type?.label

	return (
		<li className="item" data-lazy-login={item.hasLogin && !item.blocked ? loginKey(item) : undefined}>
			<button type="button" className="item__main" aria-describedby={onFill ? fillId : undefined} onClick={() => (onFill ?? onOpen)(item.id)}>
				<span className="item__icon"><Icon name={typeIcon(type?.name)} size={18} /></span>
				<span className="item__text">
					<span className="item__name">{item.name}</span>
					{item.blocked
						? <span className="badge"><Icon name="blocked" size={12} />{i18n.t('item.blocked')}</span>
						// A blank line holds the row's height until the login arrives.
						: <span className="item__sub">{subtitle ?? ' '}</span>}
				</span>
			</button>
			<div className="item__actions">
				{onFill && (
					<button type="button" id={fillId} className="btn btn--secondary item__fill" aria-label={i18n.t('item.fillName', { name: item.name })} onClick={() => onFill(item.id)}>
						{i18n.t('item.fill')}
					</button>
				)}
				{launch && <IconButton icon="external" label={i18n.t('item.launch', { name: item.name })} onClick={() => shell.launch(launch)} />}
				{quickCopy && <Menu label={i18n.t('item.copyMenu', { name: item.name })} icon="copy" items={copyItems} disabled={item.blocked || copyItems.length === 0} />}
				<Menu
					label={i18n.t('item.moreMenu', { name: item.name })}
					icon="more"
					items={[
						{ label: i18n.t('item.view'), onSelect: () => onOpen(item.id) },
						actions.edit,
						actions.clone,
						actions.move,
						...(actions.send ? [actions.send] : []),
						actions.remove,
					]}
				/>
			</div>
		</li>
	)
}
