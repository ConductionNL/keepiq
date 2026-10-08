import { useCallback, useEffect, useRef, useState } from 'react'
import type { VaultSnapshotReply } from '@/src/messages'
import { useBackgroundMessage } from './useBackgroundMessage'
import { request } from './useMessage'

/** `reply` is `undefined` while loading; `failed` means the background did not answer. */
export function useVaultSnapshot(tabUrl: string | undefined, ready: boolean) {
	const [reply, setReply] = useState<VaultSnapshotReply>()
	const [failed, setFailed] = useState(false)
	const latest = useRef(0)
	const opened = useRef(false)

	/** Only the newest request's reply is applied, whatever order replies arrive in. */
	const load = useCallback(async () => {
		const ticket = ++latest.current
		// The first read is the popup opening; later ones follow broadcasts and must not restart a failed sync.
		const first = !opened.current
		opened.current = true
		const next = await request({ kind: 'vault.snapshot', tabUrl, opened: first })
		if (ticket !== latest.current) return
		if (next) setReply(next)
		setFailed(!next)
	}, [tabUrl])

	useEffect(() => {
		if (ready) void load()
	}, [ready, load])

	useBackgroundMessage('vault.changed', () => {
		if (opened.current) void load()
	})

	const syncNow = useCallback(async () => {
		setReply((current) => current?.state === 'unlocked' ? { ...current, sync: { ...current.sync, syncing: true } } : current)
		await request({ kind: 'vault.sync' })
		await load()
	}, [load])

	return { reply, failed, syncNow }
}
