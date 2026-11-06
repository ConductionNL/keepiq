import { describe, expect, it } from 'vitest'
import { catalogs, LOCALES } from '@/src/testing/i18n'

const placeholders = (message: string) => [...new Set(message.match(/\{[A-Za-z0-9_]+\}|\$[1-9]/g))].sort()

it('has no two keys the browser would read as one', () => {
	// Message names are case-insensitive, and Firefox rejects the duplicate.
	const keys = Object.keys(catalogs.en).map((key) => key.toLowerCase())
	expect(keys.filter((key, i) => keys.indexOf(key) !== i)).toEqual([])
})

it.each(LOCALES)('keeps the %s description within Chrome\'s 132 characters', (locale) => {
	expect([...catalogs[locale].extensionDescription?.message ?? ''].length).toBeLessThanOrEqual(132)
})

describe.each(LOCALES.filter((locale) => locale !== 'en'))('the %s catalog', (locale) => {
	const source = catalogs.en
	const target = catalogs[locale]

	it('has exactly the keys of the English one', () => {
		expect(Object.keys(target).sort()).toEqual(Object.keys(source).sort())
	})

	it('keeps every placeholder', () => {
		for (const [key, { message }] of Object.entries(source)) {
			expect({ key, placeholders: placeholders(target[key]?.message ?? '') }).toEqual({ key, placeholders: placeholders(message) })
		}
	})

	it('names its own language', () => {
		// A region may follow: `pt` holds European Portuguese, so it formats as `pt-PT`.
		expect(target.language?.message.split('-')[0]).toBe(locale)
	})
})
