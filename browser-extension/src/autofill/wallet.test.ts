import { describe, expect, it } from 'vitest'
import { FORM_ROLES, type FormRole } from '@/src/messages'
import { blockedRow, secretRow, typeRows } from '@/src/testing/vault'
import { answeredOnlyHost } from './form-answers'
import { isInsecureUrl, membersFor, parseConfirmation, parseExpiry, planFrames, walletCandidates, walletKind, type ProbedFrame } from './wallet'

const CARD = { number: '4111 1111 1111 1111', expiry: '3/2027', cvv: '123', pin: '9876', cardholder: 'Anna de Vries' }
const IDENTITY = { firstName: 'Anna', lastName: 'de Vries', address: 'Kerkstraat 1\r\n\n 1234 AB Utrecht ', phone: '0612345678', email: 'anna@example.test', bsn: '123456782' }

describe('walletKind', () => {
	it('reads cards and identities by the system type only', () => {
		const types = [...typeRows, { id: 't-mine', name: 'card', scope: 'user', label: 'Card' }]
		expect(walletKind({ typeId: 't-card' }, types)).toBe('card')
		expect(walletKind({ typeId: 't-identity' }, types)).toBe('identity')
		expect(walletKind({ typeId: 't-mine' }, types)).toBeNull()
		expect(walletKind({ typeId: 't-login' }, types)).toBeNull()
		expect(walletKind({ typeId: null }, types)).toBeNull()
	})
})

describe('walletCandidates', () => {
	it('lists every unblocked card whatever its url, last used first, then by name', () => {
		const secrets = [
			secretRow({ id: 'b', name: 'Bank', url: 'https://bank.test', typeId: 't-card' }),
			secretRow({ id: 'a', name: 'Alpha', url: null, typeId: 't-card' }),
			secretRow({ id: 'p', name: 'Personal', url: 'https://other.test', typeId: 't-card' }),
			secretRow({ id: 'i', name: 'Me', typeId: 't-identity' }),
			blockedRow({ id: 'x', name: 'Blocked', typeId: 't-card' }),
		]
		expect(walletCandidates('card', { secrets, types: typeRows }, { p: 5 }).map((row) => row.id)).toEqual(['p', 'a', 'b'])
		expect(walletCandidates('identity', { secrets, types: typeRows }, {}).map((row) => row.id)).toEqual(['i'])
	})
})

describe('parseExpiry', () => {
	it.each([
		['03/27', '03', '2027'], ['03/2027', '03', '2027'], ['3/27', '03', '2027'], ['3/2027', '03', '2027'],
		['03-27', '03', '2027'], ['03.2027', '03', '2027'], ['03 27', '03', '2027'], ['03 / 27', '03', '2027'],
		['0327', '03', '2027'], ['032027', '03', '2027'], ['2027-03', '03', '2027'], ['2027-3', '03', '2027'], [' 12/30 ', '12', '2030'],
	])('reads %s', (raw, month, year) => {
		expect(parseExpiry(raw)).toEqual({ month, year })
	})

	it.each(['13/27', '00/27', 'end of 2027', '', '03/7', '2027/03', '202703'])('reads nothing from %j', (raw) => {
		expect(parseExpiry(raw)).toBeNull()
	})
})

