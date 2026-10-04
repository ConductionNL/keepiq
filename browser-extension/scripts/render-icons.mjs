/**
 * Render the extension's icons from the app's own icon (img/pwa-icon.svg),
 * so the toolbar and the store show the same mark as the web app. Run with
 * `node browser-extension/scripts/render-icons.mjs` after the SVG changes;
 * the PNGs are committed (clients-extension-gaps).
 *
 * @spec openspec/changes/clients-extension-gaps/specs/extension-release/spec.md#requirement-the-extension-ships-its-own-icons
 */
import { readFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium } from 'playwright'

const here = dirname(fileURLToPath(import.meta.url))
const svg = await readFile(resolve(here, '../../img/pwa-icon.svg'), 'utf8')

/** The sizes the manifest names. */
export const ICON_SIZES = [16, 32, 48, 128]

const browser = await chromium.launch()
try {
	for (const size of ICON_SIZES) {
		const page = await browser.newPage({
			viewport: { width: size, height: size },
			deviceScaleFactor: 1,
		})
		await page.setContent(
			`<html><body style="margin:0;background:transparent">${svg.replace(
				/<svg /,
				`<svg style="display:block;width:${size}px;height:${size}px" `,
			)}</body></html>`,
		)
		await page.screenshot({
			path: resolve(here, `../icons/icon-${size}.png`),
			omitBackground: true,
		})
		await page.close()
	}
} finally {
	await browser.close()
}
