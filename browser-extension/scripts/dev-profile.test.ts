import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { prepareDevProfile } from './dev-profile'

describe('prepareDevProfile', () => {
	it('drops the registrations and keeps extension storage', () => {
		const profile = mkdtempSync(join(tmpdir(), 'keepiq-profile-'))
		mkdirSync(join(profile, 'Default', 'Service Worker', 'ScriptCache'), { recursive: true })
		mkdirSync(join(profile, 'Default', 'Local Extension Settings'), { recursive: true })
		writeFileSync(join(profile, 'Default', 'Service Worker', 'ScriptCache', 'old-background'), 'stale')

		prepareDevProfile(profile)

		expect(existsSync(join(profile, 'Default', 'Service Worker'))).toBe(false)
		expect(existsSync(join(profile, 'Default', 'Local Extension Settings'))).toBe(true)
	})

	it('creates a missing profile', () => {
		const profile = join(mkdtempSync(join(tmpdir(), 'keepiq-wxt-')), 'chrome-data')
		prepareDevProfile(profile)
		expect(existsSync(profile)).toBe(true)
	})
})
