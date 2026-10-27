import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fakeBrowser } from 'wxt/testing/fake-browser'
import { addAccount, getAccount, markLoggedOut, removeAccount, removeAllAccounts, setActive, suiteKey, updateAccount } from '@/src/accounts/store'
import { writeSettings } from '@/src/accounts/settings'
import { fromBase64, importPublicKey, pemToPkcs8, rsaEncrypt, toBase64 } from '@/src/crypto'
import { decodeEnvelope, encodeEnvelope } from '@/src/crypto/envelope'
import { decryptPrivateKeyPem, deriveUnlockKey } from '@/src/crypto/kdf'
import { envelope, suiteRow } from '@/src/testing/vectors'
import { provenCertificateKey, readProvenCertificate } from './certificate'
import { generatorSealedKey, getKey, hasKey, LAST_TAB_KEY, sessionValues } from './key-store'
import { forgetPin, hasPin, hasSeal, openSeal, pinGeneration, pinKey, pinSealKey, readPin, sealPin, storePin, storePinCopies, wrapPin } from './pin'
import { enforce } from './timeout'
import { blockUnlock, checkSuite, enablePin, lock, lockAll, logoutForTimeout, unlock } from './unlock'

const fetchMock = vi.fn<typeof fetch>()
const password = { type: 'masterPassword' as const, masterPassword: envelope.password }

async function newAccount(uid = 'alice') {
	return addAccount({
		serverUrl: 'https://cloud.example.org', uid, loginName: uid, displayName: uid, email: null, avatarDataUrl: null, appPassword: 'pw', keepiqVersion: '0.3.12',
	}, { ...suiteRow })
}

/** The suite as cached before the master password changed: the current password no longer opens it. */
function staleSuite() {
	const decoded = decodeEnvelope(envelope.envelope)
	decoded.ciphertextWithTag[decoded.ciphertextWithTag.length - 1]! ^= 1
	return { ...suiteRow, privateKey: encodeEnvelope(decoded), unlockKeyEpoch: 0 }
}

/** The suite's key envelope re-encrypted around `change(pem)`, under the same master password. */
async function rewrap(change: (pem: string) => string): Promise<string> {
	const { salt, iv } = decodeEnvelope(envelope.envelope)
	const pem = change(await decryptPrivateKeyPem(envelope.envelope, envelope.password))
	const key = await deriveUnlockKey(envelope.password, salt)
	const ciphertextWithTag = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, new TextEncoder().encode(pem)))
	return encodeEnvelope({ salt, iv, ciphertextWithTag })
}

beforeEach(() => {
	fakeBrowser.reset()
	vi.stubGlobal('fetch', fetchMock)
	fetchMock.mockReset()
})

