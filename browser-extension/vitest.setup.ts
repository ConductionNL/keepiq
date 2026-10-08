import { afterEach } from 'vitest'
import { installI18n, setLocale } from './src/testing/i18n'

// WXT's fake browser leaves `browser.i18n` unimplemented.
installI18n()
afterEach(() => setLocale('en'))
