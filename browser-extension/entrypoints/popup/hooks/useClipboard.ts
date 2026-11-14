import { useCallback } from 'react'
import { i18n } from '#i18n'
import { copyText } from '@/src/clipboard'
import { NativeOutcomeUnknown } from '@/src/safari-native'
import { useShell } from '../shell-context'
import { request } from './useMessage'

/** Copies and toasts "<what> copied"; the background then schedules the clear, unless the delay is Never. */
export function useClipboard() {
	const { toast } = useShell()
	return useCallback(async (text: string, what: string): Promise<void> => {
		let changeCount: number | undefined
		try {
			changeCount = await copyText(text)
		} catch (error) {
			toast(i18n.t('clipboard.failed'))
			// Safari's app may still write, so the background clears anyway.
			if (import.meta.env.BROWSER === 'safari' && error instanceof NativeOutcomeUnknown) void request({ kind: 'clipboard.copied' })
			return
		}
		toast(i18n.t('clipboard.copied', { what }))
		void request(changeCount === undefined ? { kind: 'clipboard.copied' } : { kind: 'clipboard.copied', changeCount })
	}, [toast])
}
