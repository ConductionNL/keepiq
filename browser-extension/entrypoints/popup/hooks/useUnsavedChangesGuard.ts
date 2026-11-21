import { useEffect, useRef } from 'react'
import { useShell } from '../shell-context'

/** While mounted, Shell asks "Discard them?" before Back, Pop out or the account switcher drops a dirty form. Closing the popup still loses it. */
export function useUnsavedChangesGuard(dirty: boolean): void {
	const { setLeaveGuard } = useShell()
	const latest = useRef(dirty)
	useEffect(() => {
		latest.current = dirty
	})
	useEffect(() => {
		setLeaveGuard(() => latest.current)
		return () => setLeaveGuard(null)
	}, [setLeaveGuard])
}
