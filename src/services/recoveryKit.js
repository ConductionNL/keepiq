/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The recovery kit's requests (crypto-recovery-kit 2.1 to 2.3). Every request
 * carries only the wrapped copy and its parameters: the recovery code and the
 * unwrapped private key stay in the browser. The routes act on the signed-in
 * user's own active suite; none takes a user id.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { decryptPrivateKey, encryptPrivateKey } from '../crypto/aes.js'
import { HEADER_NONCE, HEADER_PROOF, PROOF_PURPOSE } from '../crypto/keyProof.js'
import { provePrivateKey } from '../crypto/reauth.js'
import { generateRecoveryCode, unwrapWithRecoveryCode, wrapWithRecoveryCode } from '../crypto/recoveryKit.js'

const BASE = '/apps/keepiq/api/v1/recovery-kit'

/**
 * The current kit, if any: when it was made, never the copy.
 *
 * @return {Promise<object|null>}
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-a-user-can-create-a-recovery-kit-that-reopens-their-own-vault
 */
export async function recoveryKitStatus() {
	const { data } = await axios.get(generateUrl(BASE))
	return data?.kit ?? null
}

/**
 * Make a new kit: unwrap the private key with the master password, wrap it
 * with a new recovery code and store only the wrapped copy. A kit made before
 * is replaced on the server.
 *
 * @param {string} encryptedPrivateKey The session's stored private key envelope.
 * @param {string} masterPassword The master password, to unwrap the private key.
 * @return {Promise<{code: string, kit: object}>} The code to print, and the stored kit.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-kit-is-created-without-the-code-leaving-the-browser
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-new-kit-replaces-the-old-one
 */
export async function createRecoveryKit(encryptedPrivateKey, masterPassword) {
	const privateKeyPem = await decryptPrivateKey(encryptedPrivateKey, masterPassword)
	const code = generateRecoveryCode()
	const copy = await wrapWithRecoveryCode(privateKeyPem, code)
	const { data } = await axios.put(generateUrl(BASE), { wrappedKey: copy.wrappedKey, kdf: copy.kdf })
	return { code, kit: data?.kit ?? null }
}

/**
 * Delete the current kit.
 *
 * @return {Promise<void>}
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#requirement-recovery-is-limited-to-the-owner-and-leaves-a-trail
 */
export async function revokeRecoveryKit() {
	await axios.delete(generateUrl(BASE))
}

/**
 * Recover with a recovery code: fetch the wrapped copy, unwrap the private
 * key, set a new master password through the routine change (re-wrap the
 * private key, proven with the key itself) and spend the kit.
 *
 * @param {string} code The recovery code as entered.
 * @param {string} newPassword The new master password.
 * @return {Promise<void>}
 * @throws {Error} When the code does not open the copy; nothing is changed then.
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-user-who-lost-the-master-password-gets-back-in
 * @spec openspec/changes/crypto-recovery-kit/specs/vault-recovery-kit/spec.md#scenario-a-wrong-code-opens-nothing
 */
export async function recoverWithCode(code, newPassword) {
	const { data: copy } = await axios.get(generateUrl(BASE + '/copy'))
	const privateKeyPem = await unwrapWithRecoveryCode(copy, code)
	const newEnvelope = await encryptPrivateKey(privateKeyPem, newPassword)

	const { data: challenge } = await axios.get(
		generateUrl('/apps/keepiq/api/v1/suites/' + copy.suiteId + '/proof-challenge'),
		{ params: { purpose: PROOF_PURPOSE.UPDATE_PRIVATE_KEY } },
	)
	const signature = await provePrivateKey(privateKeyPem, challenge.nonce, [newEnvelope])
	await axios.put(
		generateUrl('/apps/keepiq/api/v1/suites/' + copy.suiteId + '/private-key'),
		{ encryptedPrivateKey: newEnvelope },
		{ headers: { [HEADER_NONCE]: challenge.nonce, [HEADER_PROOF]: signature } },
	)
	await axios.post(generateUrl(BASE + '/spent'))
}
