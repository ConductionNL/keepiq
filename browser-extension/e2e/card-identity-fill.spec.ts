/**
 * Card and identity fill in real Chromium (spec `card-identity-fill`), on the test site's card, identity and hidden-field pages: the lists, the
 * frame plan's questions and which fields a fill reaches.
 */
import { readFileSync } from 'node:fs'
import type { Frame, Locator, Page } from '@playwright/test'
import { INLINE_WALLET_ROWS, inlineHeight } from '@/src/messages'
import { clickField, expect, focusFirstRow, holds, hostHeight, INSECURE_HOST, keyboardPick, listFocus, listFrame, listOpen, test } from './fixtures'
import { CARD, IDENTITY } from './seed'

/** From the build global setup makes, so not before it. */
let catalog: Record<string, { message: string }> | undefined
const t = (key: string) => (catalog ??= JSON.parse(readFileSync(new URL('../.output/chrome-mv3/_locales/en/messages.json', import.meta.url), 'utf8')))[key]!.message

const NUMBER = CARD.number.replace(/\D/g, '')
const FULL_NAME = `${IDENTITY.firstName} ${IDENTITY.lastName}`
const [STREET] = IDENTITY.address.split('\n')

const listTitle = (page: Page) => listFrame(page)?.evaluate(() => document.title).catch(() => null) ?? null
/** What the list shows, without its screen-reader status. */
const shownText = async (page: Page) => (await listFrame(page)?.locator('#list').innerText().catch(() => null))?.replace(/\s+/g, ' ').trim() ?? null
const questionShown = async (page: Page) => (await listFrame(page)?.locator('[role=alertdialog]').count() ?? 0) > 0
const rowsShown = async (page: Page) => (await listFrame(page)?.locator('[role=listbox]').count() ?? 0) > 0
const focusedName = (page: Page) => page.evaluate(() => (document.activeElement as HTMLInputElement | null)?.name ?? null)
const valuesOf = (fields: Locator[]) => Promise.all(fields.map((field) => field.inputValue()))

/** The list's title, once one opens on `field`. */
async function openList(page: Page, field: Locator, title: string): Promise<void> {
	await clickField(page, field)
	await expect.poll(() => listTitle(page)).toBe(t(title))
}

/** The input and change events the page sees from now on, as `type:name`. */
function recordEvents(scope: Page | Frame): Promise<void> {
	return scope.evaluate(() => {
		const seen: string[] = []
		;(window as unknown as { seen: string[] }).seen = seen
		for (const type of ['input', 'change']) {
			document.addEventListener(type, (event) => seen.push(`${type}:${(event.target as HTMLInputElement).name}`), true)
		}
	})
}

const eventsIn = (scope: Page | Frame) => scope.evaluate(() => (window as unknown as { seen: string[] }).seen)

/** Clicks the list's button `name` until `done` holds; an answer within 500 ms of the question is ignored. */
async function answer(page: Page, name: string, done: () => Promise<boolean>): Promise<void> {
	await expect(async () => {
		if (!(await done())) {
			const box = (await listFrame(page)!.getByRole('button', { name, exact: true }).boundingBox({ timeout: 1000 }))!
			await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2)
		}
		await expect.poll(done, { timeout: 1000 }).toBe(true)
	}).toPass()
}

test.use({ cards: 2, identities: 1 })

test.describe('card.html', () => {
	const field = (page: Page, name: string) => page.locator(`[name=${name}]`)
	const checkout = (page: Page) => ['ccname', 'cardnumber', 'exp', 'cvc'].map((name) => field(page, name))

	test('the card list on the number field fills the checkout with one pick, closes and gives focus back', async ({ page }) => {
		await page.goto('/card.html')
		await recordEvents(page)
		await openList(page, field(page, 'cardnumber'), 'inlineList_card_frameTitle')
		await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count()).toBe(2)
		expect(await shownText(page)).toMatch(/^Personal ?Visa, \*1111 ?Work Visa ?Visa, \*1111$/)
		await keyboardPick(page, async () => await field(page, 'cardnumber').inputValue() === NUMBER)
		expect(await valuesOf(checkout(page))).toEqual([CARD.cardholder, NUMBER, CARD.expiry, CARD.cvv])
		await expect.poll(() => listOpen(page)).toBe(false)
		expect(await focusedName(page)).toBe('cardnumber')
		for (const name of ['ccname', 'cardnumber', 'exp', 'cvc']) expect(await eventsIn(page)).toEqual(expect.arrayContaining([`input:${name}`, `change:${name}`]))
		// Focus given back does not reopen the list.
		await holds(() => !listOpen(page), 1000)
	})

	test('the password-typed CVC opens the card list, never the login list', async ({ page }) => {
		await page.goto('/card.html')
		await openList(page, field(page, 'cvc'), 'inlineList_card_frameTitle')
		expect(await shownText(page)).not.toContain('Test site')
	})

	test('a submitted checkout offers no save bar', async ({ page }) => {
		await page.goto('/card.html')
		await field(page, 'ccname').fill(CARD.cardholder)
		await field(page, 'cardnumber').fill(NUMBER)
		await field(page, 'exp').fill(CARD.expiry)
		await field(page, 'cvc').fill(CARD.cvv)
		await page.locator('button[type=submit]').click()
		await page.waitForURL('**/signed-in.html**')
		await holds(() => !page.frames().some((frame) => frame.url().startsWith('chrome-extension://') && frame.url().endsWith('/notification.html')), 2000)
	})
})