describe('unlock', () => {
	it('opens the cached suite without a request', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(fetchMock).not.toHaveBeenCalled()
		expect(await hasKey(account.id)).toBe(true)
		expect(await getKey(account.id)).not.toBeNull()
	})

	it('reads the Keepiq version again when the stored one is too old, and unlocks once it was updated', async () => {
		const account = await newAccount()
		await updateAccount(account.id, { keepiqVersion: '0.3.11' })
		fetchMock.mockResolvedValue(new Response(JSON.stringify({ status: 'ok', version: '0.3.12' })))
		await unlock(account.id, password)
		expect(await hasKey(account.id)).toBe(true)
		expect((await getAccount(account.id))?.keepiqVersion).toBe('0.3.12')
	})

	it('refuses a Keepiq older than the minimum and stays locked', async () => {
		const account = await newAccount()
		await updateAccount(account.id, { keepiqVersion: '0.3.11' })
		fetchMock.mockResolvedValue(new Response(JSON.stringify({ status: 'ok', version: '0.3.11' })))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'keepiq_outdated' })
		expect(await hasKey(account.id)).toBe(false)
	})

	it('refuses a stored old version offline, but not a missing one', async () => {
		const account = await newAccount()
		fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
		await updateAccount(account.id, { keepiqVersion: '0.3.11' })
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'keepiq_outdated' })
		await updateAccount(account.id, { keepiqVersion: undefined })
		await unlock(account.id, password)
		expect(await hasKey(account.id)).toBe(true)
	})

	it('treats a server error without a version like being offline', async () => {
		const account = await newAccount()
		fetchMock.mockResolvedValue(new Response('<html>Maintenance</html>', { status: 503 }))
		await updateAccount(account.id, { keepiqVersion: '0.3.11' })
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'keepiq_outdated' })
		await updateAccount(account.id, { keepiqVersion: undefined })
		await unlock(account.id, password)
		expect(await hasKey(account.id)).toBe(true)
		expect((await getAccount(account.id))?.keepiqVersion).toBeUndefined()
	})

	it('names a vault key that is not one PEM block', async () => {
		const account = await newAccount()
		await fakeBrowser.storage.local.set({ [suiteKey(account.id)]: { ...suiteRow, privateKey: await rewrap((pem) => `${pem}\ntrailing text`) } })
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'key_unreadable' })
		expect(await hasKey(account.id)).toBe(false)
	})

	it('rejects a wrong master password and stays locked', async () => {
		const account = await newAccount()
		await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
			.rejects.toMatchObject({ code: 'invalid_master_password' })
		expect(await hasKey(account.id)).toBe(false)
	})

	describe('after the master password changed in the web app', () => {
		it('fetches the new suite once and unlocks with the new password', async () => {
			const account = await newAccount()
			await browser.storage.local.set({ [suiteKey(account.id)]: staleSuite() })
			fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
			await unlock(account.id, password)
			expect(fetchMock).toHaveBeenCalledOnce()
			expect(await hasKey(account.id)).toBe(true)
			expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toEqual(suiteRow)
		})

		it('stays invalid when the server has the same suite', async () => {
			const account = await newAccount()
			fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code: 'invalid_master_password' })
			expect(fetchMock).toHaveBeenCalledOnce()
		})

		it.each([
			['unlock_blocked', [{ ...suiteRow, privateKey: undefined, unlockBlocked: 'two_factor_required' }]],
			['no_active_suite', []],
		])('reports %s from the check instead of a wrong password', async (code, rows) => {
			const account = await newAccount()
			fetchMock.mockResolvedValue(new Response(JSON.stringify(rows)))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code })
		})

		it('reports a wrong password, not offline, when the check cannot reach the server', async () => {
			const account = await newAccount()
			fetchMock.mockRejectedValue(new TypeError('offline'))
			await expect(unlock(account.id, { type: 'masterPassword', masterPassword: envelope.wrongPassword }))
				.rejects.toMatchObject({ code: 'invalid_master_password' })
		})
	})

	it('fetches and caches the suite when nothing is cached', async () => {
		const account = await newAccount()
		await browser.storage.local.remove(suiteKey(account.id))
		fetchMock.mockResolvedValue(new Response(JSON.stringify([suiteRow])))
		await unlock(account.id, password)
		expect(fetchMock).toHaveBeenCalledOnce()
		expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toEqual(suiteRow)
	})

	it('reports an uncached suite while offline', async () => {
		const account = await newAccount()
		await browser.storage.local.remove(suiteKey(account.id))
		fetchMock.mockRejectedValue(new TypeError('offline'))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'offline_no_cache' })
	})

	it('refuses a logged out account', async () => {
		const account = await newAccount()
		await markLoggedOut(account.id)
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'session_revoked' })
		expect(fetchMock).not.toHaveBeenCalled()
	})
})

describe('the proven certificate', () => {
	const proven = (accountId: string) => readProvenCertificate(accountId)

	it('is kept at the unlock and through a lock', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(await proven(account.id)).toBe(suiteRow.certificate)
		await lock(account.id)
		await lockAll()
		expect(await proven(account.id)).toBe(suiteRow.certificate)
	})

	it('is not kept when the cached certificate is not the key\'s, and the one before goes', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		const { publicKey } = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-256' }, true, ['encrypt', 'decrypt'])
		const spki = toBase64(new Uint8Array(await crypto.subtle.exportKey('spki', publicKey)))
		// What a sync stores when the server swapped the certificate but kept the suite id and epoch.
		await browser.storage.local.set({ [suiteKey(account.id)]: { ...suiteRow, certificate: `-----BEGIN PUBLIC KEY-----\n${spki}\n-----END PUBLIC KEY-----` } })
		await lock(account.id)
		await unlock(account.id, password)
		expect(await proven(account.id)).toBeUndefined()
	})

	it.each([
		['a logout', (id: string) => markLoggedOut(id)],
		['the account removal', (id: string) => removeAccount(id)],
		['the two-factor block', (id: string) => blockUnlock(id)],
	])('goes at %s', async (_, end) => {
		const account = await newAccount()
		await unlock(account.id, password)
		await end(account.id)
		expect(await browser.storage.local.get(provenCertificateKey(account.id))).toEqual({})
	})

	/** `end` runs to completion right before the unlock writes the certificate it proved. */
	function endAtProof(end: () => Promise<unknown>) {
		const set = browser.storage.local.set.bind(browser.storage.local)
		let ended = false
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async (values) => {
			if (!ended && Object.keys(values).some((key) => key.startsWith('provenCertificate.'))) {
				ended = true
				await end()
			}
			return set(values)
		})
	}

	it('goes at a logout that lands as the unlock proves it', async () => {
		const account = await newAccount()
		endAtProof(() => markLoggedOut(account.id))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'session_revoked' })
		expect(await browser.storage.local.get(provenCertificateKey(account.id))).toEqual({})
	})

	it('goes at a two-factor block that lands as the unlock proves it', async () => {
		const account = await newAccount()
		const withheld: Partial<typeof suiteRow> = { ...suiteRow }
		delete withheld.privateKey
		fetchMock.mockResolvedValue(new Response(JSON.stringify([{ ...withheld, unlockBlocked: 'two_factor_required' }])))
		endAtProof(() => blockUnlock(account.id))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'unlock_blocked' })
		expect(await hasKey(account.id)).toBe(false)
		expect(await browser.storage.local.get(provenCertificateKey(account.id))).toEqual({})
	})
})