describe('membersFor', () => {
	it('returns only the values the roles ask for', () => {
		expect(membersFor('card', CARD, ['cardholder'])).toEqual({ cardholder: 'Anna de Vries' })
		expect(membersFor('card', CARD, ['number', 'expMonth', 'securityCode', 'brand'])).toEqual({ number: '4111111111111111', expMonth: '03', cvv: '123', brand: 'Visa', brandId: 'visa' })
		expect(membersFor('card', CARD, ['expiry'])).toEqual({ expMonth: '03', expYear: '2027' })
		expect(membersFor('card', CARD, ['firstName'])).toEqual({})
	})

	it('derives the full name, the address and its first line', () => {
		expect(membersFor('identity', IDENTITY, ['fullName', 'streetAddress', 'addressLine1'])).toEqual({
			fullName: 'Anna de Vries', streetAddress: 'Kerkstraat 1\n1234 AB Utrecht', addressLine1: 'Kerkstraat 1',
		})
		expect(membersFor('identity', { lastName: 'de Vries' }, ['fullName'])).toEqual({ fullName: 'de Vries' })
	})

	it('leaves out empty members, an unreadable expiry and a brand it cannot derive', () => {
		expect(membersFor('card', { number: '9999', expiry: 'end of 2027' }, ['number', 'expiry', 'brand', 'cardholder'])).toEqual({ number: '9999' })
	})

	it('never returns the PIN or the BSN, for any roles', () => {
		const all: FormRole[] = [...FORM_ROLES.card, ...FORM_ROLES.identity]
		const sent = JSON.stringify([membersFor('card', { ...CARD, ...IDENTITY }, all), membersFor('identity', { ...CARD, ...IDENTITY }, all)])
		expect(sent).not.toContain(CARD.pin)
		expect(sent).not.toContain(IDENTITY.bsn)
	})
})

describe('isInsecureUrl', () => {
	it.each([
		['http://shop.test/', true], ['https://shop.test/', false], ['http://localhost:8100/', false],
		['http://127.0.0.1:8100/', false], ['http://127.4.5.6/', false], ['http://[::1]/', false], ['http://shop.localhost/', false], ['http://localhost.shop.test/', true], ['nonsense', true],
	])('%s is insecure: %s', (url, insecure) => {
		expect(isInsecureUrl(url)).toBe(insecure)
	})
})

const frame = (frameId: number, url: string, roles: FormRole[] = ['number']): ProbedFrame => ({ frameId, url, roles })

describe('planFrames', () => {
	const top = frame(0, 'https://shop.test/checkout', ['cardholder'])

	it('lets frames on the top frame’s base domain take part without a question', () => {
		const pay = frame(3, 'https://pay.shop.test/card')
		expect(planFrames({ kind: 'card', topUrl: top.url, frames: [top, pay, frame(4, 'https://ads.test/', [])] })).toEqual({ question: null, receivers: [top, pay] })
	})

	it('names each cross-site host and asks', () => {
		const stripe = [frame(3, 'https://js.stripe.test/a'), frame(4, 'https://js.stripe.test/b', ['securityCode'])]
		const plan = planFrames({ kind: 'card', topUrl: top.url, frames: [top, ...stripe] })
		expect(plan.question).toEqual({ insecure: false, host: 'shop.test', origin: 'https://shop.test', frameHosts: ['js.stripe.test'] })
	})

	it('sends cross-site frames nothing after "Only {host}", and nothing at all when only they have fields', () => {
		const stripe = frame(3, 'https://js.stripe.test/')
		const sameSite = { frames: 'sameSite' as const, frameHosts: ['js.stripe.test'], origin: 'https://shop.test' }
		expect(planFrames({ kind: 'card', topUrl: top.url, frames: [top, stripe], confirmed: sameSite })).toEqual({ question: null, receivers: [top] })
		expect(planFrames({ kind: 'card', topUrl: top.url, frames: [{ ...top, roles: [] }, stripe], confirmed: sameSite }).receivers).toEqual([])
	})

	it('asks again about a host the answer did not name', () => {
		const frames = [top, frame(3, 'https://js.stripe.test/'), frame(5, 'https://ads.test/')]
		const plan = planFrames({ kind: 'card', topUrl: top.url, frames, confirmed: { frames: 'all', frameHosts: ['js.stripe.test'], origin: 'https://shop.test' } })
		expect(plan.question?.frameHosts).toEqual(['js.stripe.test', 'ads.test'])
		expect(planFrames({ kind: 'card', topUrl: top.url, frames, confirmed: { frames: 'all', frameHosts: ['js.stripe.test', 'ads.test'], origin: 'https://shop.test' } }).question).toBeNull()
	})

	it('takes an answer only on the top origin it was given for', () => {
		const stripe = frame(3, 'https://js.stripe.test/')
		const confirmed = { insecure: true as const, frames: 'all' as const, frameHosts: ['js.stripe.test'], origin: 'https://shop.test' }
		expect(planFrames({ kind: 'card', topUrl: top.url, frames: [top, stripe], confirmed }).question).toBeNull()
		for (const topUrl of ['https://evil.test/checkout', 'http://evil.test/checkout']) {
			const evil = { ...top, url: topUrl }
			expect(planFrames({ kind: 'card', topUrl, frames: [evil, stripe], confirmed }).question)
				.toEqual({ insecure: topUrl.startsWith('http:'), host: 'evil.test', origin: new URL(topUrl).origin, frameHosts: ['js.stripe.test'] })
		}
		// Same host, another scheme or port: the answer given on https://shop.test is no answer there.
		for (const topUrl of ['http://shop.test/checkout', 'https://shop.test:8443/checkout']) {
			const other = { ...top, url: topUrl }
			expect(planFrames({ kind: 'card', topUrl, frames: [other, stripe], confirmed }).question?.frameHosts).toEqual(['js.stripe.test'])
		}
		const withoutOrigin = { ...confirmed, origin: undefined }
		expect(planFrames({ kind: 'card', topUrl: top.url, frames: [top, stripe], confirmed: withoutOrigin as never }).question?.frameHosts).toEqual(['js.stripe.test'])
	})

	it('asks before a card reaches an insecure frame, never before an identity', () => {
		const insecure = frame(0, 'http://shop.test/', ['cardholder'])
		expect(planFrames({ kind: 'card', topUrl: insecure.url, frames: [insecure] }).question).toEqual({ insecure: true, host: 'shop.test', origin: 'http://shop.test', frameHosts: [] })
		expect(planFrames({ kind: 'card', topUrl: insecure.url, frames: [insecure], confirmed: { insecure: true, frames: 'all', frameHosts: [], origin: 'http://shop.test' } }).question).toBeNull()
		expect(planFrames({ kind: 'identity', topUrl: insecure.url, frames: [{ ...insecure, roles: ['email'] }] }).question).toBeNull()
		const local = frame(0, 'http://localhost:8100/card.html')
		expect(planFrames({ kind: 'card', topUrl: local.url, frames: [local] }).question).toBeNull()
	})

	it('asks nothing when no frame has fields', () => {
		expect(planFrames({ kind: 'card', topUrl: 'http://shop.test/', frames: [frame(0, 'http://shop.test/', []), frame(3, 'https://ads.test/', [])] }))
			.toEqual({ question: null, receivers: [] })
	})
})

