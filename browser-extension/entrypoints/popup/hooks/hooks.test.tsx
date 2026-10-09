// @vitest-environment happy-dom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef, type ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VaultSnapshotReply } from '@/src/messages'
import { ShellContext } from '../shell-context'
import { everythingVisible, fakeBackground, fakeClipboard, sentKinds, snapshotReply } from '../testing'
import { useBackgroundMessage } from './useBackgroundMessage'
import { useClipboard } from './useClipboard'
import { useCurrentTab } from './useCurrentTab'
import { useDecryptedFields, useLazyLogins } from './useDecryptedFields'
import { useVaultSnapshot } from './useVaultSnapshot'

afterEach(cleanup)

function shell(overrides: { refreshState?: () => void; toast?: (text: string) => void } = {}) {
	const value = { refreshState: vi.fn(), toast: vi.fn(), launch: vi.fn(), ...overrides }
	const wrapper = ({ children }: { children: ReactNode }) => <ShellContext.Provider value={value}>{children}</ShellContext.Provider>
	return { value, wrapper }
}

/** Captures the popup's runtime listeners so a test can play the background's broadcasts. */
function broadcasts() {
	const listeners = new Set<(message: unknown) => unknown>()
	vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.add(listener as never))
	vi.spyOn(browser.runtime.onMessage, 'removeListener').mockImplementation((listener) => void listeners.delete(listener as never))
	return {
		listeners,
		send: (message: unknown) => act(() => listeners.forEach((listener) => listener(message))),
	}
}

describe('useBackgroundMessage', () => {
	it('calls the handler for its kind only, replies nothing, and unsubscribes on unmount', () => {
		const { listeners, send } = broadcasts()
		const handler = vi.fn()
		const { unmount } = renderHook(() => useBackgroundMessage('vault.locked', handler))
		send({ kind: 'vault.changed', sync: {} })
		expect(handler).not.toHaveBeenCalled()
		const [listener] = listeners
		expect(listener!({ kind: 'vault.locked' })).toBeUndefined()
		expect(handler).toHaveBeenCalledOnce()
		unmount()
		expect(listeners.size).toBe(0)
	})
})

describe('useClipboard', () => {
	it('copies, toasts and tells the background', async () => {
		userEvent.setup()
		const writeText = fakeClipboard()
		const sent = fakeBackground()
		const { value, wrapper } = shell()
		const { result } = renderHook(() => useClipboard(), { wrapper })
		await act(() => result.current('alice', 'Username'))
		expect(writeText).toHaveBeenCalledWith('alice')
		expect(value.toast).toHaveBeenCalledWith('Username copied')
		expect(sentKinds(sent)).toEqual(['clipboard.copied'])
	})

	it('only toasts when the browser refuses', async () => {
		userEvent.setup()
		fakeClipboard(true)
		const sent = fakeBackground()
		const { value, wrapper } = shell()
		const { result } = renderHook(() => useClipboard(), { wrapper })
		await act(() => result.current('alice', 'Username'))
		expect(value.toast).toHaveBeenCalledWith('Could not copy')
		expect(sent).not.toHaveBeenCalled()
	})
})

describe('useCurrentTab', () => {
	it('reads the active tab and its host', async () => {
		vi.spyOn(browser.tabs, 'query').mockResolvedValue([{ id: 4, windowId: 2, url: 'https://login.example.com/x' }] as never)
		const { result } = renderHook(() => useCurrentTab())
		await waitFor(() => expect(result.current).toEqual({ id: 4, windowId: 2, url: 'https://login.example.com/x', host: 'login.example.com' }))
	})

	it('has no host on a browser page', async () => {
		vi.spyOn(browser.tabs, 'query').mockResolvedValue([{ id: 4, windowId: 2, url: 'chrome://newtab/' }] as never)
		const { result } = renderHook(() => useCurrentTab())
		await waitFor(() => expect(result.current?.host).toBeNull())
	})
})

describe('useDecryptedFields', () => {
	it('decrypts the item on mount', async () => {
		fakeBackground({ decrypted: { a: { key: 'pw' } } })
		const { result } = renderHook(() => useDecryptedFields('a', ['key']), { wrapper: shell().wrapper })
		expect(result.current).toEqual({ status: 'loading' })
		await waitFor(() => expect(result.current).toEqual({ status: 'ready', item: { key: 'pw' } }))
	})

	it('sends nothing for a blocked row', () => {
		const sent = fakeBackground()
		renderHook(() => useDecryptedFields('a', ['key'], false), { wrapper: shell().wrapper })
		expect(sent).not.toHaveBeenCalled()
	})

	it('re-reads the popup state when the vault locked meanwhile', async () => {
		fakeBackground({ decryptError: 'locked' })
		const { value, wrapper } = shell()
		const { result } = renderHook(() => useDecryptedFields('a', ['key']), { wrapper })
		await waitFor(() => expect(result.current).toEqual({ status: 'error', error: 'locked' }))
		expect(value.refreshState).toHaveBeenCalled()
	})
})

