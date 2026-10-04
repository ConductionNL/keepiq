/**
 * Unlock with a PIN (clients-extension-gaps). After a master-password unlock,
 * the user may set a PIN: the account's unlock key (the AES key that opens
 * the private-key envelope) is wrapped under a key derived from the PIN
 * with Argon2id. The wrapped key lives in session storage only, so the PIN
 * works until the browser closes; after five wrong PINs it is forgotten.
 *
 * @spec openspec/changes/clients-extension-gaps/specs/extension-pin-unlock/spec.md#requirement-unlock-with-a-pin-until-the-browser-closes
 */

import {
	aesDecrypt,
	aesEncrypt,
	fromBase64,
	toBase64,
} from '../../../src/send/sendCrypto.js'
import { deriveAesKeyArgon2id } from '../../../src/crypto/argon2.js'
import { installArgon2Wasm } from './argon2-wasm.js'

/** Wrong PINs before the PIN is forgotten. */
export const PIN_MAX_ATTEMPTS = 5

/** The shortest PIN accepted. */
export const PIN_MIN_LENGTH = 6

const KEY = (accountId) => 'pin:' + accountId

/**
 * What is wrong with a PIN, or null.
 *
 * @param {string} pin The PIN.
 * @return {string|null}
 */
export function pinProblem(pin) {
	const value = String(pin ?? '')
	if (value.length < PIN_MIN_LENGTH) {
		return `A PIN has at least ${PIN_MIN_LENGTH} characters`
	}
	if (value.length > 64) return 'A PIN has at most 64 characters'
	return null
}

/**
 * Build the PIN store on a session storage area.
 *
 * @param {object} session The session storage area.
 * @return {object}
 */
export function buildPinUnlock(session) {
	/**
	 * The stored record of an account, or null.
	 *
	 * @param {string} accountId The account.
	 * @return {Promise<object|null>}
	 */
	async function recordOf(accountId) {
		return (await session.get(KEY(accountId)))[KEY(accountId)] ?? null
	}

	return {
		/**
		 * Whether an account has a PIN.
		 *
		 * @param {string} accountId The account.
		 * @return {Promise<boolean>}
		 */
		async has(accountId) {
			return (await recordOf(accountId)) !== null
		},

		/**
		 * Wrap an unlock key under a PIN.
		 *
		 * @param {string} accountId The account.
		 * @param {Uint8Array} rawKey The unlock key.
		 * @param {string} pin The PIN.
		 * @return {Promise<void>}
		 */
		async set(accountId, rawKey, pin) {
			const problem = pinProblem(pin)
			if (problem) throw new Error(problem)
			installArgon2Wasm()
			const salt = crypto.getRandomValues(new Uint8Array(16))
			const key = await deriveAesKeyArgon2id(String(pin), salt)
			await session.set({
				[KEY(accountId)]: {
					salt: toBase64(salt),
					wrapped: await aesEncrypt(key, rawKey),
					attempts: 0,
				},
			})
		},

		/**
		 * Open the unlock key with a PIN. A wrong PIN counts; the fifth
		 * forgets the PIN.
		 *
		 * @param {string} accountId The account.
		 * @param {string} pin The PIN.
		 * @return {Promise<Uint8Array>} The unlock key.
		 * @throws {Error} On a wrong PIN or no PIN.
		 */
		async open(accountId, pin) {
			const record = await recordOf(accountId)
			if (!record)
				throw new Error('No PIN is set. Unlock with your master password.')
			installArgon2Wasm()
			const key = await deriveAesKeyArgon2id(
				String(pin ?? ''),
				fromBase64(record.salt),
			)
			try {
				return await aesDecrypt(key, record.wrapped)
			} catch {
				const attempts = record.attempts + 1
				if (attempts >= PIN_MAX_ATTEMPTS) {
					await session.remove(KEY(accountId))
					throw new Error(
						'Too many wrong PINs. Unlock with your master password.',
					)
				}
				await session.set({ [KEY(accountId)]: { ...record, attempts } })
				const left = PIN_MAX_ATTEMPTS - attempts
				throw new Error(
					`Wrong PIN. ${left} ${left === 1 ? 'try' : 'tries'} left.`,
				)
			}
		},

		/**
		 * Forget an account's PIN.
		 *
		 * @param {string} accountId The account.
		 * @return {Promise<void>}
		 */
		async remove(accountId) {
			await session.remove(KEY(accountId))
		},
	}
}
