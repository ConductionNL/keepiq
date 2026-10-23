// @vitest-environment happy-dom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef, type ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { VaultSnapshotReply } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { emptyValues } from '@/src/vault/fields'
import { everythingVisible, fakeBackground, fakeClipboard, mounts, sentKinds, sentOf, snapshotReply, types } from '../testing'
import { applyAppearance, applyTheme } from '../theme'
import { useAccountSettings } from './useAccountSettings'
import { request } from './useMessage'
import { SettingsProvider, useGlobalSettings, useSettings } from './useSettings'
import { useItemDraft } from './useItemDraft'
import { useUnsavedChangesGuard } from './useUnsavedChangesGuard'
import { useBackgroundMessage } from './useBackgroundMessage'
import { useClipboard } from './useClipboard'
import { useCurrentTab } from './useCurrentTab'
import { useDecryptedFields, useLazyLogins } from './useDecryptedFields'
import { useVaultSnapshot } from './useVaultSnapshot'

afterEach(cleanup)

function shell(overrides: { refreshState?: () => void; toast?: (text: string) => void } = {}) {
	const value = { ...DEFAULT_SHELL, refreshState: vi.fn(), toast: vi.fn(), launch: vi.fn(), ...overrides }
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
		await waitFor(() => expect(sent).toHaveBeenCalledTimes(mounts()))
		send({ kind: 'vault.changed', sync: snapshotReply().sync })
		await waitFor(() => expect(sent).toHaveBeenCalledTimes(mounts() + 1))
		const opened = sent.mock.calls.map(([message]) => (message as { opened?: boolean }).opened)
		expect(opened).toEqual([true, ...Array<boolean>(mounts()).fill(false)])
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
		await waitFor(() => expect(replies).toHaveLength(mounts()))
		const mounted = mounts()
		send({ kind: 'vault.changed', sync: snapshotReply().sync })
		await waitFor(() => expect(replies).toHaveLength(mounted + 1))
		await act(async () => replies[mounted]!(snapshotReply({ suggestionIds: ['new'] })))
		for (const older of replies.slice(0, mounted)) await act(async () => older(snapshotReply({ suggestionIds: ['old'] })))
		expect(result.current.reply).toMatchObject({ suggestionIds: ['new'] })
	})

	it('answers an overtaken reload with the newer read', async () => {
		const { send } = broadcasts()
		const replies: Array<(reply: VaultSnapshotReply) => void> = []
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(() => new Promise((resolve) => replies.push(resolve as never)) as never)
		const { result } = renderHook(() => useVaultSnapshot('https://github.com', true))
		await waitFor(() => expect(replies).toHaveLength(mounts()))
		const mounted = mounts()
		for (const read of replies.slice(0, mounted)) await act(async () => read(snapshotReply()))
		let reloaded!: Promise<VaultSnapshotReply | undefined>
		act(() => void (reloaded = result.current.reload()))
		send({ kind: 'vault.changed', sync: snapshotReply().sync })
		await waitFor(() => expect(replies).toHaveLength(mounted + 2))
		await act(async () => replies[mounted]!(snapshotReply({ suggestionIds: ['old'] })))
		await act(async () => replies[mounted + 1]!(snapshotReply({ suggestionIds: ['new'] })))
		expect(await reloaded).toMatchObject({ suggestionIds: ['new'] })
	})
})

describe('useItemDraft', () => {
	const initial = { ...emptyValues('t-login'), name: 'GitHub', login: 'alice' }

	it('is clean until a value changes, and patches only that value', () => {
		const { result } = renderHook(() => useItemDraft(initial, types))
		expect(result.current.dirty).toBe(false)
		act(() => result.current.update({ login: '' }))
		expect(result.current.dirty).toBe(true)
		expect(result.current.patch).toEqual({ login: null })
	})

	it('shows name refusals only after Save, field name refusals at once', () => {
		const { result } = renderHook(() => useItemDraft({ ...initial, name: '' }, types))
		act(() => result.current.update({ fields: [{ id: 1, name: 'url', value: 'x' }] }))
		expect(result.current.errors).toEqual({ 'field.1.name': { code: 'reserved' } })
		expect(result.current.blocked).toBe(true)
		let draft: unknown
		act(() => {
			draft = result.current.submit()
		})
		expect(draft).toBeNull()
		expect(result.current.errors).toMatchObject({ name: { code: 'required' } })
	})

	it('hands over the draft once valid', () => {
		const { result } = renderHook(() => useItemDraft(initial, types))
		let draft: unknown
		act(() => {
			draft = result.current.submit()
		})
		expect(draft).toMatchObject({ name: 'GitHub', login: 'alice', typeId: 't-login' })
	})
})