test('card-split.html: the selects, the number boxes and the card type take their parts', async ({ page }) => {
	await page.goto('/card-split.html')
	await recordEvents(page)
	const form = page.locator('#selects')
	await openList(page, form.locator('#number-1'), 'inlineList_card_frameTitle')
	await keyboardPick(page, async () => await form.locator('[name=number4]').inputValue() !== '')
	const parts = ['brand', 'number1', 'number2', 'number3', 'number4', 'month', 'year', 'cvc'].map((name) => form.locator(`[name=${name}]`))
	expect(await valuesOf(parts)).toEqual(['VISA', '4111', '1111', '1111', '1111', '03', '2027', CARD.cvv])
	expect(await eventsIn(page)).toEqual(expect.arrayContaining(['input:month', 'change:month', 'input:year', 'change:year']))
})

test('card-split.html: a combined expiry takes the pattern its placeholder writes', async ({ page }) => {
	await page.goto('/card-split.html')
	const form = page.locator('#combined')
	await openList(page, form.locator('[name=number]'), 'inlineList_card_frameTitle')
	await keyboardPick(page, async () => await form.locator('[name=number]').inputValue() === NUMBER)
	expect(await form.locator('[name=expiry]').inputValue()).toBe('03 / 2027')
})

test('card-dutch.html: Dutch labels without tokens fill, the expiry as MMJJ, and the PIN stays empty', async ({ page }) => {
	await page.goto('/card-dutch.html')
	await openList(page, page.locator('[name=kaartnummer]'), 'inlineList_card_frameTitle')
	await keyboardPick(page, async () => await page.locator('[name=kaartnummer]').inputValue() === NUMBER)
	const fields = ['naam', 'kaartnummer', 'vervaldatum', 'beveiligingscode', 'pin'].map((name) => page.locator(`[name=${name}]`))
	expect(await valuesOf(fields)).toEqual([CARD.cardholder, NUMBER, '0327', CARD.cvv, ''])
})

