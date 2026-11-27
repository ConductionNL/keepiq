/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The production bundle stays lean (hydra ADR-124, speed budget).
 *
 * Two build settings decide most of what every keepiq page downloads:
 *
 *   1. css-loader must not write source maps. It follows `devtool`, and with the
 *      production `source-map` it embedded every stylesheet a second time inside
 *      the JavaScript (keepiq-shared-nc-vue.js was 15.2 MB with them, 5.1 MB
 *      without).
 *   2. The shared cache groups take modules from initial chunks only, so a chunk
 *      loaded on demand (an editor, a chart, a dialog) stays a lazy file instead
 *      of being pulled into the chunk every page loads. The service worker never
 *      joins a shared chunk at all.
 */

import { createRequire } from 'node:module'
import { describe, expect, it } from 'vitest'

const require = createRequire(import.meta.url)
const config = require('../../webpack.config.js')

/**
 * The css-loader entries of the rule that matches a file name.
 *
 * @param {string} file A file name the rule should match.
 * @return {Array<string|object>} The css-loader entries of that rule.
 */
function cssLoadersFor(file) {
	const rule = config.module.rules.find((r) => r.test instanceof RegExp && r.test.test(file))
	expect(rule, `a rule for ${file}`).toBeTruthy()
	return rule.use.filter((u) => u === 'css-loader' || u?.loader === 'css-loader')
}

describe('lean production bundles', () => {
	it.each(['library.css', 'component.scss'])('builds %s without css-loader source maps', (file) => {
		const loaders = cssLoadersFor(file)
		expect(loaders).toHaveLength(1)
		expect(loaders[0]).toEqual({ loader: 'css-loader', options: { sourceMap: false } })
	})

	it('lets the shared groups take initial chunks only, never the service worker', () => {
		const filter = config.optimization.splitChunks.chunks
		expect(typeof filter).toBe('function')
		const chunk = (name, initial) => ({ name, canBeInitial: () => initial })

		expect(filter(chunk('main', true))).toBe(true)
		expect(filter(chunk('adminSettings', true))).toBe(true)
		expect(filter(chunk('toastui-editor', false))).toBe(false)
		expect(filter(chunk('serviceWorker', true))).toBe(false)
	})

	it('keeps the two shared groups the PHP templates load by name', () => {
		const groups = config.optimization.splitChunks.cacheGroups
		expect(groups.ncVue.filename).toBe('keepiq-shared-nc-vue.js')
		expect(groups.vendor.filename).toBe('keepiq-shared-vendor.js')
	})
})