describe('lock and log out', () => {
	it('lock keeps the app password and the suite', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await lock(account.id)
		expect(await hasKey(account.id)).toBe(false)
		expect((await getAccount(account.id))?.appPassword).toBe('pw')
		expect(await browser.storage.local.get(suiteKey(account.id))).toHaveProperty(suiteKey(account.id))
	})

	it('lock all locks every account', async () => {
		const a = await newAccount('a')
		const b = await newAccount('b')
		await unlock(a.id, password)
		await unlock(b.id, password)
		await lockAll()
		expect([await hasKey(a.id), await hasKey(b.id)]).toEqual([false, false])
	})

	it('the timeout action Log out keeps the identity', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await logoutForTimeout(account.id)
		expect(await getAccount(account.id)).toMatchObject({ uid: 'alice', appPassword: null })
		expect(await hasKey(account.id)).toBe(false)
	})
})

describe('checkSuite', () => {
	it('locks when the epoch changed', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(await checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: 2 })).toBe(true)
		expect(await hasKey(account.id)).toBe(false)
		expect((await browser.storage.local.get(suiteKey(account.id)))[suiteKey(account.id)]).toMatchObject({ unlockKeyEpoch: 2 })
	})

	it('leaves an unchanged suite unlocked', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		expect(await checkSuite(account.id, { ...suiteRow })).toBe(false)
		expect(await hasKey(account.id)).toBe(true)
	})
})

describe('announcing a lock', () => {
	/** Records storage writes and broadcasts in the order they happen. */
	function trace() {
		const events: string[] = []
		vi.spyOn(browser.storage.local, 'set').mockImplementation(async () => void events.push('write'))
		vi.spyOn(browser.runtime, 'sendMessage').mockImplementation(async (message) => void events.push((message as unknown as { kind: string }).kind))
		return events
	}

	it('tells the popup only after a logout finished writing', async () => {
		const account = await newAccount()
		const events = trace()
		await markLoggedOut(account.id, true)
		expect(events.at(-1)).toBe('vault.locked')
		expect(events).toContain('write')
	})

	it('resets the remembered tab only when the active account locks', async () => {
		const alice = await newAccount('alice')
		const bob = await newAccount('bob')
		await setActive(alice.id)
		await sessionValues.set({ [LAST_TAB_KEY]: 'send' })
		await lock(bob.id)
		expect(await sessionValues.get(LAST_TAB_KEY)).toBe('send')
		await lock(alice.id)
		expect(await sessionValues.get(LAST_TAB_KEY)).toBeUndefined()
	})
})