describe('useGlobalSettings', () => {
	afterEach(() => {
		applyTheme('system')
		applyAppearance({ compactMode: false, showAnimations: true })
	})

	it('reads once and applies the stored theme and appearance', async () => {
		const sent = fakeBackground({ settings: { theme: 'dark', compactMode: true, showAnimations: false } })
		const { result } = renderHook(() => useGlobalSettings())
		await waitFor(() => expect(result.current.settings?.compactMode).toBe(true))
		expect(sentKinds(sent)).toEqual(Array(mounts()).fill('settings.get'))
		expect(document.documentElement.dataset.theme).toBe('dark')
		expect(document.documentElement.classList.contains('compact')).toBe(true)
		expect(document.documentElement.classList.contains('no-animations')).toBe(true)
	})

	it('applies a change at once and says when the background did not answer', async () => {
		fakeBackground({ unanswered: ['settings.set'] })
		const { result } = renderHook(() => useGlobalSettings())
		await waitFor(() => expect(result.current.settings).not.toBeNull())
		await act(() => result.current.update({ compactMode: true }))
		expect(result.current.failed).toBe(true)
		expect(document.documentElement.classList.contains('compact')).toBe(false)
	})

	it('shows as failed when the first read gets no answer', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		fakeBackground({ unanswered: ['settings.get'] })
		const { result } = renderHook(() => useGlobalSettings())
		await waitFor(() => expect(result.current.failed).toBe(true))
		expect(result.current.settings).toBeNull()
	})

	it('is shared through its provider, so every reader sees a change', async () => {
		const sent = fakeBackground({ settings: { quickCopy: false } })
		const { result } = renderHook(() => [useSettings(), useSettings()] as const, { wrapper: SettingsProvider })
		await waitFor(() => expect(result.current[0].settings?.quickCopy).toBe(false))
		await act(() => result.current[0].update({ quickCopy: true }))
		expect(result.current[1].settings?.quickCopy).toBe(true)
		expect(sentOf(sent, 'settings.get')).toHaveLength(mounts())
	})
})

describe('useAccountSettings', () => {
	it('reads the shell\'s account and patches it', async () => {
		const sent = fakeBackground({ accountSettings: { values: { clearClipboardMs: 20_000 } } })
		const { result } = renderHook(() => useAccountSettings(), { wrapper: shell().wrapper })
		await waitFor(() => expect(result.current.snapshot?.values.clearClipboardMs).toBe(20_000))
		let saved: boolean | undefined
		await act(async () => {
			saved = await result.current.update({ vaultTimeout: 30 })
		})
		expect(saved).toBe(true)
		expect(sentOf(sent, 'account.settings.set')).toEqual([{ kind: 'account.settings.set', accountId: 'a1', patch: { vaultTimeout: 30 } }])
		expect(result.current.snapshot?.values).toMatchObject({ vaultTimeout: 30, clearClipboardMs: 20_000 })
		expect(sentOf(sent, 'account.settings.get')).toEqual(Array(mounts()).fill({ kind: 'account.settings.get', accountId: 'a1' }))
	})

	it('re-reads the popup state when the account locked meanwhile', async () => {
		fakeBackground({ replies: { 'account.settings.set': 'locked' } })
		const { value, wrapper } = shell()
		const { result } = renderHook(() => useAccountSettings(), { wrapper })
		await waitFor(() => expect(result.current.snapshot).toBeTruthy())
		await act(async () => void await result.current.update({ vaultTimeout: 30 }))
		expect(value.refreshState).toHaveBeenCalled()
		expect(result.current.snapshot?.values.vaultTimeout).toBe(15)
	})

	it('says when the background did not answer', async () => {
		vi.spyOn(console, 'error').mockImplementation(() => {})
		fakeBackground({ unanswered: ['account.settings.get'] })
		const { result } = renderHook(() => useAccountSettings(), { wrapper: shell().wrapper })
		await waitFor(() => expect(result.current.failed).toBe(true))
		expect(result.current.snapshot).toBeUndefined()
	})

	it('re-reads after a PIN change', async () => {
		fakeBackground()
		const { result } = renderHook(() => useAccountSettings(), { wrapper: shell().wrapper })
		await waitFor(() => expect(result.current.snapshot?.pin).toBe(false))
		await act(async () => void await request({ kind: 'pin.set', accountId: 'a1', pin: '2468', masterPassword: 'pw' }))
		await act(() => result.current.reload())
		expect(result.current.snapshot?.pin).toBe(true)
	})
})

describe('useUnsavedChangesGuard', () => {
	it('registers a live dirty check with Shell and removes it on unmount', () => {
		const setLeaveGuard = vi.fn()
		const wrapper = ({ children }: { children: ReactNode }) => <ShellContext.Provider value={{ ...DEFAULT_SHELL, setLeaveGuard }}>{children}</ShellContext.Provider>
		const { rerender, unmount } = renderHook(({ dirty }) => useUnsavedChangesGuard(dirty), { wrapper, initialProps: { dirty: false } })
		const isDirty = setLeaveGuard.mock.calls[0]![0] as () => boolean
		expect(isDirty()).toBe(false)
		rerender({ dirty: true })
		expect(isDirty()).toBe(true)
		unmount()
		expect(setLeaveGuard).toHaveBeenLastCalledWith(null)
	})
})
