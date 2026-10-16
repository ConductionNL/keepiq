import { readFileSync } from 'node:fs'

/** Test vectors the Keepiq web app's own crypto wrote (`tests/vectors/crypto/` in the app repo). */
export function vectors(name: 'envelope' | 'fields') {
	return JSON.parse(readFileSync(new URL(`../../../tests/vectors/crypto/${name}.json`, import.meta.url), 'utf8'))
}

export const envelope = vectors('envelope')

export const suiteRow = {
	id: 'suite-1',
	status: 'active',
	certificate: envelope.certificatePem as string,
	privateKey: envelope.envelope as string,
	unlockKeyEpoch: 1,
}
