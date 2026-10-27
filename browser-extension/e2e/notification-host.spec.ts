/**
 * The save bar's host in real Chromium (spec `login-capture`, Keyboard and screen reader access): its corner, its
 * compact height in a short viewport, as at 400% zoom, its move into a modal dialog the page opens after it, and a
 * newer capture's bar replacing it.
 */
import { readFileSync } from 'node:fs'
import { BAR_COMPACT_HEIGHT_PX, BAR_INSET_PX, barHeight, barHostHeight, barWidth } from '@/src/messages'
import { expect, holds, test } from './fixtures'
import type { Frame, Page } from '@playwright/test'

const catalog = readFileSync(new URL('../locales/en.yml', import.meta.url), 'utf8')

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
const hostRect = (page: Page) => page.evaluate(() => {
	const { top, left, width, height } = document.querySelector('div[popover]')!.getBoundingClientRect()
	return { top, left, width, height }
})

test('the card sits in the top-right corner, and a click just outside it reaches the page', async ({ page }) => {
	await userSubmits(page)
	const width = barWidth(1200)
	await expect.poll(() => hostRect(page)).toEqual({ top: BAR_INSET_PX, left: 1200 - BAR_INSET_PX - width, width, height: barHeight(1200) })
	await page.evaluate(() => document.addEventListener('click', (event) => Object.assign(window, { pageClick: [event.clientX, event.clientY] })))
	// On the shadow, left of the card.
	const x = 1200 - BAR_INSET_PX - width - 4
	await page.mouse.click(x, 60)
	expect(await page.evaluate(() => (window as unknown as { pageClick?: number[] }).pageClick)).toEqual([x, 60])
})

test('the card moves to the bottom for a focused field under it, but not for one beside it', async ({ page }) => {
	await userSubmits(page)
	await page.evaluate(() => {
		for (const [id, side] of [['beside', 'left'], ['under', 'right']]) {
			const field = Object.assign(document.createElement('input'), { id })
			field.style.cssText = `position: fixed; top: 40px; ${side}: 40px; width: 120px`
			document.body.append(field)
		}
	})
	await page.locator('#beside').focus()
	await holds(async () => (await hostRect(page)).top === BAR_INSET_PX, 500)
	await page.locator('#under').focus()
	await expect.poll(() => hostRect(page).then(({ top }) => top)).toBe(900 - BAR_INSET_PX - barHeight(1200))
})

for (const [height, expected] of [[2 * barHeight(1200) - 1, BAR_COMPACT_HEIGHT_PX], [2 * barHeight(1200), barHeight(1200)]] as Array<[number, number]>) {
	test(`in a 1200 × ${height} viewport the card is ${expected} px tall, compact only above half of it`, async ({ page }) => {
		await page.setViewportSize({ width: 1200, height })
		const bar = await userSubmits(page)
		await expect.poll(() => frameHeight(bar)).toBe(expected)
	})
}

test('in a short viewport the bar is compact, grows while the user is in it, and shrinks after', async ({ page }) => {
	// About what 400% zoom leaves of a laptop screen: the full card would be more than half of it.
	await page.setViewportSize({ width: 1200, height: 160 })
	const bar = await userSubmits(page)
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
	await expect(bar.locator('#bar')).toBeVisible()
	// A click on the card's question gives it focus, which grows it; one landing while the bar still loads is retried.
	const grow = () => expect(async () => {
		await page.mouse.click(1000, BAR_INSET_PX + BAR_COMPACT_HEIGHT_PX / 2)
		await expect.poll(() => frameHeight(bar), { timeout: 1000 }).toBe(barHostHeight(1200, 160, true))
	}).toPass()
	await grow()
	// Focus leaving for nothing focusable sends the host no `focusout`.
	await page.mouse.click(600, 150)
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
	await grow()
	await page.locator('a.back').focus()
	await expect.poll(() => frameHeight(bar)).toBe(BAR_COMPACT_HEIGHT_PX)
})

/** A login on `spa.html`, which stays on the page. */
async function spaSubmits(page: Page, username: string): Promise<void> {
	await page.evaluate(() => {
		document.getElementById('login')!.hidden = false
	})
	await page.locator('#username').fill(username)
	await page.locator('#password').fill(`${username}-password`)
	await page.locator('#submit').click()
}

test('route changes in a single-page app keep the bar, and its Close still closes it', async ({ page }) => {
	await page.goto('/spa.html')
	await spaSubmits(page, 'route-user')
	await expect.poll(() => bars(page).length).toBe(1)
	const bar = bars(page)[0]!
	await expect(bar.locator('.bar__title')).toBeVisible()
	await bar.evaluate(() => Object.assign(window, { keepiqMark: true }))
	// Chromium fires `tabs.onUpdated` `complete` for each, which shows the capture again.
	for (let i = 0; i < 6; i++) {
		await page.evaluate((route) => history.pushState({}, '', `/spa.html#route-${route}`), i)
		await page.waitForTimeout(100)
	}
	expect(bars(page)).toHaveLength(1)
	expect(await bars(page)[0]!.evaluate(() => 'keepiqMark' in window)).toBe(true)
	await bar.locator('.bar__close').click()
	await expect.poll(() => bars(page).length).toBe(0)
})

test('a burst of route changes with no bar on screen shows one bar whose Close still closes it', async ({ page }) => {
	await page.goto('/spa.html')
	await spaSubmits(page, 'burst-user')
	await expect.poll(() => bars(page).length).toBe(1)
	await expect(bars(page)[0]!.locator('.bar__title')).toBeVisible()
	await page.evaluate(() => document.querySelector('div[popover]')!.remove())
	await expect.poll(() => bars(page).length).toBe(0)
	await page.evaluate(() => {
		for (let i = 0; i < 6; i++) history.pushState({}, '', `/spa.html#burst-${i}`)
	})
	await expect.poll(() => bars(page).length).toBe(1)
	const bar = bars(page)[0]!
	await expect(bar.locator('.bar__title')).toBeVisible()
	await bar.locator('.bar__close').click()
	await expect.poll(() => bars(page).length).toBe(0)
})

test('a second submit on a single-page login replaces the bar with one that acts on the new capture', async ({ page }) => {
	const spaSubmit = (username: string) => spaSubmits(page, username)
	await page.goto('/spa.html')
	await spaSubmit('first-user')
	await expect.poll(() => bars(page).length).toBe(1)
	const first = bars(page)[0]!
	await expect(first.locator('.bar__title')).toBeVisible()
	await first.evaluate(() => Object.assign(window, { keepiqMark: true }))
	await spaSubmit('second-user')
	await expect.poll(async () => {
		const shown = bars(page)
		return shown.length === 1 && !(await shown[0]!.evaluate(() => 'keepiqMark' in window).catch(() => true))
	}).toBe(true)
	const second = bars(page)[0]!
	await expect(second.locator('.bar__title')).toBeVisible()
	// The fake account's server never resolves, so a Save that reached the capture fails as offline, not as unknown.
	const unknown = /^errors:\n(?: .*\n)*? {2}unknown: (.+)$/m.exec(catalog)![1]!
	await expect(async () => {
		await second.locator('.bar__buttons .btn--primary').click()
		await expect(second.locator('.bar__error')).toBeVisible({ timeout: 1000 })
	}).toPass()
	await expect(second.locator('.bar__error')).not.toHaveText(unknown)
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
