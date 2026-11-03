import { createContext, createElement, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import type { GlobalSettings } from '@/src/messages'
import { followMirror, showAppearance, showTheme } from '../theme'
import { request } from './useMessage'

export interface SettingsState {
	/** `null` until the first read. */
	settings: GlobalSettings | null
	/** The background did not answer the last read or write, which left the stored values as they were. */
	failed: boolean
	update: (patch: Partial<GlobalSettings>) => Promise<void>
	/** Reads again, e.g. when a view needs the values after a failed read. */
	retry: () => Promise<void>
}

function show(settings: GlobalSettings) {
	showTheme(settings.theme)
	showAppearance(settings)
}

/** Reads once and keeps every screen, those before an account is chosen included, in the stored theme and appearance. */
export function useGlobalSettings(): SettingsState {
	const [settings, setSettings] = useState<GlobalSettings | null>(null)
	const [failed, setFailed] = useState(false)

	useEffect(() => {
		let live = true
		void request({ kind: 'settings.get' }).then((reply) => {
			if (!live) return
			if (reply) {
				setSettings(reply)
				show(reply)
			} else {
				setFailed(true)
			}
		})
		window.addEventListener('storage', followMirror)
		return () => {
			live = false
			window.removeEventListener('storage', followMirror)
		}
	}, [])

	const retry = useCallback(async () => {
		const reply = await request({ kind: 'settings.get' })
		setFailed(!reply)
		if (!reply) return
		setSettings(reply)
		show(reply)
	}, [])

	const update = useCallback(async (patch: Partial<GlobalSettings>) => {
		const reply = await request({ kind: 'settings.set', patch })
		setFailed(!reply)
		if (!reply) return
		setSettings(reply)
		show(reply)
	}, [])

	return useMemo(() => ({ settings, failed, update, retry }), [settings, failed, update, retry])
}

const SettingsContext = createContext<SettingsState>({ settings: null, failed: false, update: async () => {}, retry: async () => {} })

/** One read for the whole popup, so a change in Appearance reaches the vault list's cards. */
export function SettingsProvider({ children }: { children: ReactNode }) {
	return createElement(SettingsContext.Provider, { value: useGlobalSettings() }, children)
}

export const useSettings = () => useContext(SettingsContext)
