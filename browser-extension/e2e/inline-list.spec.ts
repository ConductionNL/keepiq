/**
 * The inline list in real Chromium (spec `inline-list`), on `test-site/inline-list.html` and `dialog.html`:
 * Chrome's occlusion report (IntersectionObserver v2) only exists in a real browser.
 */
import {
	clickField, expect, FILLED, holds, inlineListText, keyboardPickFills, listFrame, listOpen, listText, mouseClickCaught, mouseClicksDropped, mousePickFills,
	mousePickRefused, openPage, test, values,
} from './fixtures'
import type { Page } from '@playwright/test'
import { inlineHeight } from '@/src/messages'

const guardField = (page: Page) => page.locator('h2:text("Guard cases") ~ form input[name=username]').first()
const frameField = (page: Page, frame: string) => page.frameLocator(frame).locator('input[name=username]')
const hostHeight = (page: Page) => page.evaluate(() => document.querySelector(':root > div[popover]')?.getBoundingClientRect().height ?? null)

test('a mouse pick in the top frame fills', async ({ page }) => {
	await openPage(page)
	await clickField(page, guardField(page))
	await mousePickFills(page, guardField(page))
})

test('Down, then Enter after the guard, fills', async ({ page }) => {
	await openPage(page)
	await clickField(page, guardField(page))
	await keyboardPickFills(page, guardField(page))
})

test('a same-origin frame fills', async ({ page }) => {
	await openPage(page)
	await clickField(page, frameField(page, '#same-origin'))
	await mousePickFills(page, frameField(page, '#same-origin'))
})

for (const [name, frame] of [['a cross-site frame', '#cross-site'], ['a same-site frame on another port', '#same-site']]) {
	test(`${name} gets no list`, async ({ page }) => {
		await openPage(page)
		const field = frameField(page, frame!)
		await clickField(page, field)
		await holds(async () => !listOpen(page) && await field.evaluate(() => document.querySelectorAll('div[popover]').length) === 0)
	})
}

// The page tampers with the list's host once it appears (the buttons on inline-list.html).
const guardCases: Array<[title: string, attack: string, field: 'page' | 'frame', outcome: 'fills' | 'refused' | 'caught' | 'dropped' | 'closes']> = [
	['opacity 0 on <html> fills', 'rootOpacity', 'page', 'fills'],
	['opacity 0 restored on blur fills', 'blurDecoy', 'page', 'fills'],
	['a transform on <html> fills', 'rootTransform', 'page', 'fills'],
	['a zoom on <html> is refused', 'rootZoom', 'page', 'refused'],
	['a click-through overlay is refused', 'overlay', 'page', 'refused'],
	// Its `::after` takes the click, so the list never sees a pick to explain.
	['a page stylesheet drawing over the host fills nothing', 'stylesheet', 'page', 'caught'],
	['hidePopover() on the host closes the list', 'popover', 'page', 'closes'],
	['a style attribute on the host closes the list', 'styleAttribute', 'page', 'closes'],
	// Within its 5 s of opacity 0; Chrome drops the clicks, so the list cannot explain.
	['a transparent frame wrapper fills nothing', 'frameOpacity', 'frame', 'dropped'],
	['a masked frame wrapper is refused', 'frameMask', 'frame', 'refused'],
	['a translated frame wrapper fills', 'frameTranslate', 'frame', 'fills'],
	['a rotated frame wrapper is refused', 'frameTransform', 'frame', 'refused'],
]

for (const [title, attack, where, outcome] of guardCases) {
	test(`guard: ${title}`, async ({ page }) => {
		await openPage(page)
		await page.click(`button[data-attack=${attack}]`)
		const field = where === 'frame' ? frameField(page, '#guard-frame') : guardField(page)
		await clickField(page, field)
		if (outcome === 'fills') await mousePickFills(page, field)
		else if (outcome === 'refused') await mousePickRefused(page, field)
		else if (outcome === 'caught') await mouseClickCaught(page, field)
		else if (outcome === 'dropped') await mouseClicksDropped(page, field)
		else {
			await expect.poll(() => listOpen(page)).toBe(true)
			await expect.poll(() => listOpen(page)).toBe(false)
		}
	})
}

test('"Invert the page" fills on Chrome', async ({ page }) => {
	await openPage(page)
	await page.click('#invert')
	const field = page.locator('h2:text("Assistive styling") ~ form input[name=username]').first()
	await clickField(page, field)
	await mousePickFills(page, field)
})

for (const modal of ['plain', 'centred', 'aria']) {
	test(`the ${modal} modal shows the list inside it and fills`, async ({ page }) => {
		await page.goto('/dialog.html')
		await page.click(`[data-open=${modal}]`)
		const field = page.locator(`#${modal} input[name=username]`)
		// The ARIA modal's own script focuses the field.
		if (modal !== 'aria') await clickField(page, field)
		await expect(page.locator(`#${modal} > div[popover]`)).toHaveCount(1)
		await keyboardPickFills(page, field)
	})
}