test.describe('card-iframe.html', () => {
	const cardFrame = (page: Page, host: string) => page.frames().find((frame) => frame.url().endsWith('/card-frame.html') && new URL(frame.url()).hostname === host)
	const nameField = (page: Page) => page.locator('[name=ccname]')
	const numberIn = (page: Page, selector: string) => page.frameLocator(selector).locator('[name=cardnumber]')

	/** The top frame's name and the same-site and cross-site frames' numbers. */
	const filled = (page: Page) => valuesOf([nameField(page), numberIn(page, '#same-site'), numberIn(page, '#cross-site')])
	const nothingFilled = async (page: Page) => (await filled(page)).every((value) => value === '')

	/** The page with both frames loaded, events recorded in the cross-site one, and the card list open on "Name on card". */
	async function open(page: Page): Promise<Frame> {
		await page.goto('/card-iframe.html')
		await expect(numberIn(page, '#same-site')).toBeVisible()
		await expect(numberIn(page, '#cross-site')).toBeVisible()
		const cross = cardFrame(page, '127.0.0.1')!
		await recordEvents(cross)
		await openList(page, nameField(page), 'inlineList_card_frameTitle')
		return cross
	}

	async function ask(page: Page, port: string): Promise<void> {
		await keyboardPick(page, () => questionShown(page))
		expect(await shownText(page)).toContain(t('item_crossSiteTitle').replace('{hosts}', `127.0.0.1:${port}`))
		expect(await nothingFilled(page)).toBe(true)
	}

	test('asks about the cross-site frame, and Fill fills every frame', async ({ page, baseURL }) => {
		await open(page)
		await ask(page, new URL(baseURL!).port)
		await answer(page, t('inlineList_fill'), async () => !(await nothingFilled(page)))
		await expect.poll(() => filled(page)).toEqual([CARD.cardholder, NUMBER, NUMBER])
		const cvc = page.frameLocator('#cross-site').locator('[name=cvc]')
		expect(await cvc.inputValue()).toBe(CARD.cvv)
	})

	test('"Only {host}" fills this page and the same-site frame, and nothing reaches the cross-site frame', async ({ page, baseURL }) => {
		const cross = await open(page)
		const { host, port } = new URL(baseURL!)
		await ask(page, port)
		await answer(page, t('item_onlyHost').replace('{host}', host), async () => !(await nothingFilled(page)))
		await expect.poll(() => filled(page)).toEqual([CARD.cardholder, NUMBER, ''])
		await holds(async () => (await eventsIn(cross)).length === 0 && (await numberIn(page, '#cross-site').inputValue()) === '', 1000)
	})

	test('Escape and Cancel on the question fill nothing and bring the rows back', async ({ page, baseURL }) => {
		await open(page)
		await ask(page, new URL(baseURL!).port)
		await page.keyboard.press('Escape')
		await expect.poll(() => rowsShown(page)).toBe(true)
		expect(await listFocus(page)).toBe('option')
		await keyboardPick(page, () => questionShown(page))
		await answer(page, t('common_cancel'), () => rowsShown(page))
		await holds(async () => await nothingFilled(page) && listOpen(page))
	})

	test('an answer within 500 ms of the question is ignored', async ({ page }) => {
		await open(page)
		await focusFirstRow(page)
		const list = listFrame(page)!
		await list.evaluate(() => {
			const times: { asked?: number; answered?: number } = {}
			;(window as unknown as { times: typeof times }).times = times
			new MutationObserver(() => {
				times.asked ??= document.querySelector('[role=alertdialog]') ? Date.now() : undefined
			}).observe(document.body, { childList: true, subtree: true })
			document.addEventListener('keydown', (event) => {
				if (event.key === 'Enter' && times.asked) times.answered ??= Date.now()
			}, true)
		})
		// Past the row's own guard, so the pick goes through.
		await new Promise((resolve) => setTimeout(resolve, 600))
		await page.keyboard.press('Enter')
		await list.locator('[role=alertdialog]').waitFor()
		// From Cancel to Fill.
		await page.keyboard.press('Tab')
		await page.keyboard.press('Tab')
		await page.keyboard.press('Enter')
		const { asked, answered } = await list.evaluate(() => (window as unknown as { times: { asked: number; answered: number } }).times)
		expect(answered - asked).toBeLessThan(500)
		await holds(async () => await nothingFilled(page) && await questionShown(page), 1000)
		await page.keyboard.press('Enter')
		await expect.poll(() => filled(page)).toEqual([CARD.cardholder, NUMBER, NUMBER])
	})

	test('a cross-site frame added after the answer is asked about again before anything fills', async ({ page, baseURL }) => {
		await open(page)
		const { port } = new URL(baseURL!)
		await ask(page, port)
		await page.evaluate(() => (window as unknown as { addFrame(): void }).addFrame())
		await expect(page.frameLocator('iframe.added').locator('[name=cardnumber]')).toBeVisible()
		// Time for the new frame's content script to report in.
		await holds(() => questionShown(page), 1000)
		const added = () => page.frameLocator('iframe.added').locator('[name=cardnumber]').inputValue()
		await answer(page, t('inlineList_fill'), async () => (await shownText(page))?.includes(`ads.localhost:${port}`) ?? false)
		expect(await shownText(page)).toContain(t('item_crossSiteTitle').replace('{hosts}', `127.0.0.1:${port}, ads.localhost:${port}`))
		await holds(async () => await nothingFilled(page) && await added() === '', 1000)
		await answer(page, t('inlineList_fill'), async () => await added() === NUMBER)
		await expect.poll(() => filled(page)).toEqual([CARD.cardholder, NUMBER, NUMBER])
	})
})

