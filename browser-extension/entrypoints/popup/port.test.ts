import { describe, expect, it, vi } from 'vitest'
import { holdPopupPort } from './port'

function fakeConnect() {
	const disconnects: Array<() => void> = []
	const connect = vi.spyOn(browser.runtime, 'connect').mockImplementation(() => ({ onDisconnect: { addListener: (listener: () => void) => disconnects.push(listener) } }) as never)
	return { connect, drop: () => disconnects.at(-1)!() }
}

describe('holdPopupPort', () => {
	it('connects again when the background worker drops the port', () => {
		let now = 0
		const { connect, drop } = fakeConnect()
		holdPopupPort(0, () => now)
		expect(connect).toHaveBeenCalledWith({ name: 'popup' })
		for (let i = 0; i < 5; i++) {
			now += 60_000
			drop()
		}
		expect(connect).toHaveBeenCalledTimes(6)
	})

	it('stops after the port is refused three times in a row', () => {
		const { connect, drop } = fakeConnect()
		holdPopupPort(0, () => 0)
		for (let i = 0; i < 5 && connect.mock.calls.length > i; i++) drop()
		expect(connect).toHaveBeenCalledTimes(3)
	})

	it('gives up quietly once the extension was reloaded', () => {
		vi.spyOn(browser.runtime, 'connect').mockImplementation(() => {
			throw new Error('Extension context invalidated.')
		})
		expect(() => holdPopupPort()).not.toThrow()
	})
})
