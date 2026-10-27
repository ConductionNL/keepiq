/**
 * The save bar's layout in real Chromium at the card's full and narrowed sizes: nothing may run off the side or clip the
 * question, and an error too long for the card scrolls, in Dutch, the longer catalog, with a long folder path (spec `login-capture`).
 */
import { readFileSync } from 'node:fs'
import { BAR_COMPACT_HEIGHT_PX, barHeight, barHostHeight, barWidth } from '@/src/messages'
import { expect, test } from './fixtures'
import { LOGIN } from './seed'
import type { Frame, Page } from '@playwright/test'

/** Around 424 px, where the card stops narrowing with the viewport. */
const WIDTHS = [320, 360, 423, 424, 640, 1200]
/** The shared texts `writeErrorText` in `entrypoints/notification/bar.ts` shows, besides its own `notificationBar.errors`. */
const SHARED_ERRORS = [
	'errors_sessionRevoked', 'errors_unknown', 'writeErrors_offline', 'writeErrors_serverError', 'writeErrors_forbidden',
	'writeErrors_policy', 'writeErrors_invalid', 'writeErrors_restricted', 'writeErrors_folderGone',
]

/** From the build global setup makes, so not before it. */
const catalogNl = () => JSON.parse(readFileSync(new URL('../.output/chrome-mv3/_locales/nl/messages.json', import.meta.url), 'utf8')) as Record<string, { message: string }>

function barErrorsNl(): string[] {
	const catalog = catalogNl()
	const own = Object.keys(catalog).filter((name) => name.startsWith('notificationBar_errors_'))
	return [...own, ...SHARED_ERRORS].map((name) => catalog[name]!.message.replace('{version}', '0.3.12'))
}

const barFrame = (page: Page): Frame | undefined =>
	page.frames().find((frame) => frame.url().startsWith('chrome-extension://') && frame.url().endsWith('/notification.html'))

async function submit(page: Page, username: string, password: string): Promise<Frame> {
	await page.goto('/login.html')
	await page.locator('input[name=username]').fill(username)
	await page.locator('input[name=password]').fill(password)
	await page.locator('button[type=submit]').click()
	await page.waitForURL('**/signed-in.html**')
	await expect.poll(() => barFrame(page) !== undefined).toBe(true)
	const frame = barFrame(page)!
	await expect(frame.locator('.bar__title')).toBeVisible()
	return frame
}

/**
 * What runs off the frame's sides or bottom, and whether the question is cut off; empty when the layout fits. With
 * `scrolls`, text too long for the card may scroll instead, where the keyboard reaches it.
 */
const overflow = (frame: Frame, scrolls = false) => frame.evaluate((scrolls) => {
	const problems: string[] = []
	const { innerWidth, innerHeight } = window
	for (const el of document.querySelectorAll<HTMLElement>('#bar, #bar *')) {
		const rect = el.getBoundingClientRect()
		if (rect.width === 0 && rect.height === 0) continue
		if (rect.left < -0.5 || rect.right > innerWidth + 0.5 || rect.bottom > innerHeight + 0.5) {
			problems.push(`${el.tagName.toLowerCase()}.${el.getAttribute('class') ?? ''} ${Math.round(rect.left)}–${Math.round(rect.right)} × ${Math.round(rect.bottom)}`)
		}
	}
	if (document.documentElement.scrollWidth > innerWidth) problems.push(`scrollWidth ${document.documentElement.scrollWidth}`)
	const text = document.querySelector<HTMLElement>('.bar__text')!
	const scrollable = scrolls && text.tabIndex === 0 && getComputedStyle(text).overflowY === 'auto'
	if (text.scrollHeight > text.clientHeight + 0.5 && !scrollable) problems.push(`question clipped ${text.scrollHeight} > ${text.clientHeight}`)
	return problems
}, scrolls)

/** `text` names what is shown, so a failure says which message did not fit. */
async function fitsEveryWidth(page: Page, text = '', scrolls = false) {
	const frame = barFrame(page)!
	for (const width of WIDTHS) {
		await page.setViewportSize({ width, height: 700 })
		await expect.poll(() => frame.evaluate(() => [window.innerWidth, window.innerHeight])).toEqual([barWidth(width), barHeight(width)])
		// The bar's resize handler, which makes clipped text focusable, runs before the next frame.
		await frame.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))))
		expect.soft({ text, width, problems: await overflow(frame, scrolls) }).toEqual({ text, width, problems: [] })
	}
}

