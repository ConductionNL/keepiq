import { useCallback, useRef, useState, type ReactNode } from 'react'
import { i18n } from '#i18n'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { useShell } from '../shell-context'
import { isPopout, type CurrentTab } from './useCurrentTab'
import { request } from './useMessage'

/**
 * `fill(itemId)` fills the tab and closes the popup, but not a popout; `dialog` is the insecure-page question, for the caller to render.
 * The tab rides along because in a popout the active tab is the popout itself.
 */
export function useFill(tab: CurrentTab | undefined): { fill: (itemId: string) => void; dialog: ReactNode } {
	const { toast, refreshState } = useShell()
	const [insecure, setInsecure] = useState<string | null>(null)
	// A ref, not a disabled button: a focused button that becomes disabled drops focus to `<body>`.
	const inFlight = useRef(false)
	const tabId = tab?.id

	const send = useCallback(async (itemId: string, confirmedInsecure: boolean) => {
		if (inFlight.current) return
		inFlight.current = true
		try {
			const reply = await request({ kind: 'fill.request', itemId, tabId, ...(confirmedInsecure && { confirmedInsecure }) })
			if (reply?.needsInsecureConfirm) return setInsecure(itemId)
			// A second-step page fills only the code.
			if (reply && (reply.filled !== 'none' || reply.code)) {
				if (!isPopout) window.close()
				return
			}
			toast(i18n.t('vault.fillFailed'))
			// The reply can't say "locked"; a re-read shows Unlock if that was why.
			refreshState()
		} finally {
			inFlight.current = false
		}
	}, [tabId, toast, refreshState])

	const fill = useCallback((itemId: string) => void send(itemId, false), [send])

	const dialog = insecure !== null && (
		<ConfirmDialog
			title={i18n.t('item.insecureTitle')}
			actions={[{
				label: i18n.t('item.fill'),
				onClick: () => {
					setInsecure(null)
					void send(insecure, true)
				},
			}]}
			onCancel={() => setInsecure(null)}
			focusCancel
			describeBody
		>
			<p>{i18n.t('item.insecureWarning')}</p>
		</ConfirmDialog>
	)

	return { fill, dialog }
}
