import { describe, expect, it } from 'vitest'
import { createQueue } from './queue'

describe('createQueue', () => {
	it('starts a change only after the previous one finished', async () => {
		const queued = createQueue()
		const log: string[] = []
		let release!: () => void
		const gate = new Promise<void>((resolve) => (release = resolve))
		const first = queued(async () => {
			log.push('first start')
			await gate
			log.push('first end')
		})
		const second = queued(async () => {
			log.push('second')
			return 2
		})
		await Promise.resolve()
		release()
		await first
		expect(await second).toBe(2)
		expect(log).toEqual(['first start', 'first end', 'second'])
	})

	it('keeps going after a change fails, and hands the failure to its caller', async () => {
		const queued = createQueue()
		await expect(queued(() => Promise.reject(new Error('boom')))).rejects.toThrow('boom')
		expect(await queued(async () => 'next')).toBe('next')
	})
})
