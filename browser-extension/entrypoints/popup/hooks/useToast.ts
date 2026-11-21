import { useCallback, useEffect, useState } from 'react'

const TOAST_MS = 2500

/** The message a `Toast` shows, cleared after a while. */
export function useToast(): { message: string | null; show: (text: string) => void } {
	// A fresh object per call, so the same message twice restarts the timer.
	const [toast, setToast] = useState<{ text: string } | null>(null)

	useEffect(() => {
		if (!toast) return
		const timer = setTimeout(() => setToast(null), TOAST_MS)
		return () => clearTimeout(timer)
	}, [toast])

	const show = useCallback((text: string) => setToast({ text }), [])
	return { message: toast?.text ?? null, show }
}