describe('PIN', () => {
	const pin = (value = '246813') => ({ type: 'pin' as const, pin: value })
	const MINUTE = 60_000

	/** An unlocked account with a PIN, wrapped as `enablePin` would. */
	/** An unlocked account with both PIN copies, as `enablePin` leaves them. */
	async function withPin(uid = 'alice') {
		const account = await newAccount(uid)
		await unlock(account.id, password)
		const bytes = pemToPkcs8(envelope.privateKeyPem)
		await storePinCopies(account.id, await wrapPin('246813', bytes, suiteRow), await sealPin(account.id, '246813', bytes, suiteRow), pinGeneration(account.id))
		return account.id
	}

	async function expectNoCopy(id: string) {
		expect((await browser.storage.session.get(pinKey(id)))[pinKey(id)]).toBeUndefined()
		expect((await browser.storage.local.get(pinSealKey(id)))[pinSealKey(id)]).toBeUndefined()
	}

	describe('setting', () => {
		it('needs the master password, keeps the wrapped key in session only and seals the PIN to the private key', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			const before = await browser.storage.local.get(null)
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: true })
			expect(await hasPin(account.id)).toBe(true)
			const { [pinSealKey(account.id)]: seal, ...rest } = await browser.storage.local.get(null)
			expect(rest).toEqual(before)
			expect(JSON.stringify(seal)).not.toContain('246813')
			expect(await openSeal(account.id, seal, pemToPkcs8(envelope.privateKeyPem), suiteRow)).toBe('246813')
			await lock(account.id)
			await unlock(account.id, pin())
			expect(await hasKey(account.id)).toBe(true)
		})

		it('sets neither copy when the PIN is forgotten while it is wrapped', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			const derive = crypto.subtle.deriveKey.bind(crypto.subtle)
			let forgotten = false
			vi.spyOn(crypto.subtle, 'deriveKey').mockImplementation(async (...args) => {
				if (!forgotten) {
					forgotten = true
					await forgetPin(account.id)
				}
				return derive(...args)
			})
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: false, code: 'locked' })
			await expectNoCopy(account.id)
		})

		it('accepts six characters of any kind, counted as code points', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			expect(await enablePin(account.id, '😀a😀b😀c', envelope.password)).toEqual({ ok: true })
		})

		it('refuses a wrong master password', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			expect(await enablePin(account.id, '246813', envelope.wrongPassword)).toEqual({ ok: false, code: 'invalid_master_password' })
			expect(await hasPin(account.id)).toBe(false)
		})

		it.each(['24681', '', '😀😀😀😀😀'])('refuses a PIN shorter than six characters (%j)', async (short) => {
			const account = await newAccount()
			await unlock(account.id, password)
			expect(await enablePin(account.id, short, envelope.password)).toEqual({ ok: false, code: 'pin_too_short' })
			expect(await hasPin(account.id)).toBe(false)
		})

		it('refuses a locked account', async () => {
			const account = await newAccount()
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: false, code: 'locked' })
			expect(await hasPin(account.id)).toBe(false)
		})

		it('sets nothing when the account locks while its status is read', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			const get = browser.storage.session.get.bind(browser.storage.session)
			let locked = false
			vi.spyOn(browser.storage.session, 'get').mockImplementation(async (keys) => {
				const values = await get(keys)
				if (!locked && keys === `privateKeyPkcs8.${account.id}`) {
					locked = true
					await lock(account.id)
				}
				return values
			})
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: false, code: 'locked' })
			expect(await hasPin(account.id)).toBe(false)
		})

		it('sets nothing when the account locks while the PIN is wrapped', async () => {
			const account = await newAccount()
			await unlock(account.id, password)
			const derive = crypto.subtle.deriveKey.bind(crypto.subtle)
			let locked = false
			vi.spyOn(crypto.subtle, 'deriveKey').mockImplementation(async (...args) => {
				if (!locked) {
					locked = true
					await lock(account.id)
				}
				return derive(...args)
			})
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: false, code: 'locked' })
			await expectNoCopy(account.id)
		})
	})

	describe('unlocking', () => {
		it('restores the key after Lock now without a request', async () => {
			const id = await withPin()
			await lock(id)
			await unlock(id, pin())
			expect(fetchMock).not.toHaveBeenCalled()
			expect(await hasKey(id)).toBe(true)
			expect(await getKey(id)).not.toBeNull()
		})

		it('proves the certificate, as a master password unlock does', async () => {
			const id = await withPin()
			await lock(id)
			await browser.storage.local.remove(provenCertificateKey(id))
			await unlock(id, pin())
			expect(await readProvenCertificate(id)).toBe(suiteRow.certificate)
		})

		it('says how many attempts are left after a wrong PIN', async () => {
			const id = await withPin()
			await lock(id)
			await expect(unlock(id, pin('0000'))).rejects.toMatchObject({ code: 'invalid_pin', attemptsLeft: 4 })
			expect(await hasKey(id)).toBe(false)
		})

		it('forgets the PIN on the fifth wrong one in a row', async () => {
			const id = await withPin()
			await lock(id)
			for (const left of [4, 3, 2, 1]) await expect(unlock(id, pin('0000'))).rejects.toMatchObject({ code: 'invalid_pin', attemptsLeft: left })
			await expect(unlock(id, pin('0000'))).rejects.toMatchObject({ code: 'pin_forgotten' })
			await expectNoCopy(id)
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'pin_forgotten' })
		}, 20_000)

		it('resets the count after a correct PIN', async () => {
			const id = await withPin()
			await lock(id)
			for (let i = 0; i < 4; i++) await unlock(id, pin('0000')).catch(() => {})
			await unlock(id, pin())
			await lock(id)
			await expect(unlock(id, pin('0000'))).rejects.toMatchObject({ code: 'invalid_pin', attemptsLeft: 4 })
		}, 20_000)

		it('resets the count after a master password unlock', async () => {
			const id = await withPin()
			await lock(id)
			for (let i = 0; i < 4; i++) await unlock(id, pin('0000')).catch(() => {})
			await unlock(id, password)
			await lock(id)
			await expect(unlock(id, pin('0000'))).rejects.toMatchObject({ code: 'invalid_pin', attemptsLeft: 4 })
		}, 20_000)

		it('forgets a PIN whose count already reached the limit, without checking it', async () => {
			const id = await withPin()
			// A worker killed during the fifth attempt counted it but never forgot the PIN.
			await storePin(id, { ...(await readPin(id))!, failures: 5 }, pinGeneration(id))
			await lock(id)
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'pin_forgotten' })
			await expectNoCopy(id)
			expect(await hasKey(id)).toBe(false)
		})

		it('forgets a PIN whose record does not open', async () => {
			const id = await withPin()
			await storePin(id, { ...(await readPin(id))!, envelope: 'AAAA' }, pinGeneration(id))
			await lock(id)
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'pin_forgotten' })
			expect(await hasPin(id)).toBe(false)
		})

		it('counts a PIN that is not a string as a wrong one', async () => {
			const id = await withPin()
			await lock(id)
			await expect(unlock(id, { type: 'pin', pin: undefined as never })).rejects.toMatchObject({ code: 'invalid_pin', attemptsLeft: 4 })
		})

		it('does not put back the old PIN over one set while it was tried', async () => {
			const id = await withPin()
			const encrypt = crypto.subtle.encrypt.bind(crypto.subtle)
			let wrapped!: () => void
			const newPinWrapped = new Promise<void>((resolve) => (wrapped = resolve))
			vi.spyOn(crypto.subtle, 'encrypt').mockImplementation(async (...args) => {
				const result = await encrypt(...args)
				wrapped()
				return result
			})
			// The attempt has counted itself; the new PIN is set before it puts the record back.
			const set = browser.storage.session.set.bind(browser.storage.session)
			let setting: Promise<unknown> | undefined
			vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
				const record = (values as Record<string, unknown>)[pinKey(id)]
				if (!setting && typeof record === 'string' && (JSON.parse(record) as { failures: number }).failures === 1) {
					await set(values)
					setting = enablePin(id, '135790', envelope.password)
					await newPinWrapped
					await new Promise((resolve) => setTimeout(resolve, 20))
					return
				}
				return set(values)
			})
			await unlock(id, pin())
			expect(await setting).toEqual({ ok: true })
			await lock(id)
			await unlock(id, pin('135790'))
			expect(await hasKey(id)).toBe(true)
		})

		it('counts two attempts sent at once', async () => {
			const id = await withPin()
			await lock(id)
			const results = await Promise.allSettled([unlock(id, pin('0000')), unlock(id, pin('1111'))])
			expect(results.map((r) => r.status === 'rejected' && (r.reason as { attemptsLeft?: number }).attemptsLeft).sort()).toEqual([3, 4])
			expect((await readPin(id))?.failures).toBe(2)
		})

		it('counts the attempt before the PIN is checked', async () => {
			const id = await withPin()
			await lock(id)
			const decrypt = crypto.subtle.decrypt.bind(crypto.subtle)
			let counted: number | undefined
			vi.spyOn(crypto.subtle, 'decrypt').mockImplementation(async (...args) => {
				counted ??= (await readPin(id))?.failures
				return decrypt(...args)
			})
			await unlock(id, pin('0000')).catch(() => {})
			expect(counted).toBe(1)
		})

		it('forgets a PIN made for another vault key', async () => {
			const id = await withPin()
			await lock(id)
			await browser.storage.local.set({ [suiteKey(id)]: { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 } })
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'pin_key_changed' })
			expect(await hasPin(id)).toBe(false)
		})

		it('reports a PIN that is gone as forgotten', async () => {
			const account = await newAccount()
			await expect(unlock(account.id, pin())).rejects.toMatchObject({ code: 'pin_forgotten' })
		})

		it('refuses a logged out account', async () => {
			const id = await withPin()
			await markLoggedOut(id)
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'session_revoked' })
		})
	})

	describe('after a browser restart', () => {
		/** Session storage goes with the browser; `storage.local` stays. */
		const restart = () => browser.storage.session.clear()
		const settle = () => new Promise((resolve) => setTimeout(resolve, 20))

		/** Holds the rebuild's PBKDF2, the one after the master password's, until `release`. */
		function holdRebuild() {
			const derive = crypto.subtle.deriveKey.bind(crypto.subtle)
			const encrypt = crypto.subtle.encrypt.bind(crypto.subtle)
			let release!: () => void
			const gate = new Promise<void>((resolve) => (release = resolve))
			let reached!: () => void
			const held = new Promise<void>((resolve) => (reached = resolve))
			let wrapped!: () => void
			const done = new Promise<void>((resolve) => (wrapped = resolve))
			let pbkdf2 = 0
			vi.spyOn(crypto.subtle, 'deriveKey').mockImplementation(async (params, ...rest) => {
				if ((params as { name: string }).name === 'PBKDF2' && ++pbkdf2 === 2) {
					reached()
					await gate
				}
				return derive(params, ...rest)
			})
			vi.spyOn(crypto.subtle, 'encrypt').mockImplementation(async (...args) => {
				const result = await encrypt(...args)
				if (pbkdf2 >= 2) wrapped()
				return result
			})
			return { held, release, done }
		}

		it('answers the unlock before the PIN is rebuilt, and the same PIN then unlocks', async () => {
			const id = await withPin()
			await lock(id)
			await restart()
			const rebuild = holdRebuild()
			await unlock(id, password)
			expect(await hasKey(id)).toBe(true)
			expect(await hasPin(id)).toBe(false)
			await rebuild.held
			rebuild.release()
			await vi.waitFor(async () => expect(await hasPin(id)).toBe(true))
			expect(await hasSeal(id)).toBe(true)
			await lock(id)
			await unlock(id, pin())
			expect(await hasKey(id)).toBe(true)
		})

		it.each<[string, (id: string) => Promise<unknown>]>([
			['a flipped byte', async (id) => {
				const seal = (await browser.storage.local.get(pinSealKey(id)))[pinSealKey(id)] as { ciphertext: string }
				const bytes = fromBase64(seal.ciphertext)
				bytes[0]! ^= 1
				await browser.storage.local.set({ [pinSealKey(id)]: { ...seal, ciphertext: toBase64(bytes) } })
			}],
			['a stale epoch', async (id) => {
				const bytes = pemToPkcs8(envelope.privateKeyPem)
				await browser.storage.local.set({ [pinSealKey(id)]: await sealPin(id, '246813', bytes, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch - 1 }) })
			}],
			['a PIN encrypted to the public key', async (id) => {
				const ciphertext = await rsaEncrypt('111111', await importPublicKey(envelope.certificatePem))
				const random = (n: number) => toBase64(crypto.getRandomValues(new Uint8Array(n)))
				await browser.storage.local.set({ [pinSealKey(id)]: { v: 1, salt: random(16), iv: random(12), ciphertext, suiteId: suiteRow.id, unlockKeyEpoch: suiteRow.unlockKeyEpoch } })
			}],
			['a changed account in the AAD', async (id) => {
				await browser.storage.local.set({ [pinSealKey(id)]: await sealPin('someone-else', '111111', pemToPkcs8(envelope.privateKeyPem), suiteRow) })
			}],
		])('deletes a seal with %s and rebuilds no PIN', async (_, tamper) => {
			const id = await withPin()
			await lock(id)
			await restart()
			await tamper(id)
			await unlock(id, password)
			await vi.waitFor(async () => expect(await hasSeal(id)).toBe(false))
			expect(await hasPin(id)).toBe(false)
		})

		it('deletes another account\'s seal copied over this one\'s', async () => {
			const a = await withPin('a')
			const b = await newAccount('b')
			await lock(a)
			await restart()
			await browser.storage.local.set({ [pinSealKey(b.id)]: (await browser.storage.local.get(pinSealKey(a)))[pinSealKey(a)] })
			await unlock(b.id, password)
			await vi.waitFor(async () => expect(await hasSeal(b.id)).toBe(false))
			expect(await hasPin(b.id)).toBe(false)
			expect(await hasSeal(a)).toBe(true)
		})

		it.each<[string, (id: string) => Promise<unknown>]>([
			['a logout', (id) => markLoggedOut(id)],
			['an account removal', (id) => removeAccount(id)],
			['a new epoch', (id) => checkSuite(id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 })],
		])('leaves neither copy when %s lands during the rebuild', async (_, event) => {
			const id = await withPin()
			await lock(id)
			await restart()
			const rebuild = holdRebuild()
			await unlock(id, password)
			await rebuild.held
			await event(id)
			rebuild.release()
			await rebuild.done
			await settle()
			await expectNoCopy(id)
		})

		it('neither deletes nor adopts a seal written after the unlock read the generation', async () => {
			const id = await withPin()
			await lock(id)
			await restart()
			// One this rebuild cannot open, as it belongs to a later key.
			const newer = await sealPin(id, '135790', pemToPkcs8(envelope.privateKeyPem), { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 })
			const get = browser.storage.session.get.bind(browser.storage.session)
			let replaced = false
			vi.spyOn(browser.storage.session, 'get').mockImplementation(async (keys) => {
				if (!replaced && keys === pinKey(id)) {
					replaced = true
					await forgetPin(id)
					await browser.storage.local.set({ [pinSealKey(id)]: newer })
				}
				const values = await get(keys)
				return values
			})
			await unlock(id, password)
			await vi.waitFor(() => expect(replaced).toBe(true))
			await settle()
			expect((await browser.storage.local.get(pinSealKey(id)))[pinSealKey(id)]).toEqual(newer)
			expect(await hasPin(id)).toBe(false)
		})

		it('brings the PIN back on Firefox without storage.session, where the session copy lived in memory', async () => {
			vi.spyOn(browser.storage, 'session', 'get').mockReturnValue(undefined as never)
			const account = await newAccount()
			await unlock(account.id, password)
			expect(await enablePin(account.id, '246813', envelope.password)).toEqual({ ok: true })
			expect(await hasSeal(account.id)).toBe(true)
			await lock(account.id)
			// A restart empties the background page's memory.
			await sessionValues.remove([pinKey(account.id)])
			await unlock(account.id, password)
			await vi.waitFor(async () => expect(await hasPin(account.id)).toBe(true))
			await lock(account.id)
			await unlock(account.id, pin())
			expect(await hasKey(account.id)).toBe(true)
		})

		it('rebuilds nothing after five wrong PINs', async () => {
			const id = await withPin()
			await lock(id)
			for (let i = 0; i < 5; i++) await unlock(id, pin('000000')).catch(() => {})
			await restart()
			await unlock(id, password)
			await settle()
			await expectNoCopy(id)
		}, 20_000)
	})

	describe('lifetime', () => {
		it('survives Lock now, lock all and the timeout', async () => {
			const id = await withPin()
			await lock(id)
			await unlock(id, pin())
			await lockAll()
			await unlock(id, pin())
			await writeSettings(id, { vaultTimeout: 1 })
			await enforce(Date.now() + 2 * MINUTE)
			expect(await hasKey(id)).toBe(false)
			expect(await hasPin(id)).toBe(true)
		})

		it.each<[string, (id: string) => Promise<unknown>]>([
			['a 401', (id) => markLoggedOut(id, true)],
			['the timeout action Log out', (id) => logoutForTimeout(id)],
			['Log out', (id) => removeAccount(id)],
			['Log out all', () => removeAllAccounts()],
			['a new unlock-key epoch', (id) => checkSuite(id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 })],
			['the two-factor block', (id) => blockUnlock(id)],
		])('is forgotten, both copies, by %s', async (_, event) => {
			const id = await withPin()
			await event(id)
			await expectNoCopy(id)
		})

		it('is kept by a suite check that finds nothing new', async () => {
			const id = await withPin()
			await checkSuite(id, { ...suiteRow })
			expect(await hasPin(id)).toBe(true)
		})

		it('keeps only the seal through a browser restart, and a PIN alone then unlocks nothing', async () => {
			const id = await withPin()
			await lock(id)
			await browser.storage.session.clear()
			expect(await hasPin(id)).toBe(false)
			expect(await hasSeal(id)).toBe(true)
			await expect(unlock(id, pin())).rejects.toMatchObject({ code: 'pin_forgotten' })
			expect(await hasKey(id)).toBe(false)
		})
	})
})

