import { describe, expect, it, vi } from 'vitest'
import { broadcast, onBroadcast } from './broadcast'

describe('broadcast', () => {
	it('sends the message', () => {
		const send = vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(undefined as never)
		broadcast({ kind: 'vault.locked' })
		expect(send).toHaveBeenCalledWith({ kind: 'vault.locked' })
	})

	it('swallows the rejection when no popup is open', async () => {
		vi.spyOn(browser.runtime, 'sendMessage').mockRejectedValue(new Error('Receiving end does not exist.'))
		expect(() => broadcast({ kind: 'vault.locked' })).not.toThrow()
		await new Promise((resolve) => setTimeout(resolve, 0))
	})

	it('tells the background’s own listeners too', () => {
		vi.spyOn(browser.runtime, 'sendMessage').mockResolvedValue(undefined as never)
		const listener = vi.fn()
		onBroadcast(listener)
		broadcast({ kind: 'vault.locked' })
		expect(listener).toHaveBeenCalledWith({ kind: 'vault.locked' })
	})

	it('swallows a synchronous throw', () => {
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(() => {
			throw new Error('No receiver')
		})
		expect(() => broadcast({ kind: 'vault.locked' })).not.toThrow()
	})
})
