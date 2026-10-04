/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The owner's browser verifies a federated recipient's certificate before
 * encrypting for it (keepiq#789, sharing-federated-recipients task 2.3).
 * Fixtures are real openssl certificates from
 * tests/fixtures/generate-federation-chain.sh: two CAs with identical
 * names, as every Keepiq instance names its CA alike, so only the pinned
 * fingerprint and the signatures tell them apart.
 *
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	FederatedCertificateError,
	verifyFederatedCertificate,
} from '../../src/crypto/federatedCertificate.js'

const F = JSON.parse(
	readFileSync(resolve(__dirname, '../fixtures/federation-chain.json'), 'utf8'),
)

const BOB = 'bob@cloud.partner.example'

/**
 * Verify with the partner A chain and pin unless overridden.
 *
 * @param {object} overrides Fields to replace.
 * @return {Promise<object>}
 */
function verify(overrides = {}) {
	return verifyFederatedCertificate({
		certificate: F.bob,
		chain: [F.intermediateA, F.rootA],
		partnerRootFingerprint: F.rootAFingerprint,
		cloudId: BOB,
		...overrides,
	})
}

/**
 * The refusal reason of a verification.
 *
 * @param {Promise<object>} pending The verification.
 * @return {Promise<string>}
 */
async function reasonOf(pending) {
	try {
		await pending
	} catch (e) {
		expect(e).toBeInstanceOf(FederatedCertificateError)
		return e.reason
	}
	throw new Error('the certificate was accepted')
}

describe('verifyFederatedCertificate', () => {
	it('accepts a certificate that chains to the pinned root and names the recipient', async () => {
		const { fingerprint } = await verify()

		expect(fingerprint).toMatch(/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/)
	})

	it('accepts a renewal signed with RSASSA-PSS, as phpseclib 3 signed them', async () => {
		await expect(verify({ certificate: F.bobPss })).resolves.toHaveProperty(
			'fingerprint',
		)
	})

	it('refuses a chain that ends at another root', async () => {
		expect(
			await reasonOf(
				verify({
					certificate: F.bobOtherRoot,
					chain: [F.intermediateB, F.rootB],
				}),
			),
		).toBe('untrusted_root')
	})

	it('refuses a certificate the pinned chain did not sign, even with the same issuer name', async () => {
		// Partner B's intermediate carries the same name as A's: only the
		// signature check can tell that A's chain never signed this one.
		expect(await reasonOf(verify({ certificate: F.bobOtherRoot }))).toBe(
			'bad_chain',
		)
	})

	it('refuses another common name than the cloud id the owner entered', async () => {
		expect(await reasonOf(verify({ certificate: F.mallory }))).toBe(
			'name_mismatch',
		)
		expect(
			await reasonOf(verify({ cloudId: 'bob@cloud.elsewhere.example' })),
		).toBe('name_mismatch')
	})

	it('refuses a certificate outside its validity', async () => {
		expect(await reasonOf(verify({ now: Date.UTC(2300, 0, 1) }))).toBe(
			'not_valid_now',
		)
	})

	it('refuses without a pin, without a chain, or with garbage', async () => {
		expect(await reasonOf(verify({ partnerRootFingerprint: '' }))).toBe(
			'untrusted_root',
		)
		expect(await reasonOf(verify({ chain: [] }))).toBe('untrusted_root')
		expect(await reasonOf(verify({ certificate: 'not a certificate' }))).toBe(
			'malformed',
		)
	})

	it('compares the pin without regard to case', async () => {
		await expect(
			verify({ partnerRootFingerprint: F.rootAFingerprint.toUpperCase() }),
		).resolves.toHaveProperty('fingerprint')
	})
})
