import { useCallback, useEffect, useState } from 'react'
import type { GlobalSettings } from '@/src/messages'
import { showTheme } from '../theme'
import { request } from './useMessage'

/** `failed`: the background did not answer the last read or write, which left the stored values as they were. */
export function useSettings() {
	const [settings, setSettings] = useState<GlobalSettings | null>(null)
	const [failed, setFailed] = useState(false)

	useEffect(() => {
		let live = true
		void request({ kind: 'settings.get' }).then((reply) => {
			if (!live) return
			if (reply) setSettings(reply)
			else setFailed(true)
		})
		return () => {
			live = false
		}
	}, [])

	const update = useCallback(async (patch: Partial<GlobalSettings>) => {
		const reply = await request({ kind: 'settings.set', patch })
		setFailed(!reply)
		if (!reply) return
		setSettings(reply)
		showTheme(reply.theme)
	}, [])

	return { settings, failed, update }
}