describe('confirmations', () => {
	it('rebuilds a confirmation from its known members only', () => {
		expect(parseConfirmation({ insecure: true, frames: 'all', frameHosts: ['a.test'], origin: 'https://shop.test', extra: 1 }))
			.toEqual({ insecure: true, frames: 'all', frameHosts: ['a.test'], origin: 'https://shop.test' })
		expect(parseConfirmation({ insecure: 'yes', frames: 'sameSite', frameHosts: [], origin: 'https://shop.test' })).toEqual({ frames: 'sameSite', frameHosts: [], origin: 'https://shop.test' })
		const invalid = [
			undefined, null, {}, { frames: 'some', frameHosts: [] }, { frames: 'all', frameHosts: [1] }, { frames: 'all' }, { frames: 'all', frameHosts: [] },
			{ frames: 'all', frameHosts: [], origin: 1 }, { frames: 'all', frameHosts: [], host: 'shop.test' },
		]
		for (const value of invalid) expect(parseConfirmation(value)).toBeUndefined()
	})

	it('fills no cross-site frame after "Only {host}"', () => {
		const frames = [frame(0, 'https://shop.test/', ['cardholder']), frame(3, 'https://pay.test/', ['number'])]
		const first = planFrames({ kind: 'card', topUrl: 'https://shop.test/', frames })
		expect(first.question).toEqual({ insecure: false, host: 'shop.test', origin: 'https://shop.test', frameHosts: ['pay.test'] })
		const confirmed = answeredOnlyHost(undefined, first.question!)
		expect(planFrames({ kind: 'card', topUrl: 'https://shop.test/', frames, confirmed })).toEqual({ question: null, receivers: [frames[0]] })
	})
})
