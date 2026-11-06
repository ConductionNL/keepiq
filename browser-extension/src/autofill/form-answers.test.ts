import { describe, expect, it } from 'vitest'
import { answered, answeredOnlyHost } from './form-answers'

describe('answers', () => {
	it('adds an answer to the earlier ones for the same origin, echoing the origin', () => {
		const question = (origin: string, frameHosts: string[], insecure = false) => ({ insecure, host: new URL(origin).host, origin, frameHosts })
		expect(answered(undefined, question('https://shop.test', [], true))).toEqual({ insecure: true, frames: 'all', frameHosts: [], origin: 'https://shop.test' })
		expect(answered({ insecure: true, frames: 'all', frameHosts: ['a.test'], origin: 'https://shop.test' }, question('https://shop.test', ['a.test', 'b.test'])))
			.toEqual({ insecure: true, frames: 'all', frameHosts: ['a.test', 'b.test'], origin: 'https://shop.test' })
		for (const other of ['https://other.test', 'http://shop.test', 'https://shop.test:8443']) {
			expect(answered({ insecure: true, frames: 'all', frameHosts: ['a.test'], origin: other }, question('https://shop.test', ['b.test'])))
				.toEqual({ frames: 'all', frameHosts: ['b.test'], origin: 'https://shop.test' })
			expect(answered({ frames: 'sameSite', frameHosts: ['a.test'], origin: other }, question('https://shop.test', [], true)))
				.toEqual({ insecure: true, frames: 'all', frameHosts: [], origin: 'https://shop.test' })
		}
	})

	it('answers "Only {host}" as same-site frames only, and keeps it through a later insecure question', () => {
		const question = (frameHosts: string[], insecure = false) => ({ insecure, host: 'shop.test', origin: 'https://shop.test', frameHosts })
		const only = answeredOnlyHost(undefined, question(['pay.test'], true))
		expect(only).toEqual({ insecure: true, frames: 'sameSite', frameHosts: ['pay.test'], origin: 'https://shop.test' })
		expect(answered({ frames: 'sameSite', frameHosts: ['pay.test'], origin: 'https://shop.test' }, question([], true)))
			.toEqual({ insecure: true, frames: 'sameSite', frameHosts: ['pay.test'], origin: 'https://shop.test' })
		expect(answeredOnlyHost({ frames: 'all', frameHosts: ['a.test'], origin: 'https://other.test' }, question(['pay.test'])))
			.toEqual({ frames: 'sameSite', frameHosts: ['pay.test'], origin: 'https://shop.test' })
	})
})
