/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The dashboard tiles and the page chrome take their colours from the theme
 * (#755). Fixed hex colours kept the tiles blue and grey under an nldesign
 * organisation theme.
 *
 * @spec openspec/specs/mobile-pwa/spec.md#requirement-maskable-and-themed-app-icons
 */

import * as fs from 'fs'
import * as path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)
const tiles = manifest.pages
	.flatMap((page) => page.config?.widgets ?? [])
	.filter((widget) => widget.type === 'tile')

describe('theme colours', () => {
	it('finds the dashboard tiles', () => {
		expect(tiles.length).toBeGreaterThan(0)
	})

	it('colours every tile with a theme variable, never a fixed colour', () => {
		const fixed = tiles.filter((tile) =>
			[tile.backgroundColor, tile.textColor].some(
				(c) => typeof c === 'string' && !c.startsWith('var(--'),
			),
		)
		expect(fixed.map((tile) => tile.id)).toEqual([])
	})

	it('takes the theme-color meta from the instance theme', () => {
		const template = fs.readFileSync(
			path.join(ROOT, 'templates', 'index.php'),
			'utf8',
		)
		expect(template).not.toMatch(/#[0-9a-fA-F]{6}/)
		expect(template).toContain('getColorPrimary()')
	})
})
