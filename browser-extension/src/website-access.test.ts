import { afterEach, describe, expect, it, vi } from 'vitest'
import { SITE_ORIGINS, websiteAccess } from './website-access'

afterEach(() => vi.unstubAllEnvs())

/** `granted` holds the patterns `permissions.contains` answers true for. */
function grant(granted: string[]) {
	return vi.spyOn(browser.permissions, 'contains').mockImplementation(async ({ origins = [] }) => origins.every((origin) => granted.includes(origin)))
}

const SERVER = 'https://cloud.example.org/nextcloud'

describe('websiteAccess', () => {
	it.each(['chrome', 'firefox', 'edge'])('is all on %s without asking', async (target) => {
		vi.stubEnv('BROWSER', target)
		const contains = grant([])
		expect(await websiteAccess(SERVER)).toBe('all')
		expect(contains).not.toHaveBeenCalled()
	})

	it('is server on Safari while the server origin is not granted, whatever else is', async () => {
		vi.stubEnv('BROWSER', 'safari')
		grant(SITE_ORIGINS)
		expect(await websiteAccess(SERVER)).toBe('server')
	})

	it('is sites on Safari while not both site patterns are granted', async () => {
		vi.stubEnv('BROWSER', 'safari')
		grant(['https://cloud.example.org/*', 'https://*/*'])
		expect(await websiteAccess(SERVER)).toBe('sites')
	})

	it('is all on Safari with the server and both site patterns granted', async () => {
		vi.stubEnv('BROWSER', 'safari')
		const contains = grant(['https://cloud.example.org/*', ...SITE_ORIGINS])
		expect(await websiteAccess(SERVER)).toBe('all')
		expect(contains).toHaveBeenCalledWith({ origins: SITE_ORIGINS })
	})
})
