import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import { scanVisibleTab, type Pixels } from './qr-scan'

const SCALE = 4

/** The dark runs of a `qrcode` SVG (`M x y h n`, then `m dx dy h n`), drawn at four pixels per module on white. */
function svgPixels(file: string): Pixels {
	const svg = readFileSync(new URL(`../../test-site/${file}`, import.meta.url), 'utf8')
	const size = Number(/viewBox="0 0 (\d+) \d+"/.exec(svg)![1])
	const path = /<path stroke="#000000" d="([^"]+)"/.exec(svg)![1]
	const width = size * SCALE
	const data = new Uint8ClampedArray(width * width * 4).fill(255)
	let x = 0
	let y = 0
	for (const [, command, args] of path!.matchAll(/([Mmh])([^Mmh]*)/g)) {
		const [first = 0, second = 0] = args!.trim().split(/[\s,]+/).map(Number)
		if (command === 'M') [x, y] = [first, second]
		else if (command === 'm') [x, y] = [x + first, y + second]
		else {
			for (let module = x; module < x + first; module++) {
				for (let dy = 0; dy < SCALE; dy++) {
					for (let dx = 0; dx < SCALE; dx++) {
						const at = ((Math.floor(y) * SCALE + dy) * width + module * SCALE + dx) * 4
						data.fill(0, at, at + 3)
					}
				}
			}
			x += first
		}
	}
	return { data, width, height: width }
}

const blank = (): Pixels => ({ data: new Uint8ClampedArray(64 * 64 * 4).fill(255), width: 64, height: 64 })
const pictureOf = (pixels: Pixels) => ({ capture: async () => 'data:image/png;base64,', readPixels: async () => pixels })

describe('scanVisibleTab', () => {
	it('reads the key of the test site’s setup QR code', async () => {
		expect(await scanVisibleTab(pictureOf(svgPixels('totp-qr.svg')))).toEqual({
			key: 'otpauth://totp/Keepiq%20test%20site:test-user?secret=JBSWY3DPEHPK3PXP&issuer=Keepiq%20test%20site',
		})
	})

	it('refuses a QR code that holds a website', async () => {
		expect(await scanVisibleTab(pictureOf(svgPixels('qr-website.svg')))).toEqual({ failure: 'not_totp' })
	})

	it('finds no QR code on a blank picture', async () => {
		expect(await scanVisibleTab(pictureOf(blank()))).toEqual({ failure: 'no_qr' })
	})

	it('says the picture could not be taken when the capture throws', async () => {
		const readPixels = vi.fn()
		expect(await scanVisibleTab({ capture: () => Promise.reject(new Error('Missing activeTab permission')), readPixels })).toEqual({ failure: 'no_picture' })
		expect(readPixels).not.toHaveBeenCalled()
	})

	it('says the picture could not be taken when it does not load', async () => {
		expect(await scanVisibleTab({ capture: async () => 'data:,', readPixels: () => Promise.reject(new Error('decode')) })).toEqual({ failure: 'no_picture' })
	})

	it('passes the decoder both inversions, for dark pages', async () => {
		const decoder = vi.fn().mockReturnValue(null)
		await scanVisibleTab({ ...pictureOf(blank()), loadDecoder: async () => decoder })
		expect(decoder).toHaveBeenCalledWith(expect.any(Uint8ClampedArray), 64, 64, { inversionAttempts: 'attemptBoth' })
	})
})
