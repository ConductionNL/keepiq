/** Reads an authenticator key from a picture of the visible tab, as the web app's `src/totp/qrImage.js` reads one from an image file. The picture never leaves this call. */
import { otpauthFromQrText } from './authenticator-key'

export type ScanFailure = 'no_picture' | 'no_qr' | 'not_totp'
export type ScanResult = { key: string } | { failure: ScanFailure }

export interface Pixels {
	data: Uint8ClampedArray
	width: number
	height: number
}

export type QrDecoder = (data: Uint8ClampedArray, width: number, height: number, options?: { inversionAttempts?: 'attemptBoth' }) => { data: string } | null

export interface ScanDeps {
	capture?: () => Promise<string>
	readPixels?: (dataUrl: string) => Promise<Pixels>
	loadDecoder?: () => Promise<QrDecoder>
}

/** A high-density screen's picture holds 8 million pixels and more; a setup QR code stays readable at this size. */
const MAX_SIDE = 2048

const capture = () => browser.tabs.captureVisibleTab({ format: 'png' })

async function readPixels(dataUrl: string): Promise<Pixels> {
	const image = new Image()
	image.src = dataUrl
	await image.decode()
	const scale = Math.min(1, MAX_SIDE / Math.max(image.naturalWidth, image.naturalHeight))
	const width = Math.max(1, Math.round(image.naturalWidth * scale))
	const height = Math.max(1, Math.round(image.naturalHeight * scale))
	const canvas = document.createElement('canvas')
	canvas.width = width
	canvas.height = height
	const context = canvas.getContext('2d', { willReadFrequently: true })
	if (!context) throw new Error('No 2D canvas context')
	context.drawImage(image, 0, 0, width, height)
	return context.getImageData(0, 0, width, height)
}

/** In its own chunk, so the popup's first one stays small. */
async function loadDecoder(): Promise<QrDecoder> {
	const module: { default?: unknown } = await import('jsqr')
	const exported = module.default ?? module
	return (typeof exported === 'function' ? exported : (exported as { default: unknown }).default) as QrDecoder
}

export async function scanVisibleTab(deps: ScanDeps = {}): Promise<ScanResult> {
	let pixels: Pixels
	let decode: QrDecoder
	try {
		pixels = await (deps.readPixels ?? readPixels)(await (deps.capture ?? capture)())
		decode = await (deps.loadDecoder ?? loadDecoder)()
	} catch {
		// A browser page, or Firefox before 126 under `activeTab` (design.md).
		return { failure: 'no_picture' }
	}
	const found = decode(pixels.data, pixels.width, pixels.height, { inversionAttempts: 'attemptBoth' })
	if (!found?.data) return { failure: 'no_qr' }
	const key = otpauthFromQrText(found.data)
	return key ? { key } : { failure: 'not_totp' }
}
