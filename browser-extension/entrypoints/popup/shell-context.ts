import { createContext, useContext } from 'react'

export interface ShellContextValue {
	/** Re-reads the popup state, e.g. after the background reported the vault locked. */
	refreshState: () => void
	toast: (message: string) => void
	/** Opens a launchable url in a new tab. */
	launch: (url: string) => void
}

export const ShellContext = createContext<ShellContextValue>({ refreshState: () => {}, toast: () => {}, launch: () => {} })

export const useShell = () => useContext(ShellContext)
