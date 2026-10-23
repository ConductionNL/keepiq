import { useCallback, useEffect, useRef, useState } from 'react'
import type { VaultSnapshotReply } from '@/src/messages'
import { useBackgroundMessage } from './useBackgroundMessage'
import { request } from './useMessage'

/** `reply` is `undefined` while loading; `failed` means the background did not answer. */
export function useVaultSnapshot(tabUrl: string | undefined, ready: boolean) {
	const [reply, setReply] = useState<VaultSnapshotReply>()
	const [failed, setFailed] = useState(false)
	const newest = useRef<Promise<VaultSnapshotReply | undefined>>(undefined)
	const opened = useRef(false)

	/**
	 * Only the newest read's reply is applied, whatever order replies arrive in. Each read returns the
	 * reply applied, so a read overtaken by a newer one answers with the newer one's.
	 */
	const load = useCallback((): Promise<VaultSnapshotReply | undefined> => {
		// The first read is the popup opening; later ones follow broadcasts and must not restart a failed sync.
		const first = !opened.current
		opened.current = true
		const read: Promise<VaultSnapshotReply | undefined> = request({ kind: 'vault.snapshot', tabUrl, opened: first }).then((next) => {
			if (newest.current !== read) return newest.current
			if (next) setReply(next)
			setFailed(!next)
			return next
		})
		newest.current = read
		return read
	}, [tabUrl])

	useEffect(() => {
		if (ready) void load()
	}, [ready, load])

	useBackgroundMessage('vault.changed', () => {
		if (opened.current) void load()
	})

	/** Resolves with the snapshot read after the sync, so its caller can tell how that sync ended. */
	const syncNow = useCallback(async () => {
		setReply((current) => current?.state === 'unlocked' ? { ...current, sync: { ...current.sync, syncing: true } } : current)
		await request({ kind: 'vault.sync' })
		return load()
	}, [load])

	/** `reload` re-reads without syncing, e.g. after a write whose reply already followed its sync. */
	return { reply, failed, syncNow, reload: load }
}
