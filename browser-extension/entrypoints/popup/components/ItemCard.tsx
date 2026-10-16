import { i18n } from '#i18n'
import type { DecryptField, ItemMeta, TypeMeta } from '@/src/messages'
import { generateCode, parseTotpSeed } from '@/src/totp/totp'
import { typeIcon } from '@/src/vault/icons'
import { launchUrl } from '@/src/vault/list'
import { useClipboard } from '../hooks/useClipboard'
import { language } from '../i18n'
import { loginKey } from '../hooks/useDecryptedFields'
import { request } from '../hooks/useMessage'
import { useShell, type ItemActions } from '../shell-context'
import { IconButton } from './Button'
import { Icon } from './Icon'
import { Menu, type MenuItem } from './Menu'

/** Types whose `key` is a structured payload or a seed, not something to paste as a password. */
const NO_PASSWORD = new Set(['totp', 'card', 'identity', 'passkey'])

/** Edit, Clone, Move and Delete with the reason each is unavailable, for the More menu and item detail alike. `viewOnly`: the detail's `item.editable` answer, which the list does not ask. */
export function itemActions(item: ItemMeta, type: TypeMeta | undefined, offline: boolean, actions: ItemActions, viewOnly = false) {
	const offlineReason = offline ? i18n.t('form.offline') : undefined
	const blockedReason = item.blocked ? i18n.t('blocked.generic') : undefined
	const passkeyReason = type?.name === 'passkey' ? i18n.t('item.passkeyNoClone') : undefined
	// The share's limits, which the background enforces too (Keepiq `use-only-shares`, `expiring-shares`).
	const useOnly = item.useOnly ? i18n.t('item.useOnly') : undefined
	const readOnly = item.readOnly ? i18n.t('item.readOnly') : undefined
	const viewOnlyReason = viewOnly ? i18n.t('item.viewOnly') : undefined
	const expiring = item.accessExpiresAt ? i18n.t('item.expiringNoClone', { date: new Date(item.accessExpiresAt).toLocaleDateString(language()) }) : undefined
	return {
		// Edit reads the item first and refuses there when offline, so it is not refused for that up front.
		edit: { label: i18n.t('item.edit'), onSelect: () => actions.edit(item.id), disabledReason: blockedReason ?? useOnly ?? readOnly ?? viewOnlyReason },
		clone: { label: i18n.t('item.clone'), onSelect: () => actions.clone(item.id), disabledReason: passkeyReason ?? blockedReason ?? useOnly ?? readOnly ?? expiring ?? offlineReason },
		move: { label: i18n.t('item.move'), onSelect: () => actions.move(item), disabledReason: useOnly ?? offlineReason },
		remove: { label: i18n.t('item.delete'), onSelect: () => actions.remove(item), disabledReason: offlineReason },
	} satisfies Record<string, MenuItem>
}

interface Props {
	item: ItemMeta
	type: TypeMeta | undefined
	/** The decrypted login once its row was in view. */
	login: string | undefined
	onOpen: (id: string) => void
}

export function ItemCard({ item, type, login, onOpen }: Props) {
	const copy = useClipboard()
	const shell = useShell()
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
	const actions = itemActions(item, type, shell.offline, shell.items)

	const subtitle = item.hasLogin ? login : type?.label

	return (
		<li className="item" data-lazy-login={item.hasLogin && !item.blocked ? loginKey(item) : undefined}>
			<button type="button" className="item__main" onClick={() => onOpen(item.id)}>
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
				{launch && <IconButton icon="external" label={i18n.t('item.launch', { name: item.name })} onClick={() => shell.launch(launch)} />}
				<Menu label={i18n.t('item.copyMenu', { name: item.name })} icon="copy" items={copyItems} disabled={item.blocked || copyItems.length === 0} />
				<Menu
					label={i18n.t('item.moreMenu', { name: item.name })}
					icon="more"
					items={[
						{ label: i18n.t('item.view'), onSelect: () => onOpen(item.id) },
						actions.edit,
						actions.clone,
						actions.move,
						actions.remove,
					]}
				/>
			</div>
		</li>
	)
}