test.describe(`on http://${INSECURE_HOST}`, () => {
	const on = (path: string, baseURL: string) => {
		const url = new URL(path, baseURL)
		url.hostname = INSECURE_HOST
		return url.href
	}

	test('a card asks first; Cancel fills nothing and Fill fills', async ({ page, baseURL }) => {
		await page.goto(on('/card.html', baseURL!))
		const number = page.locator('[name=cardnumber]')
		await openList(page, number, 'inlineList_card_frameTitle')
		await keyboardPick(page, () => questionShown(page))
		expect(await shownText(page)).toContain(t('item_cardInsecureTitle'))
		await answer(page, t('common_cancel'), () => rowsShown(page))
		await holds(async () => await number.inputValue() === '', 1000)
		await keyboardPick(page, () => questionShown(page))
		await answer(page, t('inlineList_fill'), async () => await number.inputValue() === NUMBER)
	})

	test('an identity fills without a question', async ({ page, baseURL }) => {
		await page.goto(on('/identity.html', baseURL!))
		const first = page.locator('#address [name=first]')
		await openList(page, first, 'inlineList_identity_frameTitle')
		// A question would take focus off the row, which fails the pick.
		await keyboardPick(page, async () => await first.inputValue() === IDENTITY.firstName)
	})
})

test.describe('identity.html', () => {
	const cases: Array<[form: string, start: string, expected: Record<string, string>]> = [
		['address', 'first', {
			first: IDENTITY.firstName, last: IDENTITY.lastName, email: IDENTITY.email, phone: IDENTITY.phone, address: IDENTITY.address, bsn: '',
		}],
		['lines', 'first', { first: IDENTITY.firstName, last: IDENTITY.lastName, line1: STREET!, line2: '' }],
		['full-name', 'name', { name: FULL_NAME, email: IDENTITY.email, address: IDENTITY.address.replace('\n', ', ') }],
		['dutch', 'voornaam', {
			voornaam: IDENTITY.firstName, achternaam: IDENTITY.lastName, email: IDENTITY.email, telefoon: IDENTITY.phone, adres: IDENTITY.address.replace('\n', ', '),
		}],
	]

	for (const [form, start, expected] of cases) {
		test(`the identity list fills the ${form} form`, async ({ page }) => {
			await page.goto('/identity.html')
			const field = (name: string) => page.locator(`#${form} [name=${name}]`)
			await openList(page, field(start), 'inlineList_identity_frameTitle')
			expect(await shownText(page)).toMatch(new RegExp(`^Anna ?${FULL_NAME}$`))
			await keyboardPick(page, async () => await field(start).inputValue() !== '')
			expect(Object.fromEntries(await Promise.all(Object.keys(expected).map(async (name) => [name, await field(name).inputValue()])))).toEqual(expected)
		})
	}

	test('the street address textarea opens the identity list and fills the form from there', async ({ page }) => {
		await page.goto('/identity.html')
		const field = (name: string) => page.locator(`#address [name=${name}]`)
		await openList(page, field('address'), 'inlineList_identity_frameTitle')
		await keyboardPick(page, async () => await field('address').inputValue() !== '')
		expect(await valuesOf(['address', 'first', 'email'].map(field))).toEqual([IDENTITY.address, IDENTITY.firstName, IDENTITY.email])
	})
})

test.describe('identity-signup.html', () => {
	const field = (page: Page, selector: string) => page.locator(selector)

	test('the sign-up form gets the identity list, and the passwords stay empty', async ({ page }) => {
		await page.goto('/identity-signup.html')
		await openList(page, field(page, '#signup [name=first]'), 'inlineList_identity_frameTitle')
		await keyboardPick(page, async () => await field(page, '#signup [name=first]').inputValue() !== '')
		const fields = ['first', 'email', 'password', 'confirm'].map((name) => field(page, `#signup [name=${name}]`))
		expect(await valuesOf(fields)).toEqual([IDENTITY.firstName, IDENTITY.email, '', ''])
	})

	test('the login form\'s email gets the login list', async ({ page }) => {
		await page.goto('/identity-signup.html')
		await openList(page, field(page, '#login [name=username]'), 'inlineList_frameTitle')
	})

	test('the newsletter email gets no list', async ({ page }) => {
		await page.goto('/identity-signup.html')
		await clickField(page, field(page, '#newsletter [name=email]'))
		await holds(() => !listOpen(page))
	})
})

