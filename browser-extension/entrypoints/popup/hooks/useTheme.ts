import { useEffect } from 'react'
import { followMirror, showTheme } from '../theme'
import { request } from './useMessage'

/** Keeps every screen in the stored theme, including those before an account is chosen. */
export function useTheme() {
	useEffect(() => {
		let live = true
		void request({ kind: 'settings.get' }).then((settings) => {
			if (live && settings) showTheme(settings.theme)
		})
		window.addEventListener('storage', followMirror)
		return () => {
			live = false
			window.removeEventListener('storage', followMirror)
		}
	}, [])
}
