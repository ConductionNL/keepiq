import { describe, expect, it } from 'vitest'
import { normalizeOrigin } from './normalize-origin'

describe('normalizeOrigin', () => {
	it.each([
		['cloud.example.org', 'https://cloud.example.org'],
		['https://cloud.example.org/', 'https://cloud.example.org'],
		['https://cloud.example.org/index.php/apps/keepiq/vault', 'https://cloud.example.org'],
		['  https://cloud.example.org:8443/x  ', 'https://cloud.example.org:8443'],
		['http://stable35.test:8080', 'http://stable35.test:8080'],
		['http://localhost', 'http://localhost'],
		['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
		['http://nas.local', 'http://nas.local'],
	])('%s → %s', (input, origin) => {
		expect(normalizeOrigin(input)).toMatchObject({ ok: true, origin })
	})

	it('rejects plain http on a public host', () => {
		expect(normalizeOrigin('http://cloud.example.org')).toEqual({ ok: false, code: 'insecure_url' })
	})

	it.each(['', '   ', 'ftp://cloud.example.org', 'https://'])('rejects %j', (input) => {
		expect(normalizeOrigin(input)).toEqual({ ok: false, code: 'invalid_url' })
	})

	it('returns the host with its port', () => {
		expect(normalizeOrigin('stable35.test:8080')).toMatchObject({ host: 'stable35.test:8080' })
	})
})
