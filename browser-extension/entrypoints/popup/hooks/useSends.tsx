import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'
import type { SendCreateInput, SendRow, VaultWriteError, WriteResult } from '@/src/messages'
import { useShell } from '../shell-context'
import { request } from './useMessage'

export interface Sends {
	/** `undefined` until listed, and after a failed refresh, so no stale list shows. */
	sends: SendRow[] | undefined
	failed: boolean
	/** Whether this browser can make a password send; assumed until the list says. */
	passwordProtection: boolean
	/** Lists on the first call only. */
	load: () => void
	refresh: () => Promise<void>
	/** After a failed list: shows loading again, so a repeated failure is announced anew. */
	retry: () => void
	/** The link to copy, or `undefined` when it is gone. */
	linkFor: (row: SendRow) => string | undefined
	/** `undefined` is the background not answering. */
	create: (input: SendCreateInput) => Promise<WriteResult<{ row: SendRow; link: string }> | undefined>
	remove: (id: string) => Promise<WriteResult<null> | undefined>
}

const SendContext = createContext<Sends | null>(null)

/**
 * Inside Shell, which is keyed per account and unmounts on lock, so the links of sends without a
 * password are gone with it; nothing here reaches storage.
 */
export function SendProvider({ children }: { children: ReactNode }) {
	const { accountId, refreshState } = useShell()
	const [sends, setSends] = useState<SendRow[]>()
	const [failed, setFailed] = useState(false)
	const [passwordProtection, setPasswordProtection] = useState(true)
	const [links, setLinks] = useState<ReadonlyMap<string, string>>(new Map())
	const loaded = useRef(false)

	/** A locked vault or revoked session shows its own screen instead. */
	const checked = useCallback(<T,>(reply: WriteResult<T> | undefined) => {
		const code: VaultWriteError['code'] | undefined = reply && !reply.ok ? reply.error.code : undefined
		if (code === 'locked' || code === 'session_revoked') refreshState()
		return reply
	}, [refreshState])

	const refresh = useCallback(async () => {
		const reply = checked(await request({ kind: 'send.list', accountId }))
		if (reply?.ok) {
			setSends(reply.value.sends)
			setPasswordProtection(reply.value.passwordProtection)
			setFailed(false)
		} else {
			setSends(undefined)
			setFailed(true)
		}
	}, [accountId, checked])

	const load = useCallback(() => {
		if (loaded.current) return
		loaded.current = true
		void refresh()
	}, [refresh])

	const retry = useCallback(() => {
		setFailed(false)
		void refresh()
	}, [refresh])

	const create = useCallback(async (input: SendCreateInput) => {
		const reply = checked(await request({ kind: 'send.create', accountId, input }))
		if (reply?.ok) {
			const { row, link } = reply.value
			if (!row.hasPassword) setLinks((current) => new Map(current).set(row.id, link))
			void refresh()
		}
		return reply
	}, [accountId, checked, refresh])

	const remove = useCallback(async (id: string) => {
		const reply = checked(await request({ kind: 'send.delete', accountId, id }))
		if (reply?.ok) {
			setLinks((current) => {
				const next = new Map(current)
				next.delete(id)
				return next
			})
			setSends((current) => current?.filter((row) => row.id !== id))
			void refresh()
		}
		return reply
	}, [accountId, checked, refresh])

	const linkFor = useCallback((row: SendRow) => row.link ?? links.get(row.id), [links])

	const value = useMemo<Sends>(
		() => ({ sends, failed, passwordProtection, load, refresh, retry, linkFor, create, remove }),
		[sends, failed, passwordProtection, load, refresh, retry, linkFor, create, remove],
	)
	return <SendContext.Provider value={value}>{children}</SendContext.Provider>
}

export function useSends(): Sends {
	const sends = useContext(SendContext)
	if (!sends) throw new Error('useSends outside SendProvider')
	return sends
}