test.describe('in Dutch, with a long folder path', () => {
	test.use({ lang: 'nl', longFolder: true })

	test('the save bar fits at every width', async ({ page }) => {
		const frame = await submit(page, 'nieuwe-gebruiker@voorbeeld.test', 'een-nieuw-wachtwoord')
		await expect(frame.locator('.bar__title')).toHaveText(/^Login voor localhost:\d+ opslaan in Keepiq\?$/)
		await expect(frame.locator('select option').last()).toHaveText('Werk / Klanten / Gemeente Amsterdam Stadsdeel Nieuw-West')
		await frame.locator('select').selectOption({ index: 3 })
		await fitsEveryWidth(page)
	})

	test('an error, in the question\'s place, fits or scrolls at every width', async ({ page }) => {
		const frame = await submit(page, 'nieuwe-gebruiker@voorbeeld.test', 'een-nieuw-wachtwoord')
		// The account's server never resolves, so Save fails as offline; a press within 500 ms of showing is ignored.
		await expect(async () => {
			await frame.locator('.bar__buttons .btn--primary').click()
			await expect(frame.locator('.bar__error')).toBeVisible({ timeout: 1000 })
		}).toPass()
		await expect(frame.locator('.bar__title')).toBeHidden()
		// Every error the bar can show, in the place the real error took.
		const messages = barErrorsNl()
		expect(messages).toHaveLength(21)
		for (const message of messages) {
			await frame.locator('.bar__error').evaluate((error, text) => {
				error.textContent = text
			}, message)
			await fitsEveryWidth(page, message, true)
		}
	})

	test('the not-visible notice fits at every width, over the full height', async ({ page }) => {
		const frame = await submit(page, 'nieuwe-gebruiker@voorbeeld.test', 'een-nieuw-wachtwoord')
		// What `showNotVisible` builds: a real refusal needs the page to hide the bar, which the host already prevents.
		await frame.evaluate((text) => {
			const bar = document.getElementById('bar')!
			const notice = Object.assign(document.createElement('p'), { className: 'bar__text bar__notice', tabIndex: -1, textContent: text })
			bar.className = 'bar bar--notice'
			bar.replaceChildren(bar.querySelector('.logo')!, notice, bar.querySelector('.bar__close')!)
		}, catalogNl().notificationBar_notVisible!.message)
		await fitsEveryWidth(page, 'notice')
		// One row at every width, so the text has the full height.
		for (const width of WIDTHS) {
			await page.setViewportSize({ width, height: 700 })
			await expect.poll(() => frame.evaluate(() => getComputedStyle(document.getElementById('bar')!).gridTemplateRows.split(' ').length)).toBe(1)
		}
	})

	test('the update bar fits at every width', async ({ page }) => {
		const frame = await submit(page, LOGIN.username, 'een-ander-wachtwoord')
		await expect(frame.locator('.bar__title')).toHaveText(`Wachtwoord van Test site (${LOGIN.username}) bijwerken?`)
		await expect(frame.locator('.bar__item-name')).toHaveText('Test site')
		await expect(frame.locator('.bar__item-user')).toHaveText(LOGIN.username)
		await fitsEveryWidth(page)
		// Even on the narrowest card the question leaves the row its room.
		await page.setViewportSize({ width: WIDTHS[0]!, height: 700 })
		await expect.poll(() => frame.evaluate(() => window.innerWidth)).toBe(barWidth(WIDTHS[0]!))
		await expect(frame.locator('.bar__item')).toBeVisible()
	})
})

