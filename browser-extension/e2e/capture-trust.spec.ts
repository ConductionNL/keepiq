/**
 * What a page can do against login capture, in real Chromium (spec `login-capture`): frame its own copy of the bar,
 * submit a login by script, and cover the bar before the user clicks it.
 */
import { readFileSync } from 'node:fs'
import { expect, holds, test } from './fixtures'
import type { Frame, Page } from '@playwright/test'

const catalog = readFileSync(new URL('../locales/en.yml', import.meta.url), 'utf8')
const barText = (name: string) => new RegExp(`^notificationBar:\\n(?: .*\\n)*? {2}${name}: (.+)$`, 'm').exec(catalog)![1]!

const bars = (page: Page): Frame[] =>
	page.frames().filter((frame) => frame.url().startsWith('chrome-extension://') && frame.url().endsWith('/notification.html'))

/** A new login the user typed and submitted on `login.html`, which redirects to `signed-in.html`. */
async function userSubmits(page: Page): Promise<Frame> {
	await page.goto('/login.html')
	await page.locator('input[name=username]').fill('new-user@example.test')
	await page.locator('input[name=password]').fill('a-new-password')
	await page.locator('button[type=submit]').click()
	await page.waitForURL('**/signed-in.html**')
	await expect.poll(() => bars(page).length).toBe(1)
	const bar = bars(page)[0]!
	await expect(bar.locator('.bar__title')).toBeVisible()
	return bar
}

test('a copy of the bar the page frames itself gets nothing to show or act on', async ({ page }) => {
	const bar = await userSubmits(page)
	const url = bar.url()
	await page.evaluate((src) => {
		const copy = document.createElement('iframe')
		copy.id = 'copy'
		copy.src = src
		copy.style.cssText = 'width: 800px; height: 100px'
		document.body.append(copy)
		// A key of the page's own making, posted the way the host posts the real one.
		copy.addEventListener('load', () => copy.contentWindow!.postMessage({ kind: 'capture.init', key: 'f'.repeat(32) }, '*'))
	}, url)
	await expect.poll(() => bars(page).length).toBe(2)
	const copy = bars(page).find((frame) => frame !== bar)!
	await holds(async () => await copy.locator('.bar__title, .bar__buttons button').count() === 0)
	// The real bar is unaffected.
	await expect(bar.locator('.bar__title')).toBeVisible()
})

// The page submits by itself: `page.evaluate` runs as a user gesture, which would give the script the activation it lacks.
for (const [how, query] of [['requestSubmit()', ''], ['a click() on its button', '?how=click']] as const) {
	test(`a login a script submits with ${how} shows no bar`, async ({ page }) => {
		await page.goto(`/scripted-submit.html${query}`)
		await page.waitForURL('**/signed-in.html**')
		await holds(() => bars(page).length === 0, 2000)
	})
}

test('a bar the page covers does not act on a click through the cover', async ({ page }) => {
	const bar = await userSubmits(page)
	// Drawn over the bar in the top layer, letting clicks through to it.
	await page.evaluate(() => {
		const cover = document.createElement('div')
		cover.setAttribute('popover', 'manual')
		cover.style.cssText = 'position: fixed; inset: 0 0 auto 0; width: 100%; height: 200px; margin: 0; border: 0; background: rgba(255, 255, 255, 0.9); pointer-events: none'
		document.body.append(cover)
		cover.showPopover()
	})
	const save = bar.locator('.bar__buttons .btn--primary')
	const box = (await save.boundingBox())!
	const notVisible = barText('notVisible')
	await expect(async () => {
		await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
		await expect(bar.locator('body')).toContainText(notVisible, { timeout: 1000 })
	}).toPass()
	// Nothing was sent: the fake account's server never answers, so a save would end in an error, not here.
	await holds(async () => !(await bar.locator('.bar__error').isVisible()) && !(await bar.locator('body').innerText()).includes(barText('saved')))
})
