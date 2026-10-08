import { useCallback, useEffect, useState } from 'react'
import type { PopupState, PopupToBackground, Result } from '@/src/messages'
import { sendMessage } from './useMessage'

export type Dispatch = (message: PopupToBackground) => Promise<Result>

/** The background-owned state; every successful reply replaces it. */
export function usePopupState(): { state: PopupState | null; error: string | null; dispatch: Dispatch } {
	const [state, setState] = useState<PopupState | null>(null)
	const [error, setError] = useState<string | null>(null)

	const dispatch = useCallback<Dispatch>(async (message) => {
		const result = await sendMessage(message)
		if (result.ok) setState(result.state)
		return result
	}, [])

	useEffect(() => {
		void dispatch({ kind: 'vault.status' }).then((result) => {
			if (!result.ok) setError(result.message)
		})
	}, [dispatch])

	return { state, error, dispatch }
}
