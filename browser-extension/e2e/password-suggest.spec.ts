/**
 * The strong-password offer in real Chromium (spec `extension-generator`, Suggest a strong password in a sign-up field),
 * on `test-site/signup.html` and `test-site/change-password.html`: the built extension, a real trusted click, the fill
 * of the new-password field and its confirmation.
 *
 * @spec openspec/changes/archive/2026-11-25-extension-sign-up-password-suggestion/tasks.md#task-2.1
 */
import { readFileSync } from 'node:fs'
import { clickField, expect, holds, listFrame, listOpen, listText, test } from './fixtures'
import type { Locator, Page } from '@playwright/test'

// `inlineList.newPassword.offer` in the English catalog, which the browser shows with `locale: 'en-US'`.
const OFFER = /^ {4}offer: (.+)$/m.exec(readFileSync(new URL('../locales/en.yml', import.meta.url), 'utf8').split(/^ {2}newPassword:$/m)[1]!)![1]!

/** Both password fields of the page's form after `first` and `second`. */
const pair = (page: Page, first: string, second: string) => page.evaluate(([a, b]) => [
	document.querySelector<HTMLInputElement>(`[name=${a}]`)!.value,
	document.querySelector<HTMLInputElement>(`[name=${b}]`)!.value,
], [first, second])

/** Focuses `field` with a real click and waits for the inline list to settle open or closed. */
async function focusByClick(page: Page, field: Locator): Promise<void> {
	await clickField(page, field)
	await expect(field).toBeFocused()
}

/** A real click on the offer row, retried past the 500 ms guard that ignores a pick right after the list shows. */
async function pickOffer(page: Page, done: () => Promise<boolean>): Promise<void> {
	await expect(async () => {
		if (!(await done())) {
			const box = await listFrame(page)?.locator('[role=option]').first().boundingBox({ timeout: 1000 }).catch(() => null)
			if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
		}
		await expect.poll(done, { timeout: 1000 }).toBe(true)
	}).toPass()
}

test('sign up: one click fills both fields with the same generated password', async ({ page }) => {
	await page.goto('/signup.html')
	const field = page.locator('input[name=password]')
	await focusByClick(page, field)
	await expect.poll(() => listText(page)).toContain(OFFER)
	const filled = async () => {
		const [a, b] = await pair(page, 'password', 'password_confirm')
		return a !== '' && a === b
	}
	await pickOffer(page, filled)
	const [password, confirm] = await pair(page, 'password', 'password_confirm')
	expect(password).toBe(confirm)
	expect(password!.length).toBeGreaterThanOrEqual(12)
	// The email and username fields are not touched.
	expect(await page.locator('input[name=email]').inputValue()).toBe('')
	expect(await page.locator('input[name=username]').inputValue()).toBe('')
})

test('sign up: a click the page script dispatches on the offer fills nothing', async ({ page }) => {
	await page.goto('/signup.html')
	await focusByClick(page, page.locator('input[name=password]'))
	await expect.poll(() => listText(page)).toContain(OFFER)
	const frame = listFrame(page)!
	await frame.locator('[role=option]').first().dispatchEvent('click')
	await frame.locator('[role=option]').first().evaluate((row) => (row as HTMLElement).click())
	await holds(async () => (await pair(page, 'password', 'password_confirm')).join('') === '', 1500)
})

test('change password: the current-password field gets no offer', async ({ page }) => {
	await page.goto('/change-password.html')
	await focusByClick(page, page.locator('input[name=current]'))
	await holds(async () => !(listOpen(page) && (await listText(page))?.includes(OFFER)), 1500)
})

test('change password: the new-password field gets the offer and fills new and confirm only', async ({ page }) => {
	await page.goto('/change-password.html')
	await focusByClick(page, page.locator('input[name=new]'))
	await expect.poll(() => listText(page)).toContain(OFFER)
	await pickOffer(page, async () => {
		const [a, b] = await pair(page, 'new', 'confirm')
		return a !== '' && a === b
	})
	expect(await page.locator('input[name=current]').inputValue()).toBe('')
})
