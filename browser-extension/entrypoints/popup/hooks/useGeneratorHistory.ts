import { useCallback, useEffect, useState } from 'react'
import type { GeneratorHistoryEntry } from '@/src/generator/options'
import { useShell } from '../shell-context'
import { request } from './useMessage'

/** `entries` is `undefined` until listed; only the History view lists, the generator only adds. */
export function useGeneratorHistory(list = false) {
	const { accountId } = useShell()
	const [entries, setEntries] = useState<GeneratorHistoryEntry[]>()
	const [failed, setFailed] = useState(false)

	useEffect(() => {
		if (!list) return
		let live = true
		void request({ kind: 'generator.history.list', accountId }).then((reply) => {
			if (!live) return
			if (reply) setEntries(reply)
			else setFailed(true)
		})
		return () => {
			live = false
		}
	}, [accountId, list])

	const add = useCallback(async (entry: Omit<GeneratorHistoryEntry, 'createdAt'>) => {
		const reply = await request({ kind: 'generator.history.add', accountId, entry })
		if (reply) setEntries(reply)
	}, [accountId])

	/** Resolves `false` when the background did not answer. */
	const clear = useCallback(async (): Promise<boolean> => {
		if (await request({ kind: 'generator.history.clear', accountId }) === undefined) return false
		setEntries([])
		return true
	}, [accountId])

	return { entries, failed, add, clear }
}
