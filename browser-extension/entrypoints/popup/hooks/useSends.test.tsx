// @vitest-environment happy-dom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, sendRow, sentOf } from '../testing'
import { SendProvider, useSends } from './useSends'

afterEach(cleanup)

const refreshState = vi.fn()
const wrapper = ({ children }: { children: ReactNode }) => (
	<ShellContext.Provider value={{ ...DEFAULT_SHELL, accountId: 'a2', refreshState }}>
		<SendProvider>{children}</SendProvider>
	</ShellContext.Provider>
)

const input = { payloadType: 'text' as const, content: 'x', maxViews: 1, ttlSeconds: 3600 }

describe('useSends', () => {
	it('lists once for the shell account, however often it is asked', async () => {
		const sent = fakeBackground({ send: { sends: [sendRow()], passwordProtection: false } })
		const { result } = renderHook(() => useSends(), { wrapper })
		act(() => {
			result.current.load()
			result.current.load()
		})
		await waitFor(() => expect(result.current.sends).toHaveLength(1))
		expect(result.current.passwordProtection).toBe(false)
		expect(sentOf(sent, 'send.list')).toEqual([{ kind: 'send.list', accountId: 'a2' }])
	})

	it('holds the link of a send without a password, and only that one', async () => {
		fakeBackground()
		const { result } = renderHook(() => useSends(), { wrapper })
		let plain: string | undefined
		await act(async () => {
			const reply = await result.current.create(input)
			plain = reply?.ok ? reply.value.link : undefined
		})
		await act(() => result.current.create({ ...input, password: 'pw' }))
		await waitFor(() => expect(result.current.sends).toHaveLength(2))
		const [withPassword, without] = result.current.sends!
		expect(result.current.linkFor(without!)).toBe(plain)
		expect(plain).toContain('#k=')
		expect(result.current.linkFor(withPassword!)).toBe(withPassword!.link)
		expect(result.current.linkFor(sendRow({ id: 'elsewhere' }))).toBeUndefined()
	})

	it('drops a removed send and its link', async () => {
		fakeBackground()
		const { result } = renderHook(() => useSends(), { wrapper })
		await act(() => result.current.create(input))
		await waitFor(() => expect(result.current.sends).toHaveLength(1))
		const row = result.current.sends![0]!
		await act(() => result.current.remove(row.id))
		expect(result.current.sends).toEqual([])
		expect(result.current.linkFor(row)).toBeUndefined()
	})

	it('drops the list when a refresh fails, and re-reads the state on locked', async () => {
		let reply: unknown = { ok: true, value: { sends: [sendRow()], passwordProtection: true } }
		fakeBackground({ replies: { 'send.list': () => reply } })
		const { result } = renderHook(() => useSends(), { wrapper })
		act(() => result.current.load())
		await waitFor(() => expect(result.current.sends).toHaveLength(1))
		reply = { ok: false, error: { code: 'locked' } }
		await act(() => result.current.refresh())
		expect(result.current.sends).toBeUndefined()
		expect(result.current.failed).toBe(true)
		expect(refreshState).toHaveBeenCalled()
	})
})
