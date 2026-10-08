import { useCallback, useEffect, useRef, useState } from 'react'
import type { DecryptField, DecryptReply, DecryptedItem } from '@/src/messages'
import { request } from './useMessage'
import { useShell } from '../shell-context'

/** Plaintext lives in this hook's state only, so it goes when the component unmounts. */
export type Decrypted = { status: 'loading' } | { status: 'ready'; item: DecryptedItem } | { status: 'error'; error: Exclude<DecryptReply, { ok: true }>['error'] | 'no_reply' }

/**
 * Decrypts one item on mount; `enabled: false` (a blocked row) sends nothing.
 * `passive` marks a re-decrypt the user did not ask for, e.g. after a sync changed the item.
 */
export function useDecryptedFields(id: string, fields: DecryptField[], enabled = true, passive = false): Decrypted {
	const { refreshState } = useShell()
	const [result, setResult] = useState<Decrypted>({ status: 'loading' })
	const wanted = fields.join(',')

	useEffect(() => {
		if (!enabled) return
		let live = true
		void request({ kind: 'item.decrypt', ids: [id], fields: wanted.split(',') as DecryptField[], passive }).then((reply) => {
			if (!live) return
			if (!reply) return setResult({ status: 'error', error: 'no_reply' })
			if (!reply.ok) {
				if (reply.error === 'locked') refreshState()
				return setResult({ status: 'error', error: reply.error })
			}
			setResult({ status: 'ready', item: reply.items[id] ?? {} })
		})
		return () => {
			live = false
		}
	}, [id, wanted, enabled, passive, refreshState])

	return result
}

const BATCH = 20
const RETRY_MS = 2_000
const MAX_ATTEMPTS = 3

/** A row's login is cached per version, so one that changed elsewhere is decrypted again after a sync. */
export const loginKey = (item: { id: string; updatedAt: string }) => `${item.id}@${item.updatedAt}`
const idOf = (key: string) => key.slice(0, key.lastIndexOf('@'))

/**
 * List subtitles: logins are decrypted in batches of 20 as their rows scroll into view,
 * and kept for the popup's lifetime. Rows opt in with `data-lazy-login={loginKey(item)}`;
 * the result is keyed the same way.
 */
export function useLazyLogins(container: React.RefObject<HTMLElement | null>, renderKey: unknown): Record<string, string> {
	const { refreshState } = useShell()
	const [logins, setLogins] = useState<Record<string, string>>({})
	// Bumped to observe again the rows of a batch that got no reply.
	const [retry, setRetry] = useState(0)
	const requested = useRef(new Set<string>())
	const attempts = useRef(new Map<string, number>())
	const queue = useRef<string[]>([])
	const scheduled = useRef(false)

	const flush = useCallback(async () => {
		scheduled.current = false
		while (queue.current.length > 0) {
			const keys = queue.current.splice(0, BATCH)
			// Rows decrypting as they scroll in or after a sync are not the user's doing.
			const reply = await request({ kind: 'item.decrypt', ids: keys.map(idOf), fields: ['login'], passive: true })
			if (reply?.ok) {
				const found = keys.flatMap((key) => {
					const login = reply.items[idOf(key)]?.login
					return login === undefined ? [] : [[key, login] as const]
				})
				setLogins((current) => ({ ...current, ...Object.fromEntries(found) }))
			} else if (reply?.error === 'locked') {
				refreshState()
				return
			} else if (!reply) {
				const again = keys.filter((key) => (attempts.current.get(key) ?? 0) < MAX_ATTEMPTS)
				again.forEach((key) => requested.current.delete(key))
				if (again.length > 0) setTimeout(() => setRetry((n) => n + 1), RETRY_MS)
			}
		}
	}, [refreshState])

	const want = useCallback((key: string) => {
		if (requested.current.has(key)) return
		requested.current.add(key)
		attempts.current.set(key, (attempts.current.get(key) ?? 0) + 1)
		queue.current.push(key)
		if (!scheduled.current) {
			scheduled.current = true
			queueMicrotask(() => void flush())
		}
	}, [flush])

	useEffect(() => {
		const rows = container.current?.querySelectorAll<HTMLElement>('[data-lazy-login]') ?? []
		if (typeof IntersectionObserver === 'undefined') {
			rows.forEach((row) => want(row.dataset.lazyLogin!))
			return
		}
		const observer = new IntersectionObserver((entries) => {
			for (const entry of entries) {
				if (!entry.isIntersecting) continue
				want((entry.target as HTMLElement).dataset.lazyLogin!)
				observer.unobserve(entry.target)
			}
		})
		rows.forEach((row) => observer.observe(row))
		return () => observer.disconnect()
	}, [container, renderKey, retry, want])

	return logins
}
