import type { ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useShell } from '../shell-context'

/** A page's actions, in the bar the tab bar leaves when the page opens over it. */
export function PageFooter({ children }: { children: ReactNode }) {
	const { footer } = useShell()
	return footer ? createPortal(children, footer) : null
}
