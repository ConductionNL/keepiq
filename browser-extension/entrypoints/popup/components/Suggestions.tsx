import type { ItemMeta, TypeMeta } from '@/src/messages'
import { loginKey } from '../hooks/useDecryptedFields'
import { ItemCard } from './ItemCard'

interface Props {
	host: string
	items: ItemMeta[]
	types: Map<string | null, TypeMeta>
	logins: Record<string, string>
	onOpen: (id: string) => void
}

/** Its own component because ext-autofill adds Fill to these cards. */
export function Suggestions({ host, items, types, logins, onOpen }: Props) {
	return (
		<section className="section" aria-labelledby="suggestions-title">
			<h2 className="section__title" id="suggestions-title">Autofill suggestions</h2>
			{items.length > 0
				? (
					<ul className="items">
						{items.map((item) => <ItemCard key={item.id} item={item} type={types.get(item.typeId)} login={logins[loginKey(item)]} onOpen={onOpen} />)}
					</ul>
				)
				: <p className="section__empty">No items for {host}</p>}
		</section>
	)
}