describe('a logout racing an unlock', () => {
	it('takes the key back off session and disk', async () => {
		const account = await newAccount()
		await writeSettings(account.id, { vaultTimeout: 'never', vaultTimeoutAction: 'lock' })
		// The logout runs to completion right as unlock stores the key.
		const set = browser.storage.session.set.bind(browser.storage.session)
		let loggedOut = false
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (!loggedOut && Object.keys(values).some((key) => key.startsWith('privateKeyPkcs8.'))) {
				loggedOut = true
				await markLoggedOut(account.id)
			}
			return set(values)
		})
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'session_revoked' })
		expect(await hasKey(account.id)).toBe(false)
		expect(await browser.storage.local.get([`neverLockKey.${account.id}`, `suite.${account.id}`, provenCertificateKey(account.id)])).toEqual({})
	})
})

describe('a suite change racing an unlock', () => {
	/** `sync` runs right as the unlock stores its key. */
	function syncDuringUnlock(sync: () => Promise<unknown>) {
		const set = browser.storage.session.set.bind(browser.storage.session)
		let synced = false
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (!synced && Object.keys(values).some((key) => key.startsWith('privateKeyPkcs8.'))) {
				synced = true
				await set(values)
				await sync()
				return
			}
			return set(values)
		})
	}

	it('unlocks again with the new suite when the password opens it', async () => {
		const account = await newAccount()
		syncDuringUnlock(() => checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }))
		await unlock(account.id, password)
		expect(await hasKey(account.id)).toBe(true)
		expect((await getAccount(account.id))?.keyChanged).toBe(false)
	})

	it('fails a PIN unlock as a key change, since the change forgot the PIN', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await storePin(account.id, await wrapPin('246813', pemToPkcs8(envelope.privateKeyPem), suiteRow), pinGeneration(account.id))
		await lock(account.id)
		syncDuringUnlock(() => checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }))
		await expect(unlock(account.id, { type: 'pin', pin: '246813' })).rejects.toMatchObject({ code: 'pin_key_changed' })
		expect(await hasKey(account.id)).toBe(false)
		expect(await hasPin(account.id)).toBe(false)
	})

	it('keeps no key from the old suite when the password does not open the new one', async () => {
		const account = await newAccount()
		syncDuringUnlock(() => checkSuite(account.id, { ...staleSuite(), unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 }))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'invalid_master_password' })
		expect(await hasKey(account.id)).toBe(false)
		expect((await getAccount(account.id))?.keyChanged).toBe(true)
	})

	it('does not get past a two-factor block that lands during the unlock', async () => {
		const account = await newAccount()
		const withheld: Partial<typeof suiteRow> = { ...suiteRow }
		delete withheld.privateKey
		fetchMock.mockResolvedValue(new Response(JSON.stringify([{ ...withheld, unlockBlocked: 'two_factor_required' }])))
		syncDuringUnlock(() => blockUnlock(account.id))
		await expect(unlock(account.id, password)).rejects.toMatchObject({ code: 'unlock_blocked' })
		expect(await hasKey(account.id)).toBe(false)
	})

	it('fails a PIN unlock at a two-factor block that lands during it, and keeps no certificate', async () => {
		const account = await newAccount()
		await unlock(account.id, password)
		await storePin(account.id, await wrapPin('246813', pemToPkcs8(envelope.privateKeyPem), suiteRow), pinGeneration(account.id))
		await lock(account.id)
		syncDuringUnlock(() => blockUnlock(account.id))
		await expect(unlock(account.id, { type: 'pin', pin: '246813' })).rejects.toMatchObject({ code: 'unlock_blocked' })
		expect(await hasKey(account.id)).toBe(false)
		expect(await hasPin(account.id)).toBe(false)
		expect(await browser.storage.local.get(provenCertificateKey(account.id))).toEqual({})
	})

	it('keeps the values sealed before the unlock when a sync lands before the key is stored', async () => {
		const account = await newAccount()
		await sessionValues.set({ [generatorSealedKey(account.id)]: '[]' })
		const set = browser.storage.session.set.bind(browser.storage.session)
		let synced = false
		vi.spyOn(browser.storage.session, 'set').mockImplementation(async (values) => {
			if (!synced && Object.keys(values).some((key) => key.startsWith('privateKeyPkcs8.'))) {
				synced = true
				await checkSuite(account.id, { ...suiteRow, unlockKeyEpoch: suiteRow.unlockKeyEpoch + 1 })
			}
			return set(values)
		})
		await unlock(account.id, password)
		expect(synced).toBe(true)
		expect(await hasKey(account.id)).toBe(true)
		expect(await sessionValues.get(generatorSealedKey(account.id))).toBe('[]')
	})

	it('keeps the key changed notice a sync sets after the unlock cleared it', async () => {
		const account = await newAccount()
		await updateAccount(account.id, { keyChanged: true })
		// Any write after the key is stored must not clear the notice again.
		syncDuringUnlock(() => updateAccount(account.id, { keyChanged: true }))
		await unlock(account.id, password)
		expect((await getAccount(account.id))?.keyChanged).toBe(true)
	})
})