test.describe('in Dutch, on a viewport too short for the full bar', () => {
	test.use({ lang: 'nl' })

	const innerHeight = (frame: Frame) => frame.evaluate(() => window.innerHeight)
	const active = (frame: Frame) => frame.evaluate(() => document.activeElement?.tagName.toLowerCase() ?? null)
	const GROWN = barHostHeight(320, 220, true)

	async function compactBar(page: Page): Promise<Frame> {
		await page.setViewportSize({ width: 320, height: 220 })
		const frame = await submit(page, 'nieuwe-gebruiker@voorbeeld.test', 'een-nieuw-wachtwoord')
		await expect.poll(() => innerHeight(frame)).toBe(BAR_COMPACT_HEIGHT_PX)
		return frame
	}

	test('the bar is one row, and grows when it gets focus', async ({ page }) => {
		const frame = await compactBar(page)
		await expect(frame.locator('.bar__expand')).toBeVisible()
		await expect(frame.locator('.bar__buttons')).toBeHidden()
		await expect(frame.locator('select')).toBeHidden()
		// One line with an ellipsis on screen, the whole question in the page.
		const title = frame.locator('.bar__title')
		await expect(title).toHaveText(/^Login voor localhost:\d+ opslaan in Keepiq\?$/)
		expect(await title.evaluate((el) => [getComputedStyle(el).textOverflow, el.scrollWidth > el.clientWidth])).toEqual(['ellipsis', true])
		expect(await overflow(frame)).toEqual([])

		// Focus, as Tab or the shortcut gives it, grows the bar and lands on its first control.
		await frame.locator('.bar__expand').focus()
		await expect.poll(() => innerHeight(frame)).toBe(GROWN)
		await expect.poll(() => active(frame)).toBe('select')
		await expect(frame.locator('.bar__buttons')).toBeVisible()
		expect(await overflow(frame)).toEqual([])

		// Focus back in the page shrinks it to one row again, and focus on it grows it once more.
		await page.locator('a.back').focus()
		await expect.poll(() => innerHeight(frame)).toBe(BAR_COMPACT_HEIGHT_PX)
		await expect(frame.locator('.bar__expand')).toBeVisible()
		await frame.locator('.bar__expand').focus()
		await expect.poll(() => innerHeight(frame)).toBe(GROWN)
	})

	test('the bar grows when its expand control is pressed', async ({ page }) => {
		const frame = await compactBar(page)
		await frame.locator('.bar__expand').click()
		await expect.poll(() => innerHeight(frame)).toBe(GROWN)
		await expect(frame.locator('.bar__buttons')).toBeVisible()
		expect(await overflow(frame)).toEqual([])
	})
})

test('the save bar fits at every width in English', async ({ page }) => {
	const frame = await submit(page, 'new-user@example.test', 'a-new-password')
	await expect(frame.locator('.bar__title')).toHaveText(/^Save login for localhost:\d+ to Keepiq\?$/)
	await fitsEveryWidth(page)
})

test.describe('an update card for a long name without a username', () => {
	/** Wider than the row, but a question of two lines on the full card. */
	const NAME = 'Stadsdeel Nieuw-West medewerkersportaal (productie)'
	test.use({ loginItem: { name: NAME, username: null } })

	test('cuts the name off in the row with an ellipsis, and fits at every width', async ({ page }) => {
		const frame = await submit(page, '', 'a-new-password')
		await expect(frame.locator('.bar__title')).toHaveText(`Update password for ${NAME}?`)
		const name = frame.locator('.bar__item-name')
		await expect(name).toHaveText(NAME)
		await expect(frame.locator('.bar__item-user')).toHaveCount(0)
		expect(await name.evaluate((el) => [getComputedStyle(el).textOverflow, el.scrollWidth > el.clientWidth])).toEqual(['ellipsis', true])
		// Where the narrower card needs the row's room for the question, the row gives way, so nothing is clipped.
		await fitsEveryWidth(page)
		await page.setViewportSize({ width: WIDTHS[0]!, height: 700 })
		await expect(frame.locator('.bar__item')).toBeHidden()
	})
})

test('the update card hides its item row while compact', async ({ page }) => {
	await page.setViewportSize({ width: 1200, height: 2 * barHeight(1200) - 1 })
	const frame = await submit(page, LOGIN.username, 'a-new-password')
	await expect.poll(() => frame.evaluate(() => window.innerHeight)).toBe(BAR_COMPACT_HEIGHT_PX)
	await expect(frame.locator('.bar__item')).toBeHidden()
	await expect(frame.locator('.bar__expand')).toBeVisible()
	expect(await overflow(frame)).toEqual([])
})