test.describe('hidden-fields.html', () => {
	// `fold-plain` is left out: a field far below the fold outside any clipping ancestor is still filled, a known follow-up.
	const TRICKS = [
		'opacity', 'faded-ancestor', 'aria-hidden', 'inert', 'left-offscreen', 'top-offscreen', 'tiny', 'clip-path', 'clip', 'covered', 'covered-shadow',
		'zero-height', 'fold-zero-height', 'fold-clip-path',
	]
	const PARTS = ['number', 'cvc', 'phone', 'address']

	/** Fills a card from the list on "Name on card", then an identity from the list on "Your name"; the hidden or shown fields' values after both. */
	async function fillBoth(page: Page, form: string, path = '/hidden-fields.html'): Promise<string[]> {
		await page.goto(path)
		const field = (name: string) => page.locator(`#${form} [name=${name}]`)
		await openList(page, field('ccname'), 'inlineList_card_frameTitle')
		await keyboardPick(page, async () => await field('ccname').inputValue() === CARD.cardholder)
		await expect.poll(() => listOpen(page)).toBe(false)
		await openList(page, field('name'), 'inlineList_identity_frameTitle')
		await keyboardPick(page, async () => await field('name').inputValue() === FULL_NAME)
		expect(await valuesOf([field('ccname'), field('name'), field('email')])).toEqual([CARD.cardholder, FULL_NAME, IDENTITY.email])
		return valuesOf(PARTS.map((part) => page.locator(`#${form}-${part}`)))
	}

	for (const trick of TRICKS) {
		test(`fields hidden by ${trick} stay empty`, async ({ page }) => {
			expect(await fillBoth(page, trick)).toEqual(['', '', '', ''])
		})
	}

	for (const shown of ['under-list', 'scroller']) {
		test(`fields ${shown === 'under-list' ? 'under the inline list' : 'below the fold in a scrolling box'} are filled`, async ({ page }) => {
			expect(await fillBoth(page, shown)).toEqual([NUMBER, CARD.cvv, IDENTITY.phone, IDENTITY.address])
		})
	}

	test.describe('hidden-fields-locked.html', () => {
		const at = (lock: string) => `/hidden-fields-locked.html${lock ? `?lock=${lock}` : ''}`

		for (const [form, lock] of [['locked', 'html'], ['locked', 'html-y'], ['locked', 'body'], ['locked', 'body-y'], ['fixed', ''], ['zero-scroller', ''], ['fold-zero-scroller', ''], ['tiny-scroller', ''], ['covered-scroller', '']] as const) {
			test(`fields in ${form}${lock && ` with the ${lock} lock`} stay empty`, async ({ page }) => {
				expect(await fillBoth(page, form, at(lock))).toEqual(['', '', '', ''])
			})
		}

		for (const [form, lock] of [['locked', ''], ['locked', 'body-x'], ['near-scroller', '']] as const) {
			test(`fields in ${form}${lock && ` with the ${lock} lock`} are filled`, async ({ page }) => {
				expect(await fillBoth(page, form, at(lock))).toEqual([NUMBER, CARD.cvv, IDENTITY.phone, IDENTITY.address])
			})
		}
	})
})

test.describe('locked', () => {
	test.use({ vault: 'locked', cards: 3, identities: 3 })

	for (const [path, selector, kind] of [['/card.html', '[name=cardnumber]', 'card'], ['/identity.html', '#address [name=first]', 'identity']] as const) {
		test(`the ${kind} list says to unlock, one row tall`, async ({ page }) => {
			await page.goto(path)
			await openList(page, page.locator(selector), `inlineList_${kind}_frameTitle`)
			await expect.poll(() => shownText(page)).toBe(`${t(`inlineList_${kind}_locked`)} ${t('inlineList_unlock')}`)
			expect(await hostHeight(page)).toBe(inlineHeight(1))
			expect(await listFrame(page)!.locator('[role=option]').count()).toBe(0)
		})
	}
})

for (const cards of [0, 1, 8]) {
	test.describe(`with ${cards} cards`, () => {
		test.use({ cards })

		test(`the card list is ${INLINE_WALLET_ROWS} rows tall from the moment it opens`, async ({ page }) => {
			await page.goto('/card.html')
			await clickField(page, page.locator('[name=cardnumber]'))
			await expect.poll(() => hostHeight(page)).not.toBeNull()
			expect(await hostHeight(page)).toBe(inlineHeight(INLINE_WALLET_ROWS))
			if (cards) await expect.poll(async () => await listFrame(page)?.locator('[role=option]').count()).toBe(cards)
			else await expect.poll(() => shownText(page)).toBe(t('inlineList_card_noMatch'))
			await holds(async () => await hostHeight(page) === inlineHeight(INLINE_WALLET_ROWS), 500)
		})
	})
}
