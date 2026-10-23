import { readFileSync } from 'node:fs'

/** Test vectors the Keepiq web app's own crypto wrote (`tests/vectors/crypto/` in the app repo). */
export function vectors(name: 'envelope' | 'fields') {
	return JSON.parse(readFileSync(new URL(`../../../tests/vectors/crypto/${name}.json`, import.meta.url), 'utf8'))
}

export const envelope = vectors('envelope')

/** Every entry of the web app's fixture, which a test holds it to, so a case it adds can't go unread here. */
export const FEDERATION_NAMES = [
	'rootA', 'rootAFingerprint', 'rootAPathLen0', 'rootAPathLen0Fingerprint', 'rootAExpired', 'rootAExpiredFingerprint', 'rootB', 'rootBFingerprint',
	'intermediateA', 'intermediateB', 'intermediateABasicConstraintsOnly', 'intermediateADuplicateBasicConstraints', 'intermediateAEmptyKeyUsageWithUnusedBits',
	'intermediateAExpired', 'intermediateAKeyCertSignInPadding', 'intermediateANoBasicConstraints', 'intermediateANoCertSign', 'intermediateANotCa',
	'intermediateANotYetValid', 'intermediateAPathLen0', 'intermediateAUnknownCritical', 'intermediateAUnknownNonCritical',
	'bob', 'bobPss', 'bobOtherRoot', 'mallory', 'leafMarkedCa', 'leafSignedByLeaf', 'leafSignedByRoot',
] as const

/** Real openssl certificates from the app repo's `tests/fixtures/generate-federation-chain.sh`. */
export function federationChain(): Record<typeof FEDERATION_NAMES[number], string> {
	return JSON.parse(readFileSync(new URL('../../../tests/fixtures/federation-chain.json', import.meta.url), 'utf8'))
}

type CaConstraintsName = 'root' | 'rootFingerprint' | 'rootPathLen0' | 'rootPathLen0Fingerprint' | 'rootExpired' | 'rootExpiredFingerprint'
	| 'intermediate' | 'intermediateNoBasicConstraints' | 'intermediateNotCa' | 'intermediateNoCertSign' | 'intermediatePathLen0'
	| 'intermediateUnknownCritical' | 'intermediateDuplicateBasicConstraints' | 'intermediateExpired' | 'intermediateNotYetValid' | 'subIntermediate'
	| 'mallory' | 'malloryKey' | 'malloryUnknownCritical' | 'malloryUnknownNonCritical' | 'malloryDuplicateExtension' | 'malloryByRoot' | 'malloryBySubIntermediate'
	| 'forgedAlice'

/** From `generate-ca-constraints-chain.sh` next to it, without its `_note`. */
export function caConstraintsChain(): Record<CaConstraintsName, string> {
	return JSON.parse(readFileSync(new URL('./ca-constraints-chain.json', import.meta.url), 'utf8'), (key, value) => key === '_note' ? undefined : value)
}

export const suiteRow = {
	id: 'suite-1',
	status: 'active',
	certificate: envelope.certificatePem as string,
	privateKey: envelope.envelope as string,
	unlockKeyEpoch: 1,
}
