// @vitest-environment happy-dom
import { act, cleanup, renderHook } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { fakeBackground, itemMeta, sendRow, sentOf } from '../testing'
import { useBodyScroll, useItemActions, useLeave, useSendItem, useSendPages, useShellContextValue, useTabPages, type ScrollPages } from './shellHooks'

afterEach(() => {
	cleanup()
	document.body.replaceChildren()
})

describe('useBodyScroll', () => {
	const LIST: ScrollPages = { pick: null, detailId: null, form: null, sendPage: null }
	const FORM: ScrollPages = { ...LIST, form: { mode: 'add' } }

	function scroll(initial: ScrollPages = LIST) {
		const body = document.createElement('div')
		// One ref for every render, as the shell's useRef is.
		const bodyRef = { current: body }
		const hook = renderHook((pages: ScrollPages) => useBodyScroll(bodyRef, pages), { initialProps: initial })
		return { body, ...hook }
	}

	function opener() {
		const button = document.createElement('button')
		document.body.append(button)
		return button
	}

	it('opens a page at the top and brings the list back to where it was left', () => {
		const { body, result, rerender } = scroll()
		body.scrollTop = 120
		result.current.leaveList()
		rerender({ ...LIST, detailId: 'i1' })
		expect(body.scrollTop).toBe(0)
		body.scrollTop = 15
		rerender(LIST)
		expect(body.scrollTop).toBe(120)
	})

	it('keeps the list\'s place, not the detail\'s, when a form opens from a detail', () => {
		const { body, result, rerender } = scroll()
		body.scrollTop = 30
		result.current.leaveList()
		rerender({ ...LIST, detailId: 'i1' })
		body.scrollTop = 15
		result.current.leaveList()
		rerender({ ...LIST, detailId: 'i1', form: { mode: 'edit', id: 'i1' } })
		rerender(LIST)
		expect(body.scrollTop).toBe(30)
	})

	it('opens pick mode at the top, then puts the form back where it was with focus on the button that opened it', () => {
		const { body, result, rerender } = scroll(FORM)
		const button = opener()
		body.scrollTop = 40
		result.current.keepFormPlace(button)
		rerender({ ...FORM, pick: {} })
		expect(body.scrollTop).toBe(0)
		body.scrollTop = 7
		rerender(FORM)
		expect(body.scrollTop).toBe(40)
		expect(document.activeElement).toBe(button)
	})

	it('brings the list back, not the form, when pick mode ends with its form', () => {
		const { body, result, rerender } = scroll()
		body.scrollTop = 120
		result.current.leaveList()
		rerender(FORM)
		body.scrollTop = 40
		result.current.keepFormPlace(opener())
		rerender({ ...FORM, pick: {} })
		rerender(LIST)
		expect(body.scrollTop).toBe(120)
	})

	it('opens a send page at the top', () => {
		const { body, rerender } = scroll()
		body.scrollTop = 50
		rerender({ ...LIST, sendPage: { kind: 'new', key: 1 } })
		expect(body.scrollTop).toBe(0)
	})
})

describe('useLeave', () => {
	it('leaves at once when no form is dirty', () => {
		const { result } = renderHook(() => useLeave(false))
		const then = vi.fn()
		act(() => result.current.leave(then))
		result.current.leaveGuardRef.current = () => false
		act(() => result.current.leave(then))
		expect(then).toHaveBeenCalledTimes(2)
		expect(result.current.dialog).toBeNull()
	})

	it('asks first when the form is dirty, and leaves only once the user agrees', () => {
		const { result } = renderHook(() => useLeave(false))
		const then = vi.fn()
		result.current.leaveGuardRef.current = () => true
		act(() => result.current.leave(then))
		expect(then).not.toHaveBeenCalled()
		const { dialog } = result.current
		expect(dialog).toEqual({ kind: 'discard', then })
		// What the dialog's Discard runs.
		act(() => {
			if (dialog?.kind === 'discard') dialog.then()
		})
		expect(then).toHaveBeenCalledOnce()
	})

	it('does nothing while a send is being created', () => {
		const { result } = renderHook(() => useLeave(true))
		const then = vi.fn()
		act(() => result.current.leave(then))
		result.current.leaveGuardRef.current = () => true
		act(() => result.current.leave(then))
		expect(then).not.toHaveBeenCalled()
		expect(result.current.dialog).toBeNull()
	})
})

