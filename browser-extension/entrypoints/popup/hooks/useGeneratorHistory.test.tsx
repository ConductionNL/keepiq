// @vitest-environment happy-dom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it } from 'vitest'
import type { GeneratorHistoryEntry } from '@/src/generator/options'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, mounts, sentKinds, sentOf } from '../testing'
import { useGeneratorHistory } from './useGeneratorHistory'

afterEach(cleanup)

const wrapper = ({ children }: { children: ReactNode }) => <ShellContext.Provider value={{ ...DEFAULT_SHELL, accountId: 'a2' }}>{children}</ShellContext.Provider>
const entry: GeneratorHistoryEntry = { value: 'old', kind: 'password', createdAt: '2026-10-22T09:00:00Z' }

describe('useGeneratorHistory', () => {
	it('lists only when asked to', async () => {
		const sent = fakeBackground({ generator: { history: [entry] } })
		const { result } = renderHook(() => useGeneratorHistory(), { wrapper })
		expect(result.current.entries).toBeUndefined()
		expect(sentKinds(sent)).toEqual([])

		const listed = renderHook(() => useGeneratorHistory(true), { wrapper })
		await waitFor(() => expect(listed.result.current.entries).toEqual([entry]))
		expect(sentOf(sent, 'generator.history.list')).toEqual(Array(mounts()).fill({ kind: 'generator.history.list', accountId: 'a2' }))
	})

	it('adds for the shell account and takes the list the background answers', async () => {
		const sent = fakeBackground({ generator: { history: [entry] } })
		const { result } = renderHook(() => useGeneratorHistory(), { wrapper })
		await act(() => result.current.add({ value: 'new', kind: 'username' }))
		expect(sentOf(sent, 'generator.history.add')).toEqual([{ kind: 'generator.history.add', accountId: 'a2', entry: { value: 'new', kind: 'username' } }])
		expect(result.current.entries!.map((e) => e.value)).toEqual(['new', 'old'])
	})

	it('clears, and says whether the background answered', async () => {
		fakeBackground({ generator: { history: [entry] } })
		const { result } = renderHook(() => useGeneratorHistory(true), { wrapper })
		await waitFor(() => expect(result.current.entries).toHaveLength(1))
		await act(async () => expect(await result.current.clear()).toBe(true))
		expect(result.current.entries).toEqual([])

		fakeBackground({ generator: { history: [entry] }, unanswered: ['generator.history.clear'] })
		const second = renderHook(() => useGeneratorHistory(true), { wrapper })
		await waitFor(() => expect(second.result.current.entries).toHaveLength(1))
		await act(async () => expect(await second.result.current.clear()).toBe(false))
		expect(second.result.current.entries).toHaveLength(1)
	})

	it('fails when the list does not come', async () => {
		fakeBackground({ unanswered: ['generator.history.list'] })
		const { result } = renderHook(() => useGeneratorHistory(true), { wrapper })
		await waitFor(() => expect(result.current.failed).toBe(true))
	})
})
