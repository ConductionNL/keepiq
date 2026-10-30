import { resolve } from 'node:path'
import { generateChromeMessages, parseMessagesFile } from '@wxt-dev/i18n/build'
import { fakeBrowser } from 'wxt/testing/fake-browser'

type Catalog = Record<string, { message: string }>

export const LOCALES = ['en', 'nl'] as const
export type Locale = (typeof LOCALES)[number]

export const catalogs = Object.fromEntries(await Promise.all(LOCALES.map(async (locale) => {
	// From the project root vitest runs in; happy-dom gives `import.meta.url` no file path.
	return [locale, generateChromeMessages(await parseMessagesFile(resolve(`locales/${locale}.yml`))) as Catalog]
}))) as Record<Locale, Catalog>

let current: Locale = 'en'

/** The browser's UI language for the following renders. */
export function setLocale(locale: Locale): void {
	current = locale
}

/** `browser.i18n` as Chrome serves it: `$1`–`$9` substituted, English for a missing key. */
export function installI18n(): void {
	fakeBrowser.i18n.getUILanguage = () => current
	fakeBrowser.i18n.getMessage = (name: string, substitutions?: string | Array<string | number>) => {
		const message = (catalogs[current][name] ?? catalogs.en[name])?.message ?? ''
		const subs = typeof substitutions === 'string' ? [substitutions] : substitutions ?? []
		return message.replace(/\$(\$|[1-9])/g, (_, which: string) => which === '$' ? '$' : String(subs[Number(which) - 1] ?? ''))
	}
}
