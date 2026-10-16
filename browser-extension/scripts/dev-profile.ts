import { mkdirSync, rmSync } from 'node:fs'
import { join } from 'node:path'

/**
 * chrome-launcher writes its logs into a kept profile without creating it, so a missing one
 * (a fresh clone, a cleaned `.wxt`) fails the launch with ENOENT.
 *
 * Chromium also keeps an extension's service worker registered in the profile and,
 * on a restart with `--load-extension`, runs that copy instead of the new
 * background.js. Dropping the registrations makes every dev run start the current build.
 */
export function prepareDevProfile(profileDir: string): void {
	mkdirSync(profileDir, { recursive: true })
	rmSync(join(profileDir, 'Default', 'Service Worker'), { recursive: true, force: true })
}
