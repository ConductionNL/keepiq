import { i18n } from '#i18n'
import type { ItemMeta, TypeMeta } from '@/src/messages'
import { loginKey } from '../hooks/useDecryptedFields'
import { ItemCard } from './ItemCard'

interface Props {
	/** `null` on a page that is not http(s), where autofill is not available. */
	host: string | null
	/** In the background's order: last used, then name. Never blocked. */
	items: ItemMeta[]
	types: Map<string | null, TypeMeta>
	logins: Record<string, string>
	onOpen: (id: string) => void
	onFill: (id: string) => void
}

export function Suggestions({ host, items, types, logins, onOpen, onFill }: Props) {
	let body
	if (host === null) body = <p className="section__empty">{i18n.t('vault.autofillUnavailable')}</p>
	else if (items.length === 0) body = <p className="section__empty">{i18n.t('vault.noSuggestions', { host })}</p>
	else {
		body = (
			<ul className="items">
				{items.map((item) => (
					<ItemCard key={item.id} item={item} type={types.get(item.typeId)} login={logins[loginKey(item)]} onOpen={onOpen} onFill={onFill} />
				))}
			</ul>
		)
	}
	return (
		<section className="section" aria-labelledby="suggestions-title">
			<h2 className="section__title" id="suggestions-title">{i18n.t('vault.suggestions')}</h2>
			{body}
		</section>
	)
}