describe('useLazyLogins', () => {
	it('decrypts rows in view in batches of 20, once each', async () => {
		everythingVisible()
		const ids = Array.from({ length: 25 }, (_, i) => `r${i}`)
		const sent = fakeBackground({ decrypted: Object.fromEntries(ids.map((id) => [id, { login: `user-${id}` }])) })
		const container = createRef<HTMLDivElement>() as { current: HTMLDivElement }
		container.current = document.createElement('div')
		container.current.innerHTML = ids.map((id) => `<div data-lazy-login="${id}@v1"></div>`).join('')
		const { result, rerender } = renderHook(({ key }) => useLazyLogins(container, key), { wrapper: shell().wrapper, initialProps: { key: 1 } })
		await waitFor(() => expect(Object.keys(result.current)).toHaveLength(25))
		rerender({ key: 2 })
		const batches = sent.mock.calls.map(([message]) => message.ids!.length)
		expect(batches).toEqual([20, 5])
		expect(sent.mock.calls.every(([message]) => (message as { passive?: boolean }).passive === true)).toBe(true)
		expect(result.current['r24@v1']).toBe('user-r24')
	})
})

describe('useLazyLogins, retries and versions', () => {
	function rows(keys: string[]) {
		const container = { current: document.createElement('div') }
		container.current.innerHTML = keys.map((key) => `<div data-lazy-login="${key}"></div>`).join('')
		return container
	}

	it('asks again for a batch that got no reply', async () => {
		everythingVisible()
		vi.spyOn(console, 'error').mockImplementation(() => {})
		vi.useFakeTimers({ toFake: ['setTimeout'] })
		try {
			const send = vi.spyOn(browser.runtime, 'sendMessage')
				.mockResolvedValueOnce(undefined as never)
				.mockResolvedValue({ ok: true, items: { a: { login: 'alice' } } } as never)
			const { result } = renderHook(() => useLazyLogins(rows(['a@v1']), 1), { wrapper: shell().wrapper })
			// testing-library's waitFor stalls on fake timers; vi.waitFor advances them.
			await vi.waitFor(() => expect(send).toHaveBeenCalledTimes(1))
			await act(() => vi.advanceTimersByTimeAsync(2_000))
			await vi.waitFor(() => expect(result.current['a@v1']).toBe('alice'))
			expect(send).toHaveBeenCalledTimes(2)
		} finally {
			vi.useRealTimers()
		}
	})

	it('decrypts again for a new version of the row', async () => {
		everythingVisible()
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue({ ok: true, items: { a: { login: 'alice' } } } as never)
		const { result, rerender } = renderHook(({ container }) => useLazyLogins(container, container), { wrapper: shell().wrapper, initialProps: { container: rows(['a@v1']) } })
		await waitFor(() => expect(result.current['a@v1']).toBe('alice'))
		send.mockResolvedValue({ ok: true, items: { a: { login: 'alice2' } } } as never)
		rerender({ container: rows(['a@v2']) })
		await waitFor(() => expect(result.current['a@v2']).toBe('alice2'))
	})
})

describe('useCurrentTab in a popped-out window', () => {
	afterEach(() => {
		window.history.replaceState({}, '', '/')
		vi.resetModules()
	})

	it('reads the tab it was popped out from, not the active one', async () => {
		window.history.replaceState({}, '', '/popup.html?popout=1&tabId=9')
		vi.resetModules()
		const popout = await import('./useCurrentTab')
		expect(popout.isPopout).toBe(true)
		const get = vi.spyOn(browser.tabs, 'get').mockResolvedValue({ id: 9, windowId: 5, url: 'https://github.com/login' } as never)
		const query = vi.spyOn(browser.tabs, 'query')
		const { result } = renderHook(() => popout.useCurrentTab())
		await waitFor(() => expect(result.current).toEqual({ id: 9, windowId: 5, url: 'https://github.com/login', host: 'github.com' }))
		expect(get).toHaveBeenCalledWith(9)
		expect(query).not.toHaveBeenCalled()
	})
})

describe('useVaultSnapshot', () => {
	it('marks only the first read as the popup opening', async () => {
		const { send } = broadcasts()
		const sent = fakeBackground()
		renderHook(() => useVaultSnapshot('https://github.com', true))
		await waitFor(() => expect(sent).toHaveBeenCalledOnce())
		send({ kind: 'vault.changed', sync: snapshotReply().sync })
		await waitFor(() => expect(sent).toHaveBeenCalledTimes(2))
		expect(sent.mock.calls.map(([message]) => (message as { opened?: boolean }).opened)).toEqual([true, false])
	})

	it('waits until the tab is known', () => {
		const sent = fakeBackground()
		renderHook(() => useVaultSnapshot(undefined, false))
		expect(sent).not.toHaveBeenCalled()
	})

	it('keeps the newest reply when an older one arrives last', async () => {
		const { send } = broadcasts()
		const replies: Array<(reply: VaultSnapshotReply) => void> = []
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(() => new Promise((resolve) => replies.push(resolve as never)) as never)
		const { result } = renderHook(() => useVaultSnapshot('https://github.com', true))
		await waitFor(() => expect(replies).toHaveLength(1))
		send({ kind: 'vault.changed', sync: snapshotReply().sync })
		await waitFor(() => expect(replies).toHaveLength(2))
		await act(async () => replies[1]!(snapshotReply({ suggestionIds: ['new'] })))
		await act(async () => replies[0]!(snapshotReply({ suggestionIds: ['old'] })))
		expect(result.current.reply).toMatchObject({ suggestionIds: ['new'] })
	})
})
