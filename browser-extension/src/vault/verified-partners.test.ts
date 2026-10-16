import { beforeEach, describe, expect, it } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, setActive } from '@/src/accounts/store'
import { suiteRow } from '@/src/testing/vectors'
import { forgetVerifiedPartners, verifiedPartners } from './verified-partners'

const BOB = { shareId: 'fs-bob', recipientCloudId: 'bob@cloud.partner.example' }
const CAROL = { shareId: 'fs-carol', recipientCloudId: 'carol@cloud.partner.example' }
const VERIFIED = { fingerprint: 'ab'.repeat(32), spki: new Uint8Array([1]) }

/** A save of `accountId` that verified `share` and then failed or went through. */
function saved(outcome: 'failed' | 'succeeded', accountId = 'a1', share = BOB) {
	const save = verifiedPartners(accountId)
	save.add(share, VERIFIED)
	save[outcome]()
}
const kept = (accountId = 'a1', share = BOB) => verifiedPartners(accountId).get(share)

const account = (uid: string) => addAccount({
	serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw',
}, { ...suiteRow })

beforeEach(() => {
	fakeBrowser.reset()
	forgetVerifiedPartners()
})

describe('verifiedPartners', () => {
	it('keeps what a failed save verified, and nothing a save that went through verified', () => {
		saved('failed')
		saved('succeeded', 'a1', CAROL)
		expect(kept()).toEqual(VERIFIED)
		expect(kept('a1', CAROL)).toBeUndefined()
	})

	it('drops only what a save that went through used', () => {
		saved('failed')
		saved('failed', 'a1', CAROL)
		const save = verifiedPartners('a1')
		save.get(BOB)
		save.succeeded()
		expect(kept()).toBeUndefined()
		expect(kept('a1', CAROL)).toEqual(VERIFIED)
	})

	it('forgets them on an account switch, not on choosing the active account again', async () => {
		const alice = await account('alice')
		const bob = await account('bob')
		saved('failed', bob.id)
		await setActive(bob.id)
		expect(kept(bob.id)).toEqual(VERIFIED)
		await setActive(alice.id)
		expect(kept(bob.id)).toBeUndefined()
	})

	it('forgets them when an added account becomes the active one', async () => {
		saved('failed')
		await account('alice')
		expect(kept()).toBeUndefined()
	})
})
