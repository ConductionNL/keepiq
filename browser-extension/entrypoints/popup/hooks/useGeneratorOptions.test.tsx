// @vitest-environment happy-dom
import { act, cleanup, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { defaultOptions, type GeneratorOptions, type GeneratorPolicy } from '@/src/generator/options'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, sentOf } from '../testing'
import { useGeneratorOptions } from './useGeneratorOptions'

afterEach(cleanup)

const wrapper = ({ children }: { children: ReactNode }) => <ShellContext.Provider value={{ ...DEFAULT_SHELL, accountId: 'a2' }}>{children}</ShellContext.Provider>
const length = (value: number) => (options: GeneratorOptions) => ({ ...options, password: { ...options.password, length: value } })

async function loaded(background: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(background)
	const hook = renderHook(() => useGeneratorOptions(), { wrapper })
	await waitFor(() => expect(hook.result.current.options).toBeDefined())
	return { sent, ...hook }
}

describe('useGeneratorOptions', () => {
	it('loads the context for the shell account', async () => {
		const { sent, result } = await loaded({ generator: { accountEmail: 'bob@example.org' } })
		expect(result.current.options).toEqual(defaultOptions())
		expect(result.current).toMatchObject({ policy: null, accountEmail: 'bob@example.org', failed: false })
		expect(sentOf(sent, 'generator.context')).toEqual([{ kind: 'generator.context', accountId: 'a2' }])
	})

	it('shows and saves a change at once', async () => {
		const { sent, result } = await loaded()
		act(() => result.current.update(length(20)))
		expect(result.current.options!.password.length).toBe(20)
		expect(sentOf(sent, 'generator.saveOptions')).toEqual([{ kind: 'generator.saveOptions', accountId: 'a2', options: expect.objectContaining({ password: expect.objectContaining({ length: 20 }) }) }])
	})

	it('saves debounced steps once they settle', async () => {
		const { sent, result } = await loaded()
		act(() => {
			for (const value of [15, 16, 17]) result.current.update(length(value), { debounce: true })
		})
		expect(result.current.options!.password.length).toBe(17)
		expect(sentOf(sent, 'generator.saveOptions')).toEqual([])
		await waitFor(() => expect(sentOf(sent, 'generator.saveOptions')).toHaveLength(1))
		expect(sentOf(sent, 'generator.saveOptions')[0]).toMatchObject({ options: { password: { length: 17 } } })
	})

	it.each([
		['pagehide', () => window.dispatchEvent(new Event('pagehide'))],
		['a hidden page', () => {
			vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
			document.dispatchEvent(new Event('visibilitychange'))
		}],
		['unmount', null],
	] as const)('saves a debounced step on %s', async (_, hide) => {
		const { sent, result, unmount } = await loaded()
		act(() => result.current.update(length(30), { debounce: true }))
		if (hide) hide()
		else unmount()
		expect(sentOf(sent, 'generator.saveOptions')).toEqual([expect.objectContaining({ options: expect.objectContaining({ password: expect.objectContaining({ length: 30 }) }) })])
	})

	it('takes a new policy and email from a sync, keeping the options on screen', async () => {
		const listeners = new Set<(message: unknown) => unknown>()
		vi.spyOn(browser.runtime.onMessage, 'addListener').mockImplementation((listener) => void listeners.add(listener as never))
		const background = { generator: { policy: null as GeneratorPolicy | null, accountEmail: 'old@example.org' as string | null } }
		const { result } = await loaded(background)
		act(() => result.current.update(length(40), { debounce: true }))
		const relaxed: GeneratorPolicy = { minLength: 10, requireUpper: false, requireLower: false, requireDigit: false, requireSymbol: false, allowPassphrase: false }
		Object.assign(background.generator, { policy: relaxed, accountEmail: 'new@example.org' })
		act(() => listeners.forEach((listener) => listener({ kind: 'vault.changed', sync: {} })))
		await waitFor(() => expect(result.current.policy).toEqual(relaxed))
		expect(result.current.accountEmail).toBe('new@example.org')
		expect(result.current.options!.password.length).toBe(40)
	})

	it('fails when the background does not answer the load', async () => {
		fakeBackground({ unanswered: ['generator.context'] })
		const { result } = renderHook(() => useGeneratorOptions(), { wrapper })
		await waitFor(() => expect(result.current.failed).toBe(true))
		expect(result.current.options).toBeUndefined()
	})

	it('fails on an unanswered save, but not on a refused one', async () => {
		const refused = await loaded({ replies: { 'generator.saveOptions': null } })
		act(() => refused.result.current.update(length(20)))
		await waitFor(() => expect(sentOf(refused.sent, 'generator.saveOptions')).toHaveLength(1))
		await act(() => new Promise((resolve) => setTimeout(resolve, 10)))
		expect(refused.result.current.failed).toBe(false)
		refused.unmount()

		const { result } = await loaded({ unanswered: ['generator.saveOptions'] })
		act(() => result.current.update((options) => ({ ...options, tab: 'passphrase' })))
		await waitFor(() => expect(result.current.failed).toBe(true))
		expect(result.current.options!.tab).toBe('passphrase')
	})

	it('goes by the reply to the latest save only', async () => {
		let first = true
		const { result } = await loaded({
			replies: {
				'generator.saveOptions': async ({ options }: { options: GeneratorOptions }) => {
					if (!first) return options
					first = false
					// The first save's answer arrives last, and is lost.
					await new Promise((resolve) => setTimeout(resolve, 30))
					return undefined
				},
			},
		})
		act(() => result.current.update(length(20)))
		act(() => result.current.update(length(21)))
		await act(() => new Promise((resolve) => setTimeout(resolve, 60)))
		expect(result.current.failed).toBe(false)
	})
})
