// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest'
import { INLINE_MIN_HEIGHT_PX, INLINE_MIN_WIDTH_PX } from '@/src/messages'
import { placement } from './inline-host'

const view = vi.hoisted(() => ({ width: 1000, height: 800 }))
vi.mock('./frame-host', async (importOriginal) => ({ ...await importOriginal<typeof import('./frame-host')>(), viewport: () => view }))

function field(top: number, left: number, width: number, height: number): Element {
	const element = document.createElement('input')
	element.getBoundingClientRect = () => ({ top, left, width, height, bottom: top + height, right: left + width, x: left, y: top, toJSON: () => ({}) })
	return element
}

const MIN = INLINE_MIN_HEIGHT_PX

describe('placement', () => {
	it.each([
		['a field with no box', field(100, 100, 0, 0)],
		['a field above the viewport', field(-30, 100, 300, 30)],
		['a field below the viewport', field(800, 100, 300, 30)],
		['a field left of the viewport', field(100, -300, 300, 30)],
		['a field right of the viewport', field(100, 1000, 300, 30)],
	])('has no place for %s', (_name, element) => {
		expect(placement(element, 200)).toBeNull()
	})

	it('has no place in a viewport narrower than the list', () => {
		view.width = INLINE_MIN_WIDTH_PX + 15
		try {
			expect(placement(field(100, 0, 100, 30), 200)).toBeNull()
		} finally {
			view.width = 1000
		}
	})

	it('sits below the field, at least as wide as the minimum, within the edges', () => {
		expect(placement(field(100, 50, 100, 30), 200)).toEqual({ top: 134, left: 50, width: INLINE_MIN_WIDTH_PX, height: 200 })
		expect(placement(field(100, 50, 2000, 30), 200)).toEqual({ top: 134, left: 8, width: 984, height: 200 })
		expect(placement(field(100, 900, 300, 30), 200)).toEqual({ top: 134, left: 692, width: 300, height: 200 })
	})

	it('sits above the field when only that side holds the height, and nowhere when neither does', () => {
		expect(placement(field(700, 50, 300, 30), 200)).toEqual({ top: 496, left: 50, width: 300, height: 200 })
		expect(placement(field(300, 50, 300, 300), 400)).toBeNull()
		expect(placement(field(300, 50, 300, 300), 296)).toEqual({ top: 0, left: 50, width: 300, height: 296 })
	})

	it('with fit, shrinks to the larger free side, down to one row', () => {
		expect(placement(field(300, 50, 300, 300), 400, true)).toEqual({ top: 0, left: 50, width: 300, height: 296 })
		expect(placement(field(200, 50, 300, 300), 400, true)).toEqual({ top: 504, left: 50, width: 300, height: 296 })
		expect(placement(field(100, 50, 300, 30), 200, true)).toEqual({ top: 134, left: 50, width: 300, height: 200 })
		expect(placement(field(MIN, 50, 300, 800 - 2 * MIN), 400, true)).toBeNull()
		expect(placement(field(MIN + 4, 50, 300, 800 - 2 * MIN - 8), 400, true)).toEqual({ top: 800 - MIN, left: 50, width: 300, height: MIN })
	})

	it('without fit, refuses a fixed height under one row', () => {
		expect(placement(field(100, 50, 300, 30), MIN - 1)).toBeNull()
	})
})
