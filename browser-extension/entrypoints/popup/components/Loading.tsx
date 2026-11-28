import { useEffect, useState } from 'react'
import { i18n } from '#i18n'

/** Most replies take a few milliseconds; a spinner for those only flashes. */
export const LOADING_DELAY_MS = 300

export function Loading({ children = i18n.t('common.loading') }: { children?: string }) {
	const [shown, setShown] = useState(false)
	useEffect(() => {
		const timer = setTimeout(() => setShown(true), LOADING_DELAY_MS)
		return () => clearTimeout(timer)
	}, [])
	if (!shown) return null
	return <p className="loading" role="status"><span className="spinner" aria-hidden="true" />{children}</p>
}
