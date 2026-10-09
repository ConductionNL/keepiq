import { existsSync, mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { clearServiceWorkers } from './dev-profile'

describe('clearServiceWorkers', () => {
	it('drops the registrations and keeps extension storage', () => {
		const profile = mkdtempSync(join(tmpdir(), 'keepiq-profile-'))
		mkdirSync(join(profile, 'Default', 'Service Worker', 'ScriptCache'), { recursive: true })
		mkdirSync(join(profile, 'Default', 'Local Extension Settings'), { recursive: true })
		writeFileSync(join(profile, 'Default', 'Service Worker', 'ScriptCache', 'old-background'), 'stale')

		clearServiceWorkers(profile)

		expect(existsSync(join(profile, 'Default', 'Service Worker'))).toBe(false)
		expect(existsSync(join(profile, 'Default', 'Local Extension Settings'))).toBe(true)
	})

	it('is a no-op on a fresh profile', () => {
		expect(() => clearServiceWorkers(join(tmpdir(), 'keepiq-missing-profile'))).not.toThrow()
	})
})
