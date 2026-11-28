import { readFileSync } from 'node:fs'

/** Test vectors the Keepiq web app's own crypto wrote (`tests/vectors/crypto/` in the app repo). */
export function vectors(name: 'envelope' | 'fields' | 'send') {
	return JSON.parse(readFileSync(new URL(`../../../tests/vectors/crypto/${name}.json`, import.meta.url), 'utf8'))
}

export const envelope = vectors('envelope')

export interface GeneratorCase {
	name: string
	kind: 'password' | 'passphrase'
	options: Record<string, unknown>
	policy: Record<string, unknown> | null
	seed: number
	expected?: string
	error?: string
}

/** What the web app's generator gives for seeded draws (`tests/vectors/generator/` in the app repo). */
export function generatorCases(): GeneratorCase[] {
	return JSON.parse(readFileSync(new URL('../../../tests/vectors/generator/cases.json', import.meta.url), 'utf8')).cases
}

/** The app repo's `tests/vectors/seeded-random.mjs`: xorshift32, one step per draw, then `min + x % range`. */
export function seededRandom(seed: number): (min: number, max: number) => number {
	let x = seed >>> 0 || 1
	return (min, max) => {
		x ^= x << 13
		x >>>= 0
		x ^= x >>> 17
		x ^= x << 5
		x >>>= 0
		const range = max - min + 1
		return range <= 1 ? min : min + (x % range)
	}
}

/** The web app's EFF list, read from its module source. */
export function webWordlist(): string[] {
	const source = readFileSync(new URL('../../../src/generator/eff-large-wordlist.js', import.meta.url), 'utf8')
	const body = source.slice(source.indexOf('Object.freeze('), source.lastIndexOf(').split('))
	return [...body.matchAll(/'([^']*)'/g)].map((match) => match[1]).join('').split(' ')
}

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

/** A chain fixture next to this file, from the `generate-*.sh` of its name, without its `_note`. */
const chainFixture = <Name extends string>(file: string): Record<Name, string> =>
	JSON.parse(readFileSync(new URL(`./${file}`, import.meta.url), 'utf8'), (key, value) => key === '_note' ? undefined : value)

export const caConstraintsChain = () => chainFixture<CaConstraintsName>('ca-constraints-chain.json')

export const phpseclibChain = () => chainFixture<'root' | 'rootFingerprint' | 'intermediate' | 'leaf' | 'leafResigned' | 'opensslLeafResigned'>('phpseclib-chain.json')

export const suiteRow = {
	id: 'suite-1',
	status: 'active',
	certificate: envelope.certificatePem as string,
	privateKey: envelope.envelope as string,
	unlockKeyEpoch: 1,
}
