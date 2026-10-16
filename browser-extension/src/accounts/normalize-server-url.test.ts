import { describe, expect, it } from 'vitest'
import { normalizeServerUrl } from './normalize-server-url'

describe('normalizeServerUrl', () => {
	it.each([
		['cloud.example.org', 'https://cloud.example.org'],
		['https://cloud.example.org/', 'https://cloud.example.org'],
		['https://cloud.example.org/index.php/apps/keepiq/vault', 'https://cloud.example.org'],
		['https://cloud.example.org/apps/keepiq/vault', 'https://cloud.example.org'],
		['  https://cloud.example.org:8443/  ', 'https://cloud.example.org:8443'],
		['https://example.org/nextcloud', 'https://example.org/nextcloud'],
		['https://example.org/nextcloud/', 'https://example.org/nextcloud'],
		['https://example.org/nextcloud/index.php/apps/keepiq/vault?x=1#y', 'https://example.org/nextcloud'],
		['example.org/cloud/settings/user/security', 'https://example.org/cloud'],
		['https://cloud.example.org/s/aBc123', 'https://cloud.example.org'],
		['https://example.org/nextcloud/f/123', 'https://example.org/nextcloud'],
		['https://cloud.example.org/call/xyz', 'https://cloud.example.org'],
		['http://stable35.test:8080', 'http://stable35.test:8080'],
		['http://localhost', 'http://localhost'],
		['http://127.0.0.1:8000', 'http://127.0.0.1:8000'],
	])('%s → %s', (input, serverUrl) => {
		expect(normalizeServerUrl(input)).toMatchObject({ ok: true, serverUrl })
	})

	it('returns the bare origin for the host permission', () => {
		expect(normalizeServerUrl('https://example.org/nextcloud')).toMatchObject({ origin: 'https://example.org' })
	})

	it.each(['http://cloud.example.org', 'http://nas.local'])('rejects plain http on %s', (input) => {
		expect(normalizeServerUrl(input)).toEqual({ ok: false, code: 'insecure_url' })
	})

	it.each(['', '   ', 'ftp://cloud.example.org', 'https://'])('rejects %j', (input) => {
		expect(normalizeServerUrl(input)).toEqual({ ok: false, code: 'invalid_url' })
	})

	it('returns the host with its port', () => {
		expect(normalizeServerUrl('stable35.test:8080')).toMatchObject({ host: 'stable35.test:8080' })
	})
})
