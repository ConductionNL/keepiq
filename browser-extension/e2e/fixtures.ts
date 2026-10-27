import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { chromium, expect, test as base, type Frame, type Locator, type Page } from '@playwright/test'
import { fakeStorage, LOGIN, type FakeStorage } from './seed'

const EXTENSION = fileURLToPath(new URL('../.output/chrome-mv3', import.meta.url))

/**
 * The built extension in headless Chromium, with a fresh profile and the fake account per test. `lang: 'nl'` picks
 * the Dutch catalog through `--lang`; `longFolder` adds the nested folders of `LONG_FOLDER`; `matches` is the
 * number of logins for the test site.
 */
export const test = base.extend<{ vault: 'unlocked' | 'locked'; lang: 'en' | 'nl'; longFolder: boolean; matches: number }>({
	vault: ['unlocked', { option: true }],
	lang: ['en', { option: true }],
	longFolder: [false, { option: true }],
	matches: [1, { option: true }],
	context: async ({ vault, lang, longFolder, matches, baseURL }, use) => {
		const context = await chromium.launchPersistentContext('', {
			channel: 'chromium',
			headless: true,
			locale: lang === 'nl' ? 'nl-NL' : 'en-US',
			viewport: { width: 1200, height: 900 },
			args: [
				`--disable-extensions-except=${EXTENSION}`,
				`--load-extension=${EXTENSION}`,
				// Nothing but the test site resolves, so no request can leave the machine.
				'--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1',
				...(lang === 'nl' ? ['--lang=nl'] : []),
			],
		})
		const worker = context.serviceWorkers().find((sw) => sw.url().startsWith('chrome-extension://'))
			?? await context.waitForEvent('serviceworker', (sw) => sw.url().startsWith('chrome-extension://'))
		// The built worker has `chrome`, not WXT's `browser`.
		await worker.evaluate(async ({ local, session }: FakeStorage) => {
			const { storage } = (globalThis as unknown as { chrome: { storage: Record<'local' | 'session', { set(items: object): Promise<void> }> } }).chrome
			await storage.local.set(local)
			await storage.session.set(session)
		}, await fakeStorage(new URL(baseURL!).origin, vault === 'unlocked', { longFolder, matches }))
		await use(context)
		await context.close()
	},
	page: async ({ context }, use) => {
		await use(await context.newPage())
	},
})

export { expect }

export const FILLED = [LOGIN.username, LOGIN.password]
const catalog = readFileSync(new URL('../locales/en.yml', import.meta.url), 'utf8')

/** A plain string under `inlineList:` in the English catalog, which the browser shows with `locale: 'en-US'`. */
export function inlineListText(name: string): string {
	return new RegExp(`^inlineList:\\n(?: .*\\n)*? {2}${name}: (.+)$`, 'm').exec(catalog)![1]!
}

const NOT_VISIBLE = inlineListText('notVisible')

export function listFrame(page: Page): Frame | undefined {
	return page.frames().find((frame) => frame.url().startsWith('chrome-extension://') && frame.url().endsWith('/inline-list.html'))
}

export const listOpen = (page: Page) => listFrame(page) !== undefined

export async function listText(page: Page): Promise<string | null> {
	const frame = listFrame(page)
	return frame ? (await frame.locator('body').innerText().catch(() => null))?.replace(/\s+/g, ' ').trim() ?? null : null
}

/** The `role` of what has focus in the list, `null` without a list. */
export async function listFocus(page: Page): Promise<string | null> {
	return await listFrame(page)?.evaluate(() => document.activeElement?.getAttribute('role') ?? null).catch(() => null) ?? null
}

/** The username and password of the field's form. */
export const values = (field: Locator) => field.evaluate((input: HTMLInputElement) => [input.value, input.form!.querySelector<HTMLInputElement>('[name=password]')!.value])

/** A real click, as a user's, so the guard sees trusted input at real coordinates. */
export async function clickField(page: Page, field: Locator): Promise<void> {
	await field.evaluate((element) => element.scrollIntoView({ block: 'center' }))
	const box = (await field.boundingBox())!
	await page.mouse.click(box.x + 10, box.y + box.height / 2)
}

async function clickFirstRow(page: Page): Promise<void> {
	const box = await listFrame(page)?.locator('[role=option]').first().boundingBox({ timeout: 1000 }).catch(() => null)
	if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
}

/** Loads a test-site page and closes the list its `autofocus` field opens, so each case starts with none. */
export async function openPage(page: Page, url = '/inline-list.html'): Promise<void> {
	await page.goto(url)
	await expect.poll(() => listOpen(page)).toBe(true)
	await page.keyboard.press('Escape')
	await expect.poll(() => listOpen(page)).toBe(false)
}

// A pick within 500 ms of the list showing is ignored without a word, so the pickers below retry until it lands.

export async function mousePickFills(page: Page, field: Locator): Promise<void> {
	await expect(async () => {
		if ((await values(field))[0] !== LOGIN.username) await clickFirstRow(page)
		await expect.poll(() => values(field), { timeout: 1000 }).toEqual(FILLED)
	}).toPass()
}

export async function mousePickRefused(page: Page, field: Locator): Promise<void> {
	await expect(async () => {
		if (!(await listText(page))?.includes(NOT_VISIBLE)) await clickFirstRow(page)
		await expect.poll(() => listText(page), { timeout: 1000 }).toContain(NOT_VISIBLE)
	}).toPass()
	expect(await values(field)).toEqual(['', ''])
}

/** A click where a row is that the page catches before the list does: the list closes and nothing fills. */
export async function mouseClickCaught(page: Page, field: Locator): Promise<void> {
	await expect(async () => {
		if (listOpen(page)) await clickFirstRow(page)
		await expect.poll(() => listOpen(page), { timeout: 1000 }).toBe(false)
	}).toPass()
	await holds(async () => (await values(field)).join('') === '', 1000)
}

/** Down, which within 500 ms of a script's focus is ignored too, then Enter on the row. */
export async function keyboardPickFills(page: Page, field: Locator): Promise<void> {
	await expect(async () => {
		if (await listFocus(page) !== 'option') await page.keyboard.press('ArrowDown')
		await expect.poll(() => listFocus(page), { timeout: 1000 }).toBe('option')
	}).toPass()
	await expect(async () => {
		if ((await values(field))[0] !== LOGIN.username) {
			// Enter outside the list would submit the page's form.
			expect(await listFocus(page)).toBe('option')
			await page.keyboard.press('Enter')
		}
		await expect.poll(() => values(field), { timeout: 1000 }).toEqual(FILLED)
	}).toPass()
}

/** Chrome drops input to a frame it reports invisible, so clicks past the guard reach neither the list nor the form. */
export async function mouseClicksDropped(page: Page, field: Locator): Promise<void> {
	await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count() ?? 0).toBe(1)
	await holds(async () => {
		await clickFirstRow(page)
		return (await values(field)).join('') === '' && (await listFrame(page)?.locator('[role=option]').count()) === 1
	})
}

/** Absence can't be awaited, so this checks on every poll for `ms` and fails as soon as it turns. */
export async function holds(check: () => boolean | Promise<boolean>, ms = 1500): Promise<void> {
	for (const until = Date.now() + ms; Date.now() < until;) {
		expect(await check()).toBe(true)
		await new Promise((resolve) => setTimeout(resolve, 100))
	}
}
