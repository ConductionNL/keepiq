import { rmSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Chromium keeps an extension's service worker registered in the profile and,
 * on a restart with `--load-extension`, runs that copy instead of the new
 * background.js. Dropping the registrations makes every dev run start the current build.
 */
export function clearServiceWorkers(profileDir: string): void {
	rmSync(join(profileDir, 'Default', 'Service Worker'), { recursive: true, force: true })
}