test('the pointer over the list, pressed or not, keeps it open', async ({ page }) => {
	await openPage(page)
	await clickField(page, guardField(page))
	await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count() ?? 0).toBe(1)
	const box = (await listFrame(page)!.locator('[role=option]').first().boundingBox())!
	await page.mouse.move(box.x + 5, box.y + 5)
	await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2, { steps: 10 })
	await holds(() => listOpen(page), 1000)
	await page.mouse.down()
	await holds(() => listOpen(page), 1000)
	await page.mouse.up()
})

test.describe('with twelve matching logins', () => {
	test.use({ matches: 12 })

	test('shows five rows and scrolls to the rest', async ({ page }) => {
		await openPage(page)
		await clickField(page, guardField(page))
		await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count() ?? 0).toBe(12)
		const frame = listFrame(page)!
		const shown = () => frame.evaluate(() => [...document.querySelectorAll('[role=option]')]
			.filter((row) => row.getBoundingClientRect().bottom <= window.innerHeight + 0.5).length)
		expect(await shown()).toBe(5)
		await frame.locator('[role=option]').last().scrollIntoViewIfNeeded()
		await expect.poll(() => frame.locator('[role=option]').last().evaluate((row) => row.getBoundingClientRect().bottom <= window.innerHeight + 0.5)).toBe(true)
		expect(listOpen(page)).toBe(true)
	})
})

for (const matches of [1, 3, 7]) {
	test.describe(`with ${matches} matching logins`, () => {
		test.use({ matches })

		test('is as tall as its rows, five at most, from the moment it opens', async ({ page }) => {
			await openPage(page)
			await clickField(page, guardField(page))
			await expect.poll(() => hostHeight(page)).not.toBeNull()
			expect(await hostHeight(page)).toBe(inlineHeight(matches))
			await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count() ?? 0).toBe(matches)
			await holds(async () => await hostHeight(page) === inlineHeight(matches), 500)
		})
	})
}

test.describe('on an http page with an https login', () => {
	test.use({ loginItem: { https: true } })

	test('grows for the insecure question, shows all of it, and fills once the guard has seen it', async ({ page }) => {
		await openPage(page)
		const field = guardField(page)
		await clickField(page, field)
		await expect.poll(() => listOpen(page)).toBe(true)
		const frame = listFrame(page)!
		await expect(async () => {
			if (await frame.locator('[role=alertdialog]').count() === 0) {
				const row = (await frame.locator('[role=option]').first().boundingBox({ timeout: 1000 }))!
				await page.mouse.click(row.x + row.width / 2, row.y + row.height / 2)
			}
			await expect(frame.locator('[role=alertdialog]')).toHaveCount(1, { timeout: 1000 })
		}).toPass()
		await expect.poll(() => hostHeight(page)).toBeGreaterThan(inlineHeight(1))
		await expect.poll(() => frame.evaluate(() => {
			const list = document.getElementById('list')!
			return list.scrollHeight <= list.clientHeight
		})).toBe(true)
		await expect(async () => {
			if ((await values(field))[0] === '') {
				const fill = (await frame.locator('button.btn--primary').boundingBox({ timeout: 1000 }))!
				await page.mouse.click(fill.x + fill.width / 2, fill.y + fill.height / 2)
			}
			await expect.poll(() => values(field), { timeout: 1000 }).toEqual(FILLED)
		}).toPass()
	})
})

async function clickMessageKeepsList(page: Page, message: string) {
	await expect.poll(() => listText(page)).toContain(message)
	const box = (await listFrame(page)!.locator('p').first().boundingBox())!
	await page.mouse.click(box.x + 5, box.y + box.height / 2)
	await holds(() => listOpen(page), 1000)
}

test('a click on the no-match message leaves the list open', async ({ page, baseURL }) => {
	const other = new URL('/inline-list.html', baseURL)
	other.hostname = '127.0.0.1'
	await openPage(page, other.href)
	await clickField(page, guardField(page))
	await clickMessageKeepsList(page, inlineListText('noMatch').replace('{host}', '127.0.0.1'))
	expect(await hostHeight(page)).toBe(inlineHeight(1))
})

test.describe('locked', () => {
	test.use({ vault: 'locked' })

	test('a click on the locked message leaves the list open', async ({ page }) => {
		await openPage(page)
		await clickField(page, guardField(page))
		await clickMessageKeepsList(page, inlineListText('locked'))
		expect(await hostHeight(page)).toBe(inlineHeight(1))
	})
})
