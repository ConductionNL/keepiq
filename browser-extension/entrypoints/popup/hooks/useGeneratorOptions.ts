import { useCallback, useEffect, useRef, useState } from 'react'
import type { GeneratorOptions } from '@/src/generator/options'
import type { GeneratorContext } from '@/src/messages'
import { useShell } from '../shell-context'
import { useBackgroundMessage } from './useBackgroundMessage'
import { request } from './useMessage'

/** A range input sends a change per step; one save after it settles is enough. */
export const SAVE_DELAY_MS = 300

/** The stored options, shown at once on change; `failed`: the background did not answer. */
export function useGeneratorOptions() {
	const { accountId } = useShell()
	const [context, setContext] = useState<GeneratorContext | null>(null)
	const [failed, setFailed] = useState(false)
	const latest = useRef<GeneratorOptions | null>(null)
	const pending = useRef<GeneratorOptions | null>(null)
	const timer = useRef<ReturnType<typeof setTimeout>>(undefined)
	const saves = useRef(0)

	useEffect(() => {
		let live = true
		void request({ kind: 'generator.context', accountId }).then((reply) => {
			if (!live) return
			if (!reply) return setFailed(true)
			latest.current = reply.options
			setContext(reply)
		})
		return () => {
			live = false
		}
	}, [accountId])

	// A sync may bring a new policy or email; the options on screen stay the user's.
	useBackgroundMessage('vault.changed', () => {
		void request({ kind: 'generator.context', accountId }).then((reply) => {
			if (reply) setContext((current) => current && { ...current, policy: reply.policy, accountEmail: reply.accountEmail })
		})
	})

	const save = useCallback(async () => {
		clearTimeout(timer.current)
		const options = pending.current
		pending.current = null
		if (!options) return
		const mine = ++saves.current
		const reply = await request({ kind: 'generator.saveOptions', accountId, options })
		// `null` means the account is no longer the active one, which the popup state shows; not a silent background.
		if (mine === saves.current) setFailed(reply === undefined)
	}, [accountId])

	// Closing the popup window runs no React cleanup; `pagehide` and a hidden page still fire.
	useEffect(() => {
		const flush = () => void save()
		const onVisibility = () => document.visibilityState === 'hidden' && flush()
		window.addEventListener('pagehide', flush)
		document.addEventListener('visibilitychange', onVisibility)
		return () => {
			window.removeEventListener('pagehide', flush)
			document.removeEventListener('visibilitychange', onVisibility)
			flush()
		}
	}, [save])

	/** Saves at once, or after `SAVE_DELAY_MS` for the steps of a range input. */
	const update = useCallback((change: (options: GeneratorOptions) => GeneratorOptions, { debounce = false } = {}) => {
		if (!latest.current) return
		const options = change(latest.current)
		latest.current = options
		pending.current = options
		setContext((current) => current && { ...current, options })
		clearTimeout(timer.current)
		if (debounce) timer.current = setTimeout(() => void save(), SAVE_DELAY_MS)
		else void save()
	}, [save])

	return {
		options: context?.options,
		policy: context?.policy ?? null,
		accountEmail: context?.accountEmail ?? null,
		failed,
		update,
	}
}
