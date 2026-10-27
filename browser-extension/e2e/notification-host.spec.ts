/**
 * The save bar's host in real Chromium (spec `login-capture`, Keyboard and screen reader access): its compact height in
 * a short viewport, as at 400% zoom, and its move into a modal dialog the page opens after it.
 */
import { BAR_COMPACT_HEIGHT_PX, BAR_WIDE_HEIGHT_PX } from '@/src/messages'
import { expect, test } from './fixtures'
import type { Frame, Page } from '@playwright/test'

const bars = (page: Page): Frame[] =>
	page.frames().filter((frame) => frame.url().startsWith('chrome-extension://') && frame.url().endsWith('/notification.html'))

async function userSubmits(page: Page): Promise<Frame> {
	await page.goto('/login.html')
	await page.locator('input[name=username]').fill('new-user@example.test')
	await page.locator('input[name=password]').fill('a-new-password')
	await page.locator('button[type=submit]').click()
	await page.waitForURL('**/signed-in.html**')
	await expect.poll(() => bars(page).length).toBe(1)
	return bars(page)[0]!
}

const frameHeight = (bar: Frame) => bar.evaluate(() => window.innerHeight)

test('in a short viewport the bar is compact, grows while the user is in it, and shrinks after', async ({ page }) => {
	// About what 400% zoom leaves of a laptop screen: 64 px would be more than a third of it.
	await page.setViewportSize({ width: 1200, height: 160 })
	const bar = await userSubmits(page)
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
	await expect(bar.locator('#bar')).toBeVisible()
	// A click in the bar gives it focus, which grows it; one landing while the bar still loads is retried.
	const grow = () => expect(async () => {
		await page.mouse.click(600, 20)
		await expect.poll(() => frameHeight(bar), { timeout: 1000 }).toBe(BAR_WIDE_HEIGHT_PX)
	}).toPass()
	await grow()
	// Focus leaving for nothing focusable sends the host no `focusout`.
	await page.mouse.click(600, 150)
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
	await grow()
	await page.locator('a.back').focus()
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
})

test('a modal dialog opened after the bar takes the bar inside, without reloading it', async ({ page }) => {
	const bar = await userSubmits(page)
	await expect(bar.locator('.bar__title')).toBeVisible()
	await bar.evaluate(() => Object.assign(window, { keepiqMark: true }))
	await page.evaluate(() => {
		const dialog = document.createElement('dialog')
		dialog.innerHTML = '<input id="in-dialog">'
		document.body.append(dialog)
		dialog.showModal()
		dialog.querySelector('input')!.focus()
	})
	await expect.poll(() => page.evaluate(() => document.querySelector('dialog > div[popover]') !== null)).toBe(true)
	await expect.poll(() => page.evaluate(() => document.querySelector('dialog > div[popover]')!.matches(':popover-open'))).toBe(true)
	const moved = bars(page)
	expect(moved).toHaveLength(1)
	await expect(moved[0]!.locator('.bar__title')).toBeVisible()
	// `moveBefore` keeps the frame's document; a browser without it shows a new bar instead.
	if (await page.evaluate(() => 'moveBefore' in Element.prototype)) expect(await moved[0]!.evaluate(() => 'keepiqMark' in window)).toBe(true)
})
