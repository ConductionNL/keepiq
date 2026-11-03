import { useCallback, useEffect, useRef, useState } from 'react'
import type { AccountSettings } from '@/src/accounts/settings'
import type { AccountSettingsSnapshot } from '@/src/messages'
import { useShell } from '../shell-context'
import { request } from './useMessage'

/**
 * The shown account's settings. `snapshot` is `undefined` while loading; `failed` means the background did
 * not answer or no longer knows the account, which leaves the stored values as they were.
 */
export function useAccountSettings() {
	const { accountId, refreshState } = useShell()
	const [snapshot, setSnapshot] = useState<AccountSettingsSnapshot>()
	const [failed, setFailed] = useState(false)
	const live = useRef(true)

	const apply = useCallback((reply: AccountSettingsSnapshot | 'locked' | null | undefined): boolean => {
		if (!live.current) return false
		// Locked or gone in another window: the popup state moves this view away.
		if (reply === 'locked' || reply === null) refreshState()
		const ok = typeof reply === 'object' && reply !== null
		setFailed(!ok)
		if (ok) setSnapshot(reply)
		return ok
	}, [refreshState])

	const reload = useCallback(async () => {
		apply(await request({ kind: 'account.settings.get', accountId }))
	}, [accountId, apply])

	useEffect(() => {
		live.current = true
		void reload()
		return () => {
			live.current = false
		}
	}, [reload])

	/** Resolves whether the patch was stored. */
	const update = useCallback(async (patch: Partial<AccountSettings>) => apply(await request({ kind: 'account.settings.set', accountId, patch })), [accountId, apply])

	return { snapshot, failed, update, reload }
}
