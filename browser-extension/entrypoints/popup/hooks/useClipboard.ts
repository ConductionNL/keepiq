import { useCallback } from 'react'
import { i18n } from '#i18n'
import { copyText } from '@/src/clipboard'
import { useShell } from '../shell-context'
import { request } from './useMessage'

/** Copies and toasts "<what> copied"; the background then schedules the clear when one is configured. */
export function useClipboard() {
	const { toast } = useShell()
	return useCallback(async (text: string, what: string): Promise<void> => {
		try {
			await copyText(text)
		} catch {
			toast(i18n.t('clipboard.failed'))
			return
		}
		toast(i18n.t('clipboard.copied', { what }))
		void request({ kind: 'clipboard.copied' })
	}, [toast])
}
