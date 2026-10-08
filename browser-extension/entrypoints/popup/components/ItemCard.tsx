import type { DecryptField, ItemMeta, TypeMeta } from '@/src/messages'
import { generateCode, parseTotpSeed } from '@/src/totp/totp'
import { typeIcon } from '@/src/vault/icons'
import { launchUrl } from '@/src/vault/list'
import { useClipboard } from '../hooks/useClipboard'
import { loginKey } from '../hooks/useDecryptedFields'
import { request } from '../hooks/useMessage'
import { useShell } from '../shell-context'
import { IconButton } from './Button'
import { Icon } from './Icon'
import { Menu, type MenuItem } from './Menu'

export const LATER = 'Available in a later update'

/** Types whose `key` is a structured payload or a seed, not something to paste as a password. */
const NO_PASSWORD = new Set(['totp', 'card', 'identity', 'passkey'])

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
		if (value === undefined) toast('Could not copy')
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
		if (code === undefined) toast('Could not copy')
		else await copy(code, 'Verification code')
	}

	const copyItems: MenuItem[] = []
	if (item.hasLogin) copyItems.push({ label: 'Copy username', onSelect: () => void copyField('login', 'Username') })
	if (!NO_PASSWORD.has(type?.name ?? '')) copyItems.push({ label: 'Copy password', onSelect: () => void copyField('key', 'Password') })
	if (type?.name === 'totp') copyItems.push({ label: 'Copy verification code', onSelect: () => void copyCode() })

	const subtitle = item.hasLogin ? login : type?.label

	return (
		<li className="item" data-lazy-login={item.hasLogin && !item.blocked ? loginKey(item) : undefined}>
			<button type="button" className="item__main" onClick={() => onOpen(item.id)}>
				<span className="item__icon"><Icon name={typeIcon(type?.name)} size={18} /></span>
				<span className="item__text">
					<span className="item__name">{item.name}</span>
					{item.blocked
						? <span className="badge"><Icon name="blocked" size={12} />Blocked</span>
						// A blank line holds the row's height until the login arrives.
						: <span className="item__sub">{subtitle ?? ' '}</span>}
				</span>
			</button>
			<div className="item__actions">
				{launch && <IconButton icon="external" label={`Launch ${item.name}`} onClick={() => shell.launch(launch)} />}
				<Menu label={`Copy, ${item.name}`} icon="copy" items={copyItems} disabled={item.blocked || copyItems.length === 0} />
				<Menu
					label={`More, ${item.name}`}
					icon="more"
					items={[
						{ label: 'View', onSelect: () => onOpen(item.id) },
						{ label: 'Edit', onSelect: () => {}, disabledReason: LATER },
						{ label: 'Clone', onSelect: () => {}, disabledReason: LATER },
						{ label: 'Move to folder', onSelect: () => {}, disabledReason: LATER },
						{ label: 'Delete', onSelect: () => {}, disabledReason: LATER },
					]}
				/>
			</div>
		</li>
	)
}
