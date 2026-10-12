// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { applyTheme, followMirror, mirroredTheme, showTheme, THEME_MIRROR_KEY } from './theme'

const attribute = () => document.documentElement.dataset.theme

afterEach(() => {
	vi.restoreAllMocks()
	localStorage.clear()
	applyTheme('system')
})

describe('theme', () => {
	it('pins light and dark, and leaves System default to the media query', () => {
		applyTheme('dark')
		expect(attribute()).toBe('dark')
		applyTheme('light')
		expect(attribute()).toBe('light')
		applyTheme('system')
		expect(attribute()).toBeUndefined()
	})

	it('mirrors what it shows, and reads anything unknown as System default', () => {
		expect(mirroredTheme()).toBe('system')
		showTheme('light')
		expect(attribute()).toBe('light')
		expect(mirroredTheme()).toBe('light')
		localStorage.setItem(THEME_MIRROR_KEY, 'nord')
		expect(mirroredTheme()).toBe('system')
	})

	it('still applies when localStorage throws', () => {
		vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new DOMException('Blocked', 'SecurityError') })
		vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new DOMException('Blocked', 'SecurityError') })
		expect(mirroredTheme()).toBe('system')
		showTheme('dark')
		expect(attribute()).toBe('dark')
	})

	it('follows a change written by another window, and nothing else', () => {
		followMirror(new StorageEvent('storage', { key: 'other', newValue: 'dark' }))
		expect(attribute()).toBeUndefined()
		followMirror(new StorageEvent('storage', { key: THEME_MIRROR_KEY, newValue: 'dark' }))
		expect(attribute()).toBe('dark')
	})
})
