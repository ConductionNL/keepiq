/**
 * TOTP autofill in real Chromium (spec `totp-autofill`): the list on a code field, its pick in each layout of the
 * test site, and the code the page then accepts as right for its key.
 */
import { clickField, expect, holds, listFrame, listOpen, listText, test } from './fixtures'
import type { Locator, Page } from '@playwright/test'

test.use({ totp: true })

const codeInputs = (page: Page) => page.locator('[data-code]')
const typed = async (page: Page) => (await codeInputs(page).evaluateAll((inputs: HTMLInputElement[]) => inputs.map((input) => input.value))).join('')

/** Clicks the code row, retrying while the list's 500 ms guard ignores it, until the page's inputs hold the code. */
async function pickCode(page: Page, field: Locator): Promise<void> {
	await clickField(page, field)
	await expect(async () => {
		if ((await typed(page)).length < 6) {
			const box = await listFrame(page)?.locator('[role=option]').first().boundingBox({ timeout: 1000 }).catch(() => null)
			if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
		}
		await expect.poll(() => typed(page), { timeout: 1000 }).toMatch(/^\d{6}$/)
	}).toPass()
}

async function submitAccepted(page: Page): Promise<void> {
	await page.locator('button[type=submit]').click()
	await expect(page.locator('.status')).toHaveAttribute('data-result', 'accepted')
}

for (const [layout, url, inputs] of [['one field', '/otp.html', 1], ['two halves of three', '/otp-halves.html', 2], ['six boxes', '/otp-split.html', 6]] as const) {
	test(`the code list fills ${layout} with the code the site accepts`, async ({ page }) => {
		await page.goto(url)
		await expect(codeInputs(page)).toHaveCount(inputs)
		await pickCode(page, codeInputs(page).first())
		const values = await codeInputs(page).evaluateAll((all: HTMLInputElement[]) => all.map((input) => input.value.length))
		expect(values).toEqual(inputs === 1 ? [6] : inputs === 2 ? [3, 3] : [1, 1, 1, 1, 1, 1])
		await submitAccepted(page)
	})
}

test('the code list opens in code mode with the item and its code', async ({ page }) => {
	await page.goto('/otp.html')
	await clickField(page, codeInputs(page).first())
	await expect.poll(() => listText(page)).toMatch(/Test site test-user \d{3} \d{3} \d{1,2}/)
	expect(await listFrame(page)!.title()).toBe('Keepiq verification codes')
})

test('a login pick on a form with a code field fills the code too', async ({ page }) => {
	await page.goto('/login-otp.html')
	const username = page.locator('input[name=username]')
	await clickField(page, username)
	await expect(async () => {
		if (await username.inputValue() === '') {
			const box = await listFrame(page)?.locator('[role=option]').first().boundingBox({ timeout: 1000 }).catch(() => null)
			if (box) await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
		}
		await expect(page.locator('input[name=otp]')).toHaveValue(/^\d{6}$/, { timeout: 1000 })
	}).toPass()
	await expect(page.locator('input[name=password]')).toHaveValue('test-password')
})

for (const [layout, step] of [['One field', '/otp.html'], ['Two halves of three', '/otp-halves.html'], ['Six boxes', '/otp-split.html']] as const) {
	test(`a login leads to the ${layout.toLowerCase()} code step, where the list fills the code`, async ({ page }) => {
		await page.goto('/totp-login.html')
		await page.getByLabel(layout).check()
		await page.locator('input[name=username]').fill('test-user')
		await page.locator('input[name=password]').fill('test-password')
		await page.locator('button[type=submit]').click()
		await expect(page).toHaveURL(step)
		await pickCode(page, codeInputs(page).first())
		await submitAccepted(page)
	})
}

test('a recovery code field opens no list', async ({ page }) => {
	await page.goto('/recovery-code.html')
	await clickField(page, page.locator('input[name=recovery_code]'))
	await holds(() => !listOpen(page))
})

test.describe('locked', () => {
	test.use({ vault: 'locked' })

	test('the code list shows no code, name or username', async ({ page }) => {
		await page.goto('/otp-split.html')
		await clickField(page, codeInputs(page).first())
		await expect.poll(() => listText(page)).toBe('Unlock Keepiq to see your verification codes Unlock')
	})
})
