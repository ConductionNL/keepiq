import { describe, expect, it } from 'vitest'
import { defaultOptions } from '@/src/generator/options'
import { POPUP_REQUEST_KINDS } from '@/src/messages'
import { fakeBackground } from './testing'

describe('fakeBackground', () => {
	it('answers every popup request itself, not with the state reply the other messages get', async () => {
		const reply = fakeBackground() as unknown as (message: Record<string, unknown>) => Promise<unknown>
		const stateReply = await reply({ kind: 'state.get' })
		for (const kind of POPUP_REQUEST_KINDS) {
			const answer = await reply({
				kind, id: 'i1', ids: ['i1'], fields: ['login'], patch: {}, options: defaultOptions(), entry: { kind: 'password', value: 'pw' },
				input: { payloadType: 'text', text: 'hi', maxViews: 1, ttlSeconds: 60 },
			})
			expect(answer, kind).toBeDefined()
			expect(answer, kind).not.toEqual(stateReply)
		}
	})
})