describe('useTabPages and useSendPages', () => {
	function pages(lastTab: 'vault' | 'send' = 'vault') {
		return renderHook(({ tab }) => {
			const send = useSendPages()
			return { send, tab: useTabPages(tab, send.closeSend) }
		}, { initialProps: { tab: lastTab } })
	}

	it('opens on the last tab, and a later state does not move it', () => {
		const { result, rerender } = pages('send')
		expect(result.current.tab.current).toBe('send')
		rerender({ tab: 'vault' })
		expect(result.current.tab.current).toBe('send')
	})

	it('shows a tab with every page closed, the send row focus dropped, and remembers it', () => {
		const sent = fakeBackground()
		const { result } = pages('send')
		act(() => {
			result.current.tab.setDetailId('i1')
			result.current.tab.setForm({ mode: 'edit', id: 'i1' })
			result.current.tab.setSection('autofill')
			result.current.tab.setHistory(true)
			result.current.send.setSendPage({ kind: 'created', row: sendRow(), link: 'https://cloud.example.org/l' })
			result.current.send.setSendFocus('s1')
		})
		act(() => result.current.tab.show('generator'))
		const { tab, send } = result.current
		expect({ current: tab.current, detailId: tab.detailId, form: tab.form, section: tab.section, history: tab.history }).toEqual({
			current: 'generator', detailId: null, form: null, section: null, history: false,
		})
		expect({ sendPage: send.sendPage, sendFocus: send.sendFocus }).toEqual({ sendPage: null, sendFocus: null })
		expect(sentOf(sent, 'popup.lastTab.set')).toEqual([{ kind: 'popup.lastTab.set', tab: 'generator' }])
	})

	it('closes the form and the detail under it when the item is gone', () => {
		const { result } = pages()
		act(() => {
			result.current.tab.setDetailId('i1')
			result.current.tab.setForm({ mode: 'edit', id: 'i1' })
		})
		act(() => result.current.tab.gone())
		expect([result.current.tab.form, result.current.tab.detailId]).toEqual([null, null])
	})

	it('opens New send with no row focus, and shows a created send only over the form it came from', () => {
		const { result } = pages('send')
		act(() => result.current.send.setSendFocus('s1'))
		act(() => result.current.send.newSend())
		const page = result.current.send.sendPage
		expect(page?.kind).toBe('new')
		expect(result.current.send.sendFocus).toBeNull()
		const key = page?.kind === 'new' ? page.key : 0
		act(() => {
			result.current.send.setSendBusy(true)
			result.current.send.created(key + 1, sendRow(), 'https://cloud.example.org/old')
		})
		expect(result.current.send.sendPage).toBe(page)
		expect(result.current.send.sendBusy).toBe(false)
		act(() => result.current.send.created(key, sendRow(), 'https://cloud.example.org/l'))
		expect(result.current.send.sendPage).toEqual({ kind: 'created', row: sendRow(), link: 'https://cloud.example.org/l' })
	})
})

describe('useSendItem', () => {
	function sendItem(decrypt: unknown) {
		fakeBackground({ replies: { 'item.decrypt': decrypt } })
		const shell = { refreshState: vi.fn(), toast: vi.fn(), show: vi.fn(), setSendPage: vi.fn() }
		const { result } = renderHook(() => useSendItem(shell))
		return { shell, send: () => act(() => result.current(itemMeta())) }
	}

	it('opens New send on the Send tab with the login filled in', async () => {
		const { shell, send } = sendItem({ ok: true, items: { i1: { login: 'alice', key: 'pw' } } })
		await send()
		expect(shell.show).toHaveBeenCalledWith('send')
		expect(shell.setSendPage).toHaveBeenCalledWith({ kind: 'new', key: expect.any(Number), prefill: { username: 'alice', password: 'pw' } })
	})

	it('re-reads the popup state, and opens nothing, when the vault is locked', async () => {
		const { shell, send } = sendItem({ ok: false, error: 'locked' })
		await send()
		expect(shell.refreshState).toHaveBeenCalled()
		expect([shell.toast, shell.show, shell.setSendPage].map((spy) => spy.mock.calls.length)).toEqual([0, 0, 0])
	})

	it.each([
		['cannot be read', { ok: false, error: 'decrypt_failed' }],
		['is not in the reply', { ok: true, items: {} }],
	])('says so, and opens nothing, when the login %s', async (_, reply) => {
		const { shell, send } = sendItem(reply)
		await send()
		expect(shell.toast).toHaveBeenCalledWith('Could not read this login')
		expect([shell.refreshState, shell.show, shell.setSendPage].map((spy) => spy.mock.calls.length)).toEqual([0, 0, 0])
	})
})

describe('useItemActions', () => {
	it('remembers the list\'s place before a form opens, and opens dialogs in place', () => {
		const pages = { leaveList: vi.fn(), setForm: vi.fn(), setDialog: vi.fn(), sendItem: vi.fn() }
		const { result } = renderHook(() => useItemActions(pages))
		result.current.edit('i1')
		expect(pages.leaveList.mock.invocationCallOrder[0]).toBeLessThan(pages.setForm.mock.invocationCallOrder[0]!)
		expect(pages.setForm).toHaveBeenCalledWith({ mode: 'edit', id: 'i1' })
		result.current.remove(itemMeta())
		expect(pages.setDialog).toHaveBeenCalledWith({ kind: 'delete', item: itemMeta() })
		expect(pages.leaveList).toHaveBeenCalledTimes(1)
	})
})

describe('useShellContextValue', () => {
	it('lets a page set the leave guard, and keeps a form under the generator out of the bar', () => {
		const footer = document.createElement('div')
		const leaveGuardRef = { current: null as (() => boolean) | null }
		const parts = { accountId: 'a1', refreshState: vi.fn(), toast: vi.fn(), footer, offline: false, syncNow: vi.fn(), items: {} as never, generate: vi.fn() }
		const { result } = renderHook(() => useShellContextValue(parts, leaveGuardRef))
		const guard = () => true
		result.current.context.setLeaveGuard(guard)
		expect(leaveGuardRef.current).toBe(guard)
		expect(result.current.context.footer).toBe(footer)
		expect(result.current.hiddenFormContext.footer).toBeNull()
	})
})
