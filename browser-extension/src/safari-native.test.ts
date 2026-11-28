import { afterEach, describe, expect, it, vi } from 'vitest'
import { isChangeCount, NATIVE_TIMEOUT_MS, nativeClear, NativeOutcomeUnknown, nativeWrite } from './safari-native'

function fakeApp(reply: (message: unknown) => unknown) {
	return vi.spyOn(browser.runtime, 'sendNativeMessage').mockImplementation(async (_app: string, message: object) => reply(message))
}

afterEach(() => {
	vi.useRealTimers()
})

describe('nativeWrite', () => {
	it('sends the text to the containing app and resolves to its change count', async () => {
		const send = fakeApp(() => ({ ok: true, changeCount: 8 }))
		expect(await nativeWrite('hunter2')).toBe(8)
		expect(send).toHaveBeenCalledWith('nl.conduction.keepiq.safari', { kind: 'clipboard.write', text: 'hunter2' })
	})

	it('rejects when the app refuses or the message fails', async () => {
		fakeApp(() => ({ ok: false, error: 'write_failed' }))
		await expect(nativeWrite('hunter2')).rejects.toThrow('clipboard.write: refused')
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockRejectedValue(new Error('No native host'))
		await expect(nativeWrite('hunter2')).rejects.toThrow('No native host')
	})

	it.each([
		['nothing', undefined],
		['a string', 'ok'],
		['ok without a count', { ok: true }],
		['a fractional count', { ok: true, changeCount: 1.5 }],
		['a negative count', { ok: true, changeCount: -1 }],
		['a count as text', { ok: true, changeCount: '8' }],
		['ok as text', { ok: 'true', changeCount: 8 }],
	])('rejects a reply of %s, never naming the text', async (_, reply) => {
		fakeApp(() => reply)
		const error = await nativeWrite('hunter2').catch((caught: Error) => caught)
		expect(error).toBeInstanceOf(NativeOutcomeUnknown)
		expect((error as Error).message).not.toContain('hunter2')
	})

	it('tells a refusal or an unreachable app, which wrote nothing, from an unknown outcome', async () => {
		fakeApp(() => ({ ok: false, error: 'write_failed' }))
		await expect(nativeWrite('hunter2')).rejects.not.toBeInstanceOf(NativeOutcomeUnknown)
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockRejectedValue(new Error('No native host'))
		await expect(nativeWrite('hunter2')).rejects.not.toBeInstanceOf(NativeOutcomeUnknown)
	})

	it('rejects when the app does not answer within 2 seconds', async () => {
		vi.useFakeTimers()
		vi.spyOn(browser.runtime, 'sendNativeMessage').mockReturnValue(new Promise(() => {}) as never)
		const written = nativeWrite('hunter2')
		const settled = written.catch((error: unknown) => error)
		await vi.advanceTimersByTimeAsync(NATIVE_TIMEOUT_MS)
		expect(await settled).toBeInstanceOf(NativeOutcomeUnknown)
		expect(await settled).toHaveProperty('message', 'clipboard.write: no reply')
	})
})

describe('nativeClear', () => {
	it('asks for a conditional clear with a count and resolves to whether the app cleared', async () => {
		const send = fakeApp(() => ({ ok: true, cleared: false }))
		expect(await nativeClear(8)).toBe(false)
		expect(send).toHaveBeenCalledWith('nl.conduction.keepiq.safari', { kind: 'clipboard.clear', changeCount: 8 })
	})

	it('asks for an unconditional clear without one', async () => {
		const send = fakeApp(() => ({ ok: true, cleared: true }))
		expect(await nativeClear()).toBe(true)
		expect(send).toHaveBeenCalledWith('nl.conduction.keepiq.safari', { kind: 'clipboard.clear' })
	})

	it.each([['no cleared', { ok: true }], ['an error', { ok: false, error: 'bad_request' }], ['null', null]])('rejects a reply with %s', async (_, reply) => {
		fakeApp(() => reply)
		await expect(nativeClear(8)).rejects.toThrow()
	})
})

describe('isChangeCount', () => {
	it.each([[0, true], [8, true], [-1, false], [1.5, false], ['8', false], [Number.NaN, false], [undefined, false], [2 ** 60, false]])('%s → %s', (value, valid) => {
		expect(isChangeCount(value)).toBe(valid)
	})
})
